<?php

declare(strict_types=1);

namespace App\Domain\Integration\Service;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Dto\InboundDecision;
use App\Domain\Integration\Enum\ConflictType;
use App\Domain\Integration\Enum\SyncDirection;
use App\Domain\Integration\Enum\SyncEventStatus;
use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\IntegrationContext;
use App\Domain\Integration\Support\ExternalStatusMapper;
use App\Domain\Integration\Support\ReservationFingerprint;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationProviderMapping;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * External → ARK。Integration Boundary（Normalize → Validate → Mapping → Dedupe → Conflict → 反映）。
 *
 * - 反映は必ず `ReservationService` 経由（invariant を迂回しない）。
 * - 1 external ID は advisory lock で直列化（並行 import の二重予約防止・F-01）。
 * - 自動反映は confirmed↔時刻/担当 と canceled のみ。completed/no_show/未知 status は CONFLICT（F-07）。
 * - 順序比較材料（external_updated_at / rawVersion）が無い Provider は既存予約を自動更新しない（F-02）。
 * - inbound で行った変更は同一 Provider へ折り返さない（IntegrationContext・F-06）。
 * - 反映後、ARK と外部の fingerprint 一致を検証してから in_sync（F-08）。
 */
final class InboundReservationSync
{
    private const AUTO_APPLICABLE = [
        ReservationStatus::Confirmed->value,
        ReservationStatus::Canceled->value,
    ];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationMappingRepository $mappings,
        private readonly ExternalStatusMapper $statusMapper,
        private readonly ConflictRecorder $conflicts,
        private readonly SyncEventRecorder $events,
        private readonly IntegrationContext $context,
    ) {}

    public function apply(ExternalReservationData $data): InboundDecision
    {
        $lock = 'rsv-inbound:'.substr(md5($data->provider.'|'.$data->externalReservationId), 0, 24);

        return $this->withAdvisoryLock($lock, fn (): InboundDecision => $this->context->applyingInbound(
            fn (): InboundDecision => $this->run($data),
        ));
    }

    private function run(ExternalReservationData $data): InboundDecision
    {
        $startedAt = CarbonImmutable::now();
        $correlationId = (string) Str::uuid();

        if ($data->endsAt->lessThanOrEqualTo($data->startsAt)) {
            $this->event($data, SyncOperation::Fetch, SyncEventStatus::Skipped, null, $correlationId, $startedAt, 'invalid_time');

            return new InboundDecision(InboundDecision::SKIPPED_UNSUPPORTED, null, null, 'invalid_time');
        }

        $mappedStatus = $this->statusMapper->map($data);
        $canonical = $mappedStatus?->value ?? 'unknown';
        $mapping = $this->mappings->findByExternal($data->provider, $data->externalReservationId);

        if ($mapping === null) {
            return $this->handleNew($data, $mappedStatus, $canonical, $correlationId, $startedAt);
        }

        return $this->handleExisting($data, $mapping, $mappedStatus, $canonical, $correlationId, $startedAt);
    }

    // ---- 新規（mapping なし）----

    private function handleNew(
        ExternalReservationData $data,
        ?ReservationStatus $mappedStatus,
        string $canonical,
        string $correlationId,
        CarbonImmutable $startedAt,
    ): InboundDecision {
        if ($mappedStatus === null) {
            return $this->conflict($data, null, ConflictType::UnsupportedState, null, $canonical, $correlationId, $startedAt, SyncOperation::Create);
        }

        if ($data->isCanceled) {
            $this->event($data, SyncOperation::Create, SyncEventStatus::NoOp, null, $correlationId, $startedAt);

            return new InboundDecision(InboundDecision::NO_OP);
        }

        // Phase 9 は past-completed / no_show の自動取込をしない（entitlement 副作用があるため）。
        if (! in_array($mappedStatus->value, self::AUTO_APPLICABLE, true)) {
            return $this->conflict($data, null, ConflictType::UnsupportedState, null, $canonical, $correlationId, $startedAt, SyncOperation::Create);
        }

        $service = $this->resolveService($data->serviceRef);
        $customerId = $this->resolveCustomerId($data->externalCustomerId);
        $staffId = $this->resolveStaffId($data->staffRef);

        // 参照が解決できないと ARK 予約を正しく表現できない → 取り込まない。
        if ($service === null || $customerId === null || ($data->staffRef !== null && $staffId === null)) {
            return $this->conflict($data, null, ConflictType::MissingMapping, null, $canonical, $correlationId, $startedAt, SyncOperation::Create);
        }

        // 孤児（前回 create 後・mapping 作成前に停止）を回収する。
        $orphan = Reservation::query()
            ->where('source', ReservationSource::External->value)
            ->where('customer_id', $customerId)
            ->where('service_id', $service->id)
            ->where('starts_at', $data->startsAt)
            ->whereDoesntHave('providerMappings', fn ($q) => $q->where('provider', $data->provider))
            ->first();

        if ($orphan !== null) {
            $mapping = $this->mappings->firstOrCreateForExternal($data->provider, $data->externalReservationId, (int) $orphan->id, $data->externalCustomerId);
            $this->finalizeMapping($mapping, $data, $orphan, $canonical);
            $this->event($data, SyncOperation::Create, SyncEventStatus::NoOp, (int) $orphan->id, $correlationId, $startedAt);

            return new InboundDecision(InboundDecision::NO_OP, (int) $orphan->id);
        }

        try {
            $reservation = DB::transaction(function () use ($data, $service, $customerId, $staffId, $canonical): Reservation {
                $reservation = $this->reservations->create(new ReservationInput(
                    customerId: $customerId,
                    serviceId: (int) $service->id,
                    staffId: $staffId,
                    boothId: null,
                    startsAt: $data->startsAt,
                    source: ReservationSource::External,
                    actorUserId: null,
                    notes: null,
                    adminContext: true,
                    paymentMethod: PaymentMethod::Onsite,
                ));

                $mapping = $this->mappings->firstOrCreateForExternal($data->provider, $data->externalReservationId, (int) $reservation->id, $data->externalCustomerId);
                $this->finalizeMapping($mapping, $data, $reservation, $canonical);

                return $reservation;
            });
        } catch (SlotUnavailableException) {
            return $this->conflict($data, null, ConflictType::DuplicateExternal, null, $canonical, $correlationId, $startedAt, SyncOperation::Create);
        }

        $this->event($data, SyncOperation::Create, SyncEventStatus::Succeeded, (int) $reservation->id, $correlationId, $startedAt);

        return new InboundDecision(InboundDecision::CREATE, (int) $reservation->id);
    }

    // ---- 既存（mapping あり）----

    private function handleExisting(
        ExternalReservationData $data,
        ReservationProviderMapping $mapping,
        ?ReservationStatus $mappedStatus,
        string $canonical,
        string $correlationId,
        CarbonImmutable $startedAt,
    ): InboundDecision {
        $reservation = $mapping->reservation;

        if ($reservation === null) {
            return $this->conflict($data, null, ConflictType::MissingMapping, null, $canonical, $correlationId, $startedAt, SyncOperation::Update);
        }

        // 未知 status は no-op 判定より先に conflict（F-07）。
        if ($mappedStatus === null) {
            $this->markMapping($mapping, 'conflict');

            return $this->conflict($data, (int) $reservation->id, ConflictType::UnsupportedState, ReservationFingerprint::forReservation($reservation), $canonical, $correlationId, $startedAt, SyncOperation::Update);
        }

        // stale（順序材料があり、保存済みより古い）→ 巻き戻さない。
        if ($data->externalUpdatedAt !== null
            && $mapping->external_updated_at !== null
            && $data->externalUpdatedAt->lessThan(CarbonImmutable::parse($mapping->external_updated_at))) {
            $mapping->forceFill(['last_seen_at' => now()])->save();

            return new InboundDecision(InboundDecision::SKIPPED_STALE, (int) $reservation->id);
        }

        $externalFp = $data->fingerprint($canonical);

        // 変化なし → last_seen だけ更新（event は出さない・F-17）。
        if ($mapping->fingerprint === $externalFp) {
            $mapping->forceFill(['last_seen_at' => now()])->save();

            return new InboundDecision(InboundDecision::NO_OP, (int) $reservation->id);
        }

        // 順序比較材料が無い Provider は、既存予約の 2 回目以降の異なる snapshot を自動反映しない（F-02）。
        if (! $data->hasOrderingSignal()) {
            $this->markMapping($mapping, 'conflict');

            return $this->conflict($data, (int) $reservation->id, ConflictType::StatusChangedBoth, ReservationFingerprint::forReservation($reservation), $canonical, $correlationId, $startedAt, SyncOperation::Update, $externalFp);
        }

        $arkFp = ReservationFingerprint::forReservation($reservation);
        $arkChangedSinceSync = $mapping->fingerprint !== null && $arkFp !== $mapping->fingerprint;

        if ($arkChangedSinceSync) {
            $type = ($data->isCanceled || $reservation->status === ReservationStatus::Canceled)
                ? ConflictType::CancelVsUpdate
                : ConflictType::TimeChangedBoth;
            $this->markMapping($mapping, 'conflict');

            return $this->conflict($data, (int) $reservation->id, $type, $arkFp, $canonical, $correlationId, $startedAt, SyncOperation::Update, $externalFp);
        }

        // 外部のみ変化。
        if ($data->isCanceled) {
            if (in_array($reservation->status, [ReservationStatus::Canceled, ReservationStatus::Expired, ReservationStatus::NoShow], true)) {
                $this->finalizeMapping($mapping, $data, $reservation, $canonical);

                return new InboundDecision(InboundDecision::NO_OP, (int) $reservation->id);
            }

            $updated = $this->reservations->cancel($reservation, '外部予約でキャンセルされました', null);
            $this->finalizeMapping($mapping, $data, $updated, $canonical);
            $this->event($data, SyncOperation::Cancel, SyncEventStatus::Succeeded, (int) $reservation->id, $correlationId, $startedAt);

            return new InboundDecision(InboundDecision::CANCEL, (int) $reservation->id);
        }

        // 自動反映できるのは confirmed の時刻/担当変更だけ。
        if ($reservation->status !== ReservationStatus::Confirmed || ! in_array($mappedStatus->value, self::AUTO_APPLICABLE, true)) {
            $this->markMapping($mapping, 'conflict');

            return $this->conflict($data, (int) $reservation->id, ConflictType::StatusChangedBoth, $arkFp, $canonical, $correlationId, $startedAt, SyncOperation::Update, $externalFp);
        }

        try {
            $updated = $this->reservations->reschedule(new RescheduleInput(
                reservationId: (int) $reservation->id,
                staffId: $this->resolveStaffId($data->staffRef) ?? $reservation->staff_id,
                boothId: $reservation->booth_id,
                startsAt: $data->startsAt,
                expectedVersion: (int) $reservation->version,
                actorUserId: null,
                adminContext: true,
                updateNotes: false,
                notes: null,
            ));
        } catch (StaleReservationException|ValidationException|SlotUnavailableException) {
            // ARK 側が同時に動いた / 枠が埋まった。retry せず conflict にする（F-10）。
            $this->markMapping($mapping, 'conflict');

            return $this->conflict($data, (int) $reservation->id, ConflictType::TimeChangedBoth, $arkFp, $canonical, $correlationId, $startedAt, SyncOperation::Update, $externalFp);
        }

        $this->finalizeMapping($mapping, $data, $updated, $canonical);
        $this->event($data, SyncOperation::Update, SyncEventStatus::Succeeded, (int) $reservation->id, $correlationId, $startedAt);

        return new InboundDecision(InboundDecision::UPDATE, (int) $reservation->id);
    }

    // ---- helpers ----

    /**
     * 反映後、ARK と外部の fingerprint が一致していれば in_sync、そうでなければ drift + conflict（F-08）。
     */
    private function finalizeMapping(ReservationProviderMapping $mapping, ExternalReservationData $data, Reservation $reservation, string $canonical): void
    {
        $externalFp = $data->fingerprint($canonical);
        $arkFp = ReservationFingerprint::forReservation($reservation->fresh() ?? $reservation);
        $synced = $arkFp === $externalFp;

        $mapping->forceFill([
            'fingerprint' => $externalFp,
            'external_updated_at' => $data->externalUpdatedAt,
            'external_version' => $data->rawVersion,
            'last_synced_at' => now(),
            'last_seen_at' => now(),
            'external_customer_id' => $data->externalCustomerId ?? $mapping->external_customer_id,
            'sync_status' => $synced ? 'in_sync' : 'drift',
        ])->save();

        if (! $synced) {
            // service/duration/staff 差など ARK が表現できない差異。
            $this->conflicts->open(
                $data->provider, (int) $reservation->id, ConflictType::TimeChangedBoth,
                $arkFp, $externalFp, $data->maskedExternalId(), $data->refHash(),
            );
        }
    }

    private function markMapping(ReservationProviderMapping $mapping, string $status): void
    {
        $mapping->forceFill(['sync_status' => $status, 'last_seen_at' => now()])->save();
    }

    private function conflict(
        ExternalReservationData $data,
        ?int $reservationId,
        ConflictType $type,
        ?string $arkFp,
        string $canonical,
        string $correlationId,
        CarbonImmutable $startedAt,
        SyncOperation $operation,
        ?string $externalFp = null,
    ): InboundDecision {
        $this->conflicts->open(
            $data->provider, $reservationId, $type,
            $arkFp, $externalFp ?? $data->fingerprint($canonical),
            $data->maskedExternalId(), $data->refHash(),
        );
        $this->event($data, $operation, SyncEventStatus::Conflict, $reservationId, $correlationId, $startedAt);

        return new InboundDecision(InboundDecision::CONFLICT, $reservationId, $type->value);
    }

    private function resolveService(?string $ref): ?Service
    {
        return $ref !== null && ctype_digit($ref) ? Service::query()->find((int) $ref) : null;
    }

    private function resolveStaffId(?string $ref): ?int
    {
        return $ref !== null && ctype_digit($ref) ? Staff::query()->where('user_id', (int) $ref)->value('user_id') : null;
    }

    private function resolveCustomerId(?string $ref): ?int
    {
        return $ref !== null && ctype_digit($ref) ? Customer::query()->where('user_id', (int) $ref)->value('user_id') : null;
    }

    private function event(
        ExternalReservationData $data,
        SyncOperation $operation,
        SyncEventStatus $status,
        ?int $reservationId,
        string $correlationId,
        CarbonImmutable $startedAt,
        ?string $code = null,
    ): void {
        $this->events->record(
            provider: $data->provider,
            direction: SyncDirection::Inbound,
            operation: $operation,
            status: $status,
            reservationId: $reservationId,
            externalId: $data->externalReservationId,
            correlationId: $correlationId,
            safeErrorCode: $code,
            startedAt: $startedAt,
        );
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function withAdvisoryLock(string $name, \Closure $fn): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return $fn();
        }

        $got = (int) $connection->selectOne('SELECT GET_LOCK(?, 10) AS l', [$name])->l;

        if ($got !== 1) {
            // 取れなくても処理は継続（UNIQUE 制約が最終防衛線）。
            return $fn();
        }

        try {
            return $fn();
        } finally {
            $connection->statement('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
