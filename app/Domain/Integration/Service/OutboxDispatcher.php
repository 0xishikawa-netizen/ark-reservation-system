<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Dto\OutboundReservationCommand;
use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ErrorCategory;
use App\Domain\Integration\Enum\OutboxStatus;
use App\Domain\Integration\Enum\SyncDirection;
use App\Domain\Integration\Enum\SyncEventStatus;
use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\Exception\IntegrationException;
use App\Domain\Integration\Exception\ProviderConfigurationException;
use App\Domain\Integration\Exception\ProviderPermanentException;
use App\Domain\Integration\ProviderResolver;
use App\Domain\Integration\Support\ReservationFingerprint;
use App\Models\Reservation;
use App\Models\ReservationProviderSyncState;
use App\Models\ReservationSyncOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Outbox 行を 1 件ずつ claim して外部へ送る。External HTTP は DB transaction の外。
 *
 * - claim は SKIP LOCKED（並列 worker のブロック回避・F-20）+ 同一予約の先行未完了行を待つ（F-03）。
 * - crash で processing に固定された行は lease 期限で再取得（F-04）。
 * - Provider は行の provider key で解決。有効でなければ terminal 化せず pending 保持（F-12）。
 * - 成功時は mapping.fingerprint（＝ ARK の共通 baseline）を更新（F-05）。
 */
