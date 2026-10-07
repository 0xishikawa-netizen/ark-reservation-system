<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\Reservation;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * 予約 1 件に対する利用権のライフサイクル（RESERVE / RELEASE / CONSUME / no_show）。冪等。
 * `ReservationService` の既存 DB transaction の中から呼ばれる（Stripe HTTP を含まない）。
 * Phase 4 の TicketReservationService と同型。
 */
final class MembershipReservationService
{
    public function __construct(
        private readonly MembershipLedgerService $ledger,
        private readonly MembershipPolicyResolver $policy,
        private readonly AuditLogger $auditLogger,
        private readonly BusinessTime $businessTime,
    ) {}

    /**
     * 予約作成時。当期の利用権を 1 消費し、期と no_show ポリシーを snapshot する。
     */
    public function reserve(Reservation $reservation, ?Authenticatable $actor = null): MembershipReservationUsage
    {
        $existing = $this->usageFor($reservation);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($reservation, $actor): MembershipReservationUsage {
            $membership = Membership::query()
                ->where('customer_id', $reservation->customer_id)
                ->bookable()
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw new InsufficientMembershipBalanceException(__('messages.membership.none_available'));
            }

            // canceling（当期末で終了予定）は current_period_end までしか使えない。
            // subscription.deleted webhook の遅延・欠落で canceling のまま期末を越えても予約させない。
            if ($membership->status === MembershipStatus::Canceling
                && $membership->current_period_end !== null) {
                $periodEnd = $membership->current_period_end->toDateString();
                $today = $this->businessTime->businessDate()->toDateString();
                $reservationDate = $this->businessTime
                    ->businessDate($reservation->starts_at)
                    ->toDateString();

                if ($today > $periodEnd || $reservationDate > $periodEnd) {
                    throw new InsufficientMembershipBalanceException(__('messages.membership.expired'));
                }
            }

            $period = $this->ledger->currentPeriod($membership);

            $this->ledger->append(
                membership: $membership,
                type: MembershipUsageType::Reserve,
                delta: -1,
                dedupeKey: "reserve:{$reservation->id}",
                periodStart: $period,
                reservationId: (int) $reservation->id,
            );

            $usage = MembershipReservationUsage::query()->create([
                'reservation_id' => $reservation->id,
                'membership_id' => $membership->id,
                'period_start' => $period,
                'no_show_policy' => $this->policy->noShowPolicy(),
                'status' => MembershipReservationUsageStatus::Reserved,
                'reserved_at' => now(),
            ]);

            $this->auditLogger->log(
                'membership.reserved',
                $usage,
                "利用権 RESERVE 予約#{$reservation->id} membership#{$membership->id}（期 {$period}）",
                $actor,
            );

            return $usage;
        });
    }

    /**
     * キャンセル / no_show(restore) 共通。RELEASE +1。
     * 予約時の期がすでに終了済みなら、同一 transaction で ADJUST -1 を旧期へ追加し
     * 「期限切れ権利の復活」を防ぐ（現在期の残数は増やさない）。
     */
    public function release(Reservation $reservation, ?Authenticatable $actor = null): void
    {
        $usage = $this->usageFor($reservation);

        if ($usage === null || $usage->status !== MembershipReservationUsageStatus::Reserved) {
            return;
        }

        DB::transaction(function () use ($reservation, $usage, $actor): void {
            $membership = Membership::query()->whereKey($usage->membership_id)->lockForUpdate()->firstOrFail();
            $reservedPeriod = $usage->period_start->toDateString();

            $this->ledger->append(
                membership: $membership,
                type: MembershipUsageType::Release,
                delta: 1,
                dedupeKey: "release:{$reservation->id}",
                periodStart: $reservedPeriod,
                reservationId: (int) $reservation->id,
            );

            if ($this->reservedPeriodHasEnded($membership, $reservedPeriod)) {
                $this->ledger->append(
                    membership: $membership,
                    type: MembershipUsageType::Adjust,
                    delta: -1,
                    dedupeKey: "mbr-expire:{$reservation->id}",
                    periodStart: $reservedPeriod,
                    reason: '期またぎ解放の期限切れ相殺',
                    reservationId: (int) $reservation->id,
                );
            }

            $usage->update([
                'status' => MembershipReservationUsageStatus::Released,
                'released_at' => now(),
            ]);

            $this->auditLogger->log(
                'membership.released',
                $usage,
                "利用権 RELEASE 予約#{$reservation->id} membership#{$membership->id}",
                $actor,
            );
        });
    }

    /**
     * completed / no_show(consume) 共通。RELEASE +1 → CONSUME -1（available 増減ゼロ・二重減算なし）。
     */
    public function consume(Reservation $reservation, ?Authenticatable $actor = null): void
    {
        $usage = $this->usageFor($reservation);

        if ($usage === null || in_array($usage->status, [
            MembershipReservationUsageStatus::Released,
            MembershipReservationUsageStatus::Consumed,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($reservation, $usage, $actor): void {
            $membership = Membership::query()->whereKey($usage->membership_id)->lockForUpdate()->firstOrFail();
            $reservedPeriod = $usage->period_start->toDateString();

            $this->ledger->append(
                membership: $membership,
                type: MembershipUsageType::Release,
                delta: 1,
                dedupeKey: "release:{$reservation->id}",
                periodStart: $reservedPeriod,
                reservationId: (int) $reservation->id,
            );
            $this->ledger->append(
                membership: $membership,
                type: MembershipUsageType::Consume,
                delta: -1,
                dedupeKey: "consume:{$reservation->id}",
                periodStart: $reservedPeriod,
                reservationId: (int) $reservation->id,
            );

            $usage->update([
                'status' => MembershipReservationUsageStatus::Consumed,
                'consumed_at' => now(),
                'released_at' => $usage->released_at ?? now(),
            ]);

            $this->auditLogger->log(
                'membership.consumed',
                $usage,
                "利用権 CONSUME 予約#{$reservation->id} membership#{$membership->id}",
                $actor,
            );
        });
    }

    /**
     * no_show。RESERVE 時に snapshot した usage.no_show_policy で分岐（設定変更を既存予約へ遡及しない）。
     */
    public function handleNoShow(Reservation $reservation, ?Authenticatable $actor = null): void
    {
        $usage = $this->usageFor($reservation);

        if ($usage === null) {
            return;
        }

        match ($usage->no_show_policy) {
            MembershipNoShowPolicy::Restore => $this->release($reservation, $actor),
            MembershipNoShowPolicy::Consume => $this->consume($reservation, $actor),
        };
    }

    private function usageFor(Reservation $reservation): ?MembershipReservationUsage
    {
        return MembershipReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->first();
    }

    private function reservedPeriodHasEnded(Membership $membership, string $reservedPeriod): bool
    {
        $current = $membership->current_period_start;

        if ($current === null) {
            return false;
        }

        return $current->toDateString() > $reservedPeriod;
    }
}
