<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Enums\Membership\MembershipUsageType;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Models\Membership;
use App\Models\MembershipUsageTransaction;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 利用権台帳（`membership_usage_transactions`）への追記の唯一の入口。
 *
 * - 追記専用。UPDATE/DELETE しない（Model 側でも拒否）。
 * - 残数は `available(period) = SUM(delta) WHERE period_start = period`。
 *   held(period) = count(RESERVE) - count(RELEASE)。`SUM(delta) - held` は使わない。
 * - `memberships.period_available` は「当期 available」の derived cache。台帳追記と同一 transaction で更新。
 * - すべての追記は `dedupe_key` UNIQUE で冪等（retry で二重追記されない）。
 */
final class MembershipLedgerService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * 台帳へ 1 行追記する。短い DB transaction・membership を FOR UPDATE・cache を同一 transaction 更新。
     */
    public function append(
        Membership $membership,
        MembershipUsageType $type,
        int $delta,
        string $dedupeKey,
        CarbonInterface|string $periodStart,
        ?int $reservationId = null,
        ?int $staffId = null,
        ?string $reason = null,
    ): MembershipUsageTransaction {
        $period = $this->normalizePeriod($periodStart);

        $existing = MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $membership,
                $type,
                $delta,
                $dedupeKey,
                $period,
                $reservationId,
                $staffId,
                $reason,
            ): MembershipUsageTransaction {
                $locked = Membership::query()
                    ->whereKey($membership->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->validateDelta($type, $delta);

                $currentAvailable = $this->availableForLocked($locked, $period);
                $newAvailable = $currentAvailable + $delta;

                if ($newAvailable < 0) {
                    if ($type === MembershipUsageType::Reserve) {
                        throw new InsufficientMembershipBalanceException;
                    }

                    throw ValidationException::withMessages([
                        'membership' => '当期の利用可能回数を超える操作です。',
                    ]);
                }

                $transaction = MembershipUsageTransaction::query()->create([
                    'membership_id' => $locked->getKey(),
                    'period_start' => $period,
                    'type' => $type,
                    'delta' => $delta,
                    'reservation_id' => $reservationId,
                    'staff_id' => $staffId,
                    'reason' => $reason,
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                ]);

                // period_available は「当期」の cache。旧期への追記では触らない。
                if ($this->isCurrentPeriod($locked, $period)) {
                    $locked->forceFill(['period_available' => $newAvailable])->save();
                }

                return $transaction;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * 当期（または指定 period）の利用可能回数 = SUM(delta)。
     */
    public function available(Membership $membership, CarbonInterface|string|null $periodStart = null): int
    {
        $period = $this->normalizePeriod($periodStart ?? $this->currentPeriod($membership));

        return (int) $membership->usageTransactions()
            ->where('period_start', $period)
            ->sum('delta');
    }

    /**
     * 当期（または指定 period）の未解消 RESERVE 本数 = count(RESERVE) - count(RELEASE)。
     */
    public function held(Membership $membership, CarbonInterface|string|null $periodStart = null): int
    {
        $period = $this->normalizePeriod($periodStart ?? $this->currentPeriod($membership));

        $reserves = $membership->usageTransactions()
            ->where('period_start', $period)
            ->where('type', MembershipUsageType::Reserve->value)
            ->count();
        $releases = $membership->usageTransactions()
            ->where('period_start', $period)
            ->where('type', MembershipUsageType::Release->value)
            ->count();

        return $reserves - $releases;
    }

    public function total(Membership $membership, CarbonInterface|string|null $periodStart = null): int
    {
        return $this->available($membership, $periodStart) + $this->held($membership, $periodStart);
    }

    /** @return array{available: int, held: int, total: int} */
    public function summary(Membership $membership, CarbonInterface|string|null $periodStart = null): array
    {
        $available = $this->available($membership, $periodStart);
        $held = $this->held($membership, $periodStart);

        return [
            'available' => $available,
            'held' => $held,
            'total' => $available + $held,
        ];
    }

    /**
     * 期首付与。1 期 1 回（dedupe `grant:{membership_id}:{period_start}`）。
     * webhook duplicate / retry / 順序逆転 / scheduler との同時実行で二重 GRANT されない。
     */
    public function grant(
        Membership $membership,
        CarbonInterface|string $periodStart,
        int $count,
        ?string $reason = null,
        ?Authenticatable $actor = null,
    ): MembershipUsageTransaction {
        if ($count <= 0) {
            throw new InvalidArgumentException('GRANT は正の回数である必要があります。');
        }

        $period = $this->normalizePeriod($periodStart);
        $dedupeKey = "grant:{$membership->getKey()}:{$period}";

        $existing = MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->first();

        $transaction = $this->append(
            membership: $membership,
            type: MembershipUsageType::Grant,
            delta: $count,
            dedupeKey: $dedupeKey,
            periodStart: $period,
            reason: $reason,
            staffId: $this->actorId($actor),
        );

        if ($existing === null) {
            $this->auditLogger->log(
                'membership.granted',
                $membership,
                "利用権付与 membership#{$membership->id} +{$count}（期 {$period}）",
                $actor,
            );
        }

        return $transaction;
    }

    /**
     * 管理者手動調整。当期に ±N。reason + 監査必須（reauth は route middleware）。
     */
    public function adjust(
        Membership $membership,
        int $delta,
        string $operationKey,
        string $reason,
        ?Authenticatable $actor = null,
    ): MembershipUsageTransaction {
        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => '調整数は 0 以外で指定してください。']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => '理由は必須です。']);
        }

        $period = $this->currentPeriod($membership);
        $dedupeKey = "adjust:{$operationKey}";

        $existing = MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->first();

        $transaction = $this->append(
            membership: $membership,
            type: MembershipUsageType::Adjust,
            delta: $delta,
            dedupeKey: $dedupeKey,
            periodStart: $period,
            reason: $reason,
            staffId: $this->actorId($actor),
        );

        if ($existing === null) {
            $this->auditLogger->log(
                'membership.adjusted',
                $membership,
                "利用権調整 membership#{$membership->id} {$this->signed($delta)}（理由: {$reason}）",
                $actor,
            );
        }

        return $transaction;
    }

    /**
     * reconcile 用。当期 available を台帳から再計算して cache に反映する（派生状態のみ）。
     */
    public function recalculatePeriodAvailable(Membership $membership): int
    {
        return DB::transaction(function () use ($membership): int {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $available = $this->availableForLocked($locked, $this->currentPeriod($locked));
            $locked->forceFill(['period_available' => $available])->save();

            return $available;
        });
    }

    public function currentPeriod(Membership $membership): string
    {
        $start = $membership->current_period_start;

        return $start instanceof CarbonInterface
            ? $start->toDateString()
            : $this->normalizePeriod($start ?? Carbon::now()->startOfMonth());
    }

    private function availableForLocked(Membership $membership, string $period): int
    {
        return (int) MembershipUsageTransaction::query()
            ->where('membership_id', $membership->getKey())
            ->where('period_start', $period)
            ->sum('delta');
    }

    private function isCurrentPeriod(Membership $membership, string $period): bool
    {
        $current = $membership->current_period_start;

        if ($current === null) {
            return false;
        }

        $currentString = $current instanceof CarbonInterface
            ? $current->toDateString()
            : $this->normalizePeriod($current);

        return $currentString === $period;
    }

    private function normalizePeriod(CarbonInterface|string $period): string
    {
        if ($period instanceof CarbonInterface) {
            return $period->toDateString();
        }

        return Carbon::parse($period)->toDateString();
    }

    private function validateDelta(MembershipUsageType $type, int $delta): void
    {
        $valid = match ($type) {
            MembershipUsageType::Reserve, MembershipUsageType::Consume => $delta === -1,
            MembershipUsageType::Release => $delta === 1,
            MembershipUsageType::Grant => $delta > 0,
            MembershipUsageType::Adjust => $delta !== 0,
        };

        if (! $valid) {
            throw new InvalidArgumentException("{$type->value} の delta が不正です。");
        }
    }

    private function actorId(?Authenticatable $actor): ?int
    {
        return $actor === null ? null : (int) $actor->getAuthIdentifier();
    }

    private function signed(int $delta): string
    {
        return $delta > 0 ? "+{$delta}" : (string) $delta;
    }
}