final class OutboxDispatcher
{
    private const LEASE_SECONDS = 900;

    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly ReservationMappingRepository $mappings,
        private readonly SyncEventRecorder $events,
    ) {}

    public function claimNext(string $lockedBy): ?ReservationSyncOutbox
    {
        return DB::transaction(function () use ($lockedBy): ?ReservationSyncOutbox {
            $leaseCutoff = now()->subSeconds(self::LEASE_SECONDS);

            $query = ReservationSyncOutbox::query()
                ->where('available_at', '<=', now())
                ->where(function ($q) use ($leaseCutoff): void {
                    $q->where('status', OutboxStatus::Pending->value)
                        ->orWhere(function ($q2) use ($leaseCutoff): void {
                            $q2->where('status', OutboxStatus::Processing->value)
                                ->where('locked_at', '<', $leaseCutoff);
                        });
                })
                // 同一 (provider, reservation) の先行する未完了行があれば待つ（順序直列化）。
                ->whereNotExists(function ($q): void {
                    $q->selectRaw('1')
                        ->from('reservation_sync_outbox as o2')
                        ->whereColumn('o2.provider', 'reservation_sync_outbox.provider')
                        ->whereColumn('o2.reservation_id', 'reservation_sync_outbox.reservation_id')
                        ->whereColumn('o2.id', '<', 'reservation_sync_outbox.id')
                        ->whereIn('o2.status', [
                            OutboxStatus::Pending->value,
                            OutboxStatus::Processing->value,
                            OutboxStatus::Failed->value,
                            OutboxStatus::NeedsAttention->value,
                        ]);
                })
                ->orderBy('available_at')
                ->orderBy('id');

            $row = $this->lockSkipLocked($query);

            if ($row === null) {
                return null;
            }

            $row->forceFill([
                'status' => OutboxStatus::Processing,
                'attempts' => $row->attempts + 1,
                'locked_at' => now(),
                'locked_by' => substr($lockedBy, 0, 64),
            ])->save();

            return $row;
        });
    }

    public function process(ReservationSyncOutbox $row): OutboxStatus
    {
        $startedAt = CarbonImmutable::now();

        // 行の provider を解決。有効でない / 設定不備 → terminal 化せず pending へ戻す（F-12）。
        try {
            $provider = $this->resolver->resolve((string) $row->provider);
        } catch (ProviderConfigurationException) {
            return $this->park($row, 'provider_unresolved', $startedAt);
        }

        if ($this->resolver->activeKey() !== $row->provider) {
            return $this->park($row, 'provider_inactive', $startedAt);
        }

        $reservation = $row->reservation;

        if ($reservation === null) {
            return $this->finish($row, OutboxStatus::Skipped, ErrorCategory::NonRetryable, 'reservation_missing', $startedAt);
        }

        $mapping = $this->mappings->findByReservation($row->provider, (int) $row->reservation_id);
        $requested = $this->op($row);

        // mapping が既にあれば create でも update に切替。command は effective operation で組む（F-15）。
        $effective = $requested === SyncOperation::Create && $mapping !== null
            ? SyncOperation::Update
            : $requested;

        $command = $this->command($row, $reservation, $effective);

        try {
            match ($effective) {
                SyncOperation::Create => $this->afterCreate($row, $reservation, $provider->createReservation($command)),
                SyncOperation::Update => $this->afterMutate(
                    $row, $reservation,
                    (string) ($mapping?->external_reservation_id ?? throw new ProviderPermanentException('update に mapping がありません。')),
                    fn (string $id) => $provider->updateReservation($id, $command),
                ),
                SyncOperation::Cancel => $mapping === null
                    ? null // 外部にまだ無い予約の cancel は no-op（先行 create 直列化により通常起きない）
                    : $this->afterMutate($row, $reservation, (string) $mapping->external_reservation_id, function (string $id) use ($provider, $command): void {
                        $provider->cancelReservation($id, $command);
                    }),
                default => throw new ProviderPermanentException("未対応 operation: {$effective->value}"),
            };
        } catch (IntegrationException $exception) {
            return $this->onFailure($row, $exception->category(), $exception->safeCode(), $startedAt);
        } catch (Throwable $exception) {
            return $this->onFailure($row, ErrorCategory::Ambiguous, class_basename($exception), $startedAt);
        }

        $this->touchOutboundState($row->provider);

        return $this->finish($row, OutboxStatus::Succeeded, null, null, $startedAt);
    }

    /** 指定 ID の 1 行だけを条件付きで claim（管理画面の手動 retry 用・F-11）。 */
    public function claimById(int $id, string $lockedBy): ?ReservationSyncOutbox
    {
        return DB::transaction(function () use ($id, $lockedBy): ?ReservationSyncOutbox {
            $row = ReservationSyncOutbox::query()->whereKey($id)->lockForUpdate()->first();

            if ($row === null || $row->status !== OutboxStatus::Pending) {
                return null;
            }

            $row->forceFill([
                'status' => OutboxStatus::Processing,
                'attempts' => $row->attempts + 1,
                'locked_at' => now(),
                'locked_by' => substr($lockedBy, 0, 64),
            ])->save();

            return $row;
        });
    }

    private function command(ReservationSyncOutbox $row, Reservation $reservation, SyncOperation $effective): OutboundReservationCommand
    {
        return new OutboundReservationCommand(
            operation: $effective,
            reservationId: (int) $row->reservation_id,
            idempotencyKey: (string) $row->idempotency_key,
            correlationId: (string) $row->correlation_id,
            startsAt: CarbonImmutable::parse($reservation->starts_at),
            endsAt: CarbonImmutable::parse($reservation->ends_at),
            arkStatus: $reservation->status?->value ?? 'unknown',
            serviceRef: $reservation->service_id === null ? null : (string) $reservation->service_id,
            staffRef: $reservation->staff_id === null ? null : (string) $reservation->staff_id,
            // 自由記述はここに載せない（PII 混入防止・F-14）。理由の詳細は audit_logs で追う。
            cancelReason: $effective === SyncOperation::Cancel ? 'canceled' : null,
        );
    }

    private function afterCreate(ReservationSyncOutbox $row, Reservation $reservation, ProviderRef $ref): void
    {
        $mapping = $this->mappings->firstOrCreateForExternal($row->provider, $ref->externalReservationId, (int) $row->reservation_id);
        $mapping->forceFill([
            'fingerprint' => ReservationFingerprint::forReservation($reservation->fresh() ?? $reservation),
            'last_synced_at' => now(),
            'last_seen_at' => now(),
            'external_updated_at' => $ref->externalUpdatedAt,
            'sync_status' => 'in_sync',
        ])->save();
    }

    /** @param  callable(string): void  $call */
    private function afterMutate(ReservationSyncOutbox $row, Reservation $reservation, string $externalId, callable $call): void
    {
        $call($externalId);

        $mapping = $this->mappings->findByReservation($row->provider, (int) $row->reservation_id);
        $mapping?->forceFill([
            'fingerprint' => ReservationFingerprint::forReservation($reservation->fresh() ?? $reservation),
            'last_synced_at' => now(),
            'last_seen_at' => now(),
            'sync_status' => 'in_sync',
        ])->save();
    }

    private function park(ReservationSyncOutbox $row, string $code, CarbonImmutable $startedAt): OutboxStatus
    {
        $row->forceFill([
            'status' => OutboxStatus::Pending,
            'available_at' => now()->addMinutes(5),
            'locked_at' => null,
            'locked_by' => null,
            'last_error_category' => ErrorCategory::ConfigError->value,
            'last_error_code' => $code,
        ])->save();

        $this->events->record(
            $row->provider, SyncDirection::Outbound, $this->op($row), SyncEventStatus::Skipped,
            (int) $row->reservation_id, null, (string) $row->correlation_id, (string) $row->idempotency_key,
            (int) $row->attempts, ErrorCategory::ConfigError, $code, $startedAt,
        );

        return OutboxStatus::Pending;
    }

    private function onFailure(
        ReservationSyncOutbox $row,
        ErrorCategory $category,
        string $safeCode,
        CarbonImmutable $startedAt,
    ): OutboxStatus {
        $maxAttempts = (int) config('reservation_integration.outbox.max_attempts', 6);
        $retryable = in_array($category, [ErrorCategory::Retryable, ErrorCategory::Ambiguous], true);

        if ($retryable && $row->attempts < $maxAttempts) {
            $row->forceFill([
                'status' => OutboxStatus::Pending,
                'available_at' => now()->addSeconds($this->backoffSeconds($row->attempts)),
                'locked_at' => null,
                'locked_by' => null,
                'last_error_category' => $category->value,
                'last_error_code' => substr($safeCode, 0, 80),
            ])->save();

            $this->events->record(
                $row->provider, SyncDirection::Outbound, $this->op($row), SyncEventStatus::Failed,
                (int) $row->reservation_id, null, (string) $row->correlation_id, (string) $row->idempotency_key,
                (int) $row->attempts, $category, $safeCode, $startedAt,
            );

            return OutboxStatus::Pending;
        }

        return $this->finish($row, OutboxStatus::NeedsAttention, $category, $safeCode, $startedAt);
    }

    private function finish(
        ReservationSyncOutbox $row,
        OutboxStatus $status,
        ?ErrorCategory $category,
        ?string $safeCode,
        CarbonImmutable $startedAt,
    ): OutboxStatus {
        $row->forceFill([
            'status' => $status,
            'locked_at' => null,
            'locked_by' => null,
            'last_error_category' => $category?->value,
            'last_error_code' => $safeCode === null ? null : substr($safeCode, 0, 80),
            'completed_at' => in_array($status, [OutboxStatus::Succeeded, OutboxStatus::Skipped, OutboxStatus::NeedsAttention], true) ? now() : null,
        ])->save();

        $eventStatus = match ($status) {
            OutboxStatus::Succeeded => SyncEventStatus::Succeeded,
            OutboxStatus::Skipped => SyncEventStatus::Skipped,
            default => SyncEventStatus::Failed,
        };

        $this->events->record(
            $row->provider, SyncDirection::Outbound, $this->op($row), $eventStatus,
            (int) $row->reservation_id, null, (string) $row->correlation_id, (string) $row->idempotency_key,
            (int) $row->attempts, $category, $safeCode, $startedAt,
        );

        return $status;
    }

    private function op(ReservationSyncOutbox $row): SyncOperation
    {
        return $row->operation instanceof SyncOperation ? $row->operation : SyncOperation::from((string) $row->operation);
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = (int) config('reservation_integration.outbox.backoff_base_seconds', 30);
        $cap = (int) config('reservation_integration.outbox.backoff_cap_seconds', 3600);

        return (int) min($cap, $base * (2 ** max(0, $attempts - 1)));
    }

    private function touchOutboundState(string $provider): void
    {
        ReservationProviderSyncState::query()->updateOrCreate(
            ['provider' => $provider],
            ['last_outbound_at' => now()],
        );
    }

    /**
     * @param  Builder<ReservationSyncOutbox>  $query
     */
    private function lockSkipLocked($query): ?ReservationSyncOutbox
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return $query->lock('for update skip locked')->first();
        }

        return $query->lockForUpdate()->first();
    }
}
