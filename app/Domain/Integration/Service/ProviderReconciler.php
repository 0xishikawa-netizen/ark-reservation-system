<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Dto\FetchWindow;
use App\Domain\Integration\Dto\ProviderRef;
use App\Domain\Integration\Enum\ConflictType;
use App\Domain\Integration\Enum\ProviderCapability;
use App\Domain\Integration\Enum\SyncDirection;
use App\Domain\Integration\Enum\SyncEventStatus;
use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\ProviderResolver;
use App\Domain\Integration\Support\ReservationFingerprint;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationProviderMapping;
use App\Models\ReservationProviderSyncState;
use Carbon\CarbonImmutable;

/**
 * ARK と Provider の現在状態を突合する（read-mostly）。
 * Safe self-heal（last_seen / sync_status の派生更新）と Needs attention（conflict 行作成）を分離。
 * 大量予約を勝手に上書きしない。
 *
 * @phpstan-type ReconcileReport array{
 *   provider: string, checked: int, in_sync: int, drift: int, stale: int,
 *   ark_only: int, external_only: int, conflicts_opened: int
 * }
 */
final class ProviderReconciler
{
    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly ConflictRecorder $conflicts,
        private readonly SyncEventRecorder $events,
    ) {}

    /**
     * @return ReconcileReport
     */
    public function reconcile(?string $providerKey = null, bool $dryRun = false): array
    {
        $providerKey ??= $this->resolver->activeKey();

        if ($providerKey === null) {
            return $this->emptyReport('none');
        }

        $provider = $this->resolver->resolve($providerKey);
        $report = $this->emptyReport($providerKey);

        if (! $provider->capabilities()->has(ProviderCapability::ReadReservations)) {
            return $report;
        }

        $windowDays = (int) config('reservation_integration.reconcile.window_days', 14);
        $now = CarbonImmutable::now();
        $window = new FetchWindow($now->subDays($windowDays), $now->addDays($windowDays));

        /** @var array<string, ExternalReservationData> $externalById */
        $externalById = [];
        $count = 0;
        foreach ($provider->fetchReservations($window) as $data) {
            $externalById[$data->externalReservationId] = $data;
            if (++$count > 5000) {
                break; // 無制限に配列化しない（F-17）。上限超はページング前提の Phase 10 契約。
            }
        }
        $seenExternalIds = [];

        // 1) mapping ベースで突合
        ReservationProviderMapping::query()
            ->where('provider', $providerKey)
            ->with('reservation')
            ->chunkById(200, function ($mappings) use (&$report, $externalById, &$seenExternalIds, $dryRun, $providerKey): void {
                foreach ($mappings as $mapping) {
                    $report['checked']++;
                    $seenExternalIds[$mapping->external_reservation_id] = true;
                    $reservation = $mapping->reservation;
                    $external = $externalById[$mapping->external_reservation_id] ?? null;

                    if ($reservation === null) {
                        $report['conflicts_opened'] += $this->maybeConflict($dryRun, $providerKey, null, ConflictType::MissingMapping, null, null, $mapping->external_reservation_id, hash('sha256', $providerKey.'|'.$mapping->external_reservation_id));

                        continue;
                    }

                    if ($external === null) {
                        // Mapped but missing on external side。
                        $report['stale']++;
                        if (! $dryRun) {
                            $mapping->forceFill(['sync_status' => 'stale'])->save();
                        }

                        continue;
                    }

                    $externalFp = $external->fingerprint($reservation->status->value);
                    $arkFp = ReservationFingerprint::forReservation($reservation);

                    if ($externalFp === $mapping->fingerprint && $arkFp === $mapping->fingerprint) {
                        $report['in_sync']++;
                        if (! $dryRun) {
                            $mapping->forceFill(['last_seen_at' => now(), 'sync_status' => 'in_sync'])->save();
                        }

                        continue;
                    }

                    // どこかがずれている。両側が動いていれば conflict、片側だけなら drift（次の poll で吸収）。
                    $arkChanged = $mapping->fingerprint !== null && $arkFp !== $mapping->fingerprint;
                    $externalChanged = $mapping->fingerprint !== null && $externalFp !== $mapping->fingerprint;

                    if ($arkChanged && $externalChanged) {
                        $type = $external->isCanceled || $reservation->status === ReservationStatus::Canceled
                            ? ConflictType::CancelVsUpdate
                            : ConflictType::TimeChangedBoth;
                        $report['conflicts_opened'] += $this->maybeConflict($dryRun, $providerKey, (int) $reservation->id, $type, $arkFp, $externalFp, $mapping->external_reservation_id, $external->refHash());
                        if (! $dryRun) {
                            $mapping->forceFill(['sync_status' => 'conflict'])->save();
                        }
                    } else {
                        $report['drift']++;
                        if (! $dryRun) {
                            $mapping->forceFill(['sync_status' => 'drift', 'last_seen_at' => now()])->save();
                        }
                    }
                }
            });

        // 2) ARK 側の外部由来予約で mapping が無いもの（orphan）
        Reservation::query()
            ->where('source', ReservationSource::External->value)
            ->whereDoesntHave('providerMappings', fn ($q) => $q->where('provider', $providerKey))
            ->whereBetween('starts_at', [$window->from, $window->to])
            ->chunkById(200, function ($rows) use (&$report, $dryRun, $providerKey): void {
                foreach ($rows as $reservation) {
                    $report['ark_only']++;
                    $report['conflicts_opened'] += $this->maybeConflict($dryRun, $providerKey, (int) $reservation->id, ConflictType::MissingMapping, ReservationFingerprint::forReservation($reservation), null, null, null);
                }
            });

        // 3) 外部にあるが ARK に mapping が無い（応答喪失の outbound orphan 等・F-09）。
        foreach ($externalById as $externalId => $data) {
            if (isset($seenExternalIds[$externalId])) {
                continue;
            }

            $report['external_only']++;

            // 「作ったが mapping を失った」可能性: 同一 customer/service/starts_at の ARK 予約が mapping 無しで存在。
            $customerId = ctype_digit((string) $data->externalCustomerId) ? (int) $data->externalCustomerId : null;
            $probableOrphan = $customerId !== null && ctype_digit((string) $data->serviceRef)
                && Reservation::query()
                    ->where('customer_id', $customerId)
                    ->where('service_id', (int) $data->serviceRef)
                    ->where('starts_at', $data->startsAt)
                    ->whereDoesntHave('providerMappings', fn ($q) => $q->where('provider', $providerKey))
                    ->exists();

            if ($probableOrphan) {
                $report['conflicts_opened'] += $this->maybeConflict(
                    $dryRun, $providerKey, null, ConflictType::DuplicateExternal,
                    null, $data->fingerprint('unknown'), $data->externalReservationId, $data->refHash(),
                );
            }
        }

        if (! $dryRun) {
            ReservationProviderSyncState::query()->updateOrCreate(
                ['provider' => $providerKey],
                ['last_reconcile_at' => $now],
            );
            $this->events->record($providerKey, SyncDirection::Inbound, SyncOperation::Reconcile, SyncEventStatus::Succeeded);
        }

        return $report;
    }

    private function maybeConflict(
        bool $dryRun,
        string $provider,
        ?int $reservationId,
        ConflictType $type,
        ?string $arkFp,
        ?string $externalFp,
        ?string $externalId,
        ?string $externalRefHash = null,
    ): int {
        if ($dryRun) {
            return 1;
        }

        $this->conflicts->open(
            $provider,
            $reservationId,
            $type,
            $arkFp,
            $externalFp,
            ProviderRef::mask($externalId),
            $externalRefHash,
        );

        return 1;
    }

    /**
     * @return ReconcileReport
     */
    private function emptyReport(string $provider): array
    {
        return [
            'provider' => $provider,
            'checked' => 0,
            'in_sync' => 0,
            'drift' => 0,
            'stale' => 0,
            'ark_only' => 0,
            'external_only' => 0,
            'conflicts_opened' => 0,
        ];
    }
}
