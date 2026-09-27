<?php

declare(strict_types=1);

namespace App\Domain\Visit;

use App\Domain\Accounting\CheckoutService;
use App\Domain\Membership\MembershipReservationService;
use App\Domain\Reservation\ReservationStateMachine;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\CheckoutExemptionReason;
use App\Enums\Visit\VisitStatus;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Visit;
use App\Models\VisitStaffNomination;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * 予約の来店完了に関する唯一のtransaction boundary。
 * 外部APIは呼ばず、DB内の事実・権利消化・snapshotだけを原子的に確定する。
 */
final class VisitCompletionService
{
    public function __construct(
        private readonly VisitFactService $visitFacts,
        private readonly CheckoutService $checkouts,
        private readonly TicketReservationService $tickets,
        private readonly MembershipReservationService $memberships,
        private readonly BusinessTime $businessTime,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  CheckoutExemptionReason|null  $exemption  会計を作らずに完了する正当な理由（無料・事前決済済み・回数券/月額利用）。
     *                                                   通常の有償施術は来店・会計入力から確定するため null にしない運用とする（Task 11-27）。
     */
    public function completeReservation(
        Reservation $reservation,
        ?Authenticatable $actor = null,
        ?string $operationId = null,
        ?CheckoutExemptionReason $exemption = null,
    ): VisitCompletionResult {
        $operationId ??= $this->operationIdFor($reservation);

        return DB::transaction(function () use ($reservation, $actor, $operationId, $exemption): VisitCompletionResult {
            $lockedReservation = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->status === ReservationStatus::Completed) {
                return $this->completedResult($lockedReservation);
            }
            if ($lockedReservation->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => __('messages.visit_completion.invalid_status'),
                ]);
            }

            // 同一顧客の同時完了を直列化し、visit_sequenceの採番競合を防ぐ。
            $customer = Customer::query()
                ->whereKey($lockedReservation->customer_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedReservation->loadMissing(['service.analysisCategory', 'staff']);
            $completedAt = now();

            $visit = Visit::query()
                ->where('reservation_id', $lockedReservation->id)
                ->lockForUpdate()
                ->first();

            if ($visit === null) {
                $visit = $this->visitFacts->createDraft($customer, $lockedReservation, [
                    'business_date' => $this->businessTime->businessDate($completedAt)->toDateString(),
                    'primary_staff_id' => $lockedReservation->staff_id,
                    'primary_staff_name_snapshot' => $lockedReservation->staff?->display_name,
                    'completion_operation_id' => $operationId,
                ], $actor);
            } else {
                $this->assertReusableDraftVisit($visit, $lockedReservation, $operationId);
                $visit->forceFill([
                    'primary_staff_id' => $visit->primary_staff_id ?? $lockedReservation->staff_id,
                    'primary_staff_name_snapshot' => $visit->primary_staff_name_snapshot ?? $lockedReservation->staff?->display_name,
                    'completion_operation_id' => $operationId,
                ])->save();
            }

            $this->finalizeTreatments($visit, $lockedReservation, $operationId, $actor);
            $accountingPending = $this->finalizeExistingCheckout($visit, $actor);
            if ($exemption !== null && $accountingPending) {
                // 会計なし完了の理由を来店に残し、「会計未確定」の要確認から外す。
                $visit->forceFill(['checkout_exemption_reason' => $exemption])->save();
                $accountingPending = false;
            }

            // 既存の追記型台帳・dedupe keyをそのまま利用し、別系統の減算を作らない。
            $this->tickets->consume($lockedReservation, $actor);
            $this->memberships->consume($lockedReservation, $actor);

            $this->applyCompletionSnapshots($visit, $customer, $lockedReservation, $completedAt);

            (new ReservationStateMachine)->apply(
                $lockedReservation,
                'status',
                ReservationStatus::Completed->value,
            );
            $lockedReservation->forceFill(['attended_at' => $completedAt])->save();

            $this->audit->log(
                'reservation.completed',
                $lockedReservation,
                "予約完了 #{$lockedReservation->id} visit#{$visit->id}"
                    .($visit->checkout_exemption_reason !== null ? ' 会計なし:'.$visit->checkout_exemption_reason->value : ''),
                $actor,
            );

            return new VisitCompletionResult(
                reservation: $lockedReservation->refresh(),
                visit: $visit->refresh(),
                accountingPending: $accountingPending,
            );
        });
    }

    /**
     * 予約なし来店（飛び込み）の完了。予約完了と同じsnapshot規則を使い、予約・権利消化は行わない。
     */
    public function completeWalkIn(Visit $visit, ?Authenticatable $actor = null): VisitCompletionResult
    {
        return DB::transaction(function () use ($visit, $actor): VisitCompletionResult {
            $locked = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === VisitStatus::Completed) {
                return new VisitCompletionResult(
                    reservation: null,
                    visit: $locked,
                    accountingPending: $locked->checkout === null || $locked->checkout->status === CheckoutStatus::Draft,
                );
            }
            if ($locked->status !== VisitStatus::Draft || $locked->reservation_id !== null) {
                throw ValidationException::withMessages(['visit' => __('messages.visit_completion.visit_conflict')]);
            }
            $customer = Customer::query()->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();

            $treatments = VisitTreatment::query()->where('visit_id', $locked->id)->lockForUpdate()->get();
            if ($treatments->isEmpty()) {
                throw ValidationException::withMessages(['treatments' => __('messages.checkout_entry.treatment_required')]);
            }
            foreach ($treatments as $treatment) {
                $this->visitFacts->completeTreatment($treatment, $actor);
            }
            $accountingPending = $this->finalizeExistingCheckout($locked, $actor);
            $this->applyCompletionSnapshots($locked, $customer, null, now());
            $this->audit->log('visit.completed', $locked, "予約なし来店を完了 visit#{$locked->id}", $actor);

            return new VisitCompletionResult(reservation: null, visit: $locked->refresh(), accountingPending: $accountingPending);
        });
    }

    /** 来店順・初診属性・次回予約・指名を完了時点の値で固定する。 */
    private function applyCompletionSnapshots(Visit $visit, Customer $customer, ?Reservation $reservation, CarbonImmutable|Carbon $completedAt): void
    {
        $sequence = $visit->visit_sequence ?? ((int) Visit::query()
            ->where('customer_id', $customer->getKey())
            ->whereNotNull('visit_sequence')
            ->where('id', '!=', $visit->id)
            ->max('visit_sequence') + 1);
        $hasFutureReservation = Reservation::query()
            ->active()
            ->where('customer_id', $customer->getKey())
            ->when($reservation !== null, fn ($query) => $query->where('id', '!=', $reservation->id))
            ->where('starts_at', '>', $completedAt)
            ->exists();
        $firstVisitAge = null;
        if ($sequence === 1 && $customer->birthday !== null) {
            $birthday = CarbonImmutable::createFromFormat('!Y-m-d', $customer->birthday->toDateString(), $this->businessTime->timezone());
            $businessDate = CarbonImmutable::createFromFormat(
                '!Y-m-d', $visit->business_date->toDateString(), $this->businessTime->timezone(),
            );
            if ($birthday !== false && $businessDate !== false && $birthday->lte($businessDate)) {
                $firstVisitAge = (int) $birthday->diffInYears($businessDate);
            }
        }

        [$staffRequested, $requestedStaffId, $recordedAt] = $this->nominationSnapshot($visit, $reservation, $completedAt);

        // 初診時のカルテ分析項目をsnapshotする（Task 11-21）。後日の顧客情報変更で過去の新規統計を変えない。
        $karte = [
            'first_visit_karte_snapshot_at' => null, 'first_visit_acquisition_channel_id' => null,
            'first_visit_referred' => null, 'first_visit_prefecture' => null, 'first_visit_city' => null,
        ];
        if ($sequence === 1) {
            $karte = [
                'first_visit_karte_snapshot_at' => $completedAt,
                'first_visit_acquisition_channel_id' => $customer->acquisition_channel_id,
                'first_visit_referred' => $this->referredSnapshot($customer),
                'first_visit_prefecture' => $customer->prefecture,
                'first_visit_city' => $customer->city,
            ];
            $purposeIds = DB::table('customer_visit_purpose')->where('customer_id', $customer->getKey())->pluck('visit_purpose_id');
            DB::table('visit_first_purposes')->insertOrIgnore($purposeIds->map(fn ($id): array => [
                'visit_id' => $visit->id, 'visit_purpose_id' => (int) $id,
            ])->all());
        }

        $visit->forceFill([
            'status' => VisitStatus::Completed,
            'completed_at' => $completedAt,
            'visit_sequence' => $sequence,
            'first_visit_gender_snapshot' => $sequence === 1 ? $customer->gender : null,
            'first_visit_age_years_snapshot' => $firstVisitAge,
            'future_reservation_exists_at_checkout' => $hasFutureReservation,
            'future_reservation_snapshot_at' => $completedAt,
            'staff_requested_at_checkout' => $staffRequested,
            'requested_staff_id_at_checkout' => $requestedStaffId,
            'nominations_recorded_at' => $recordedAt,
            ...$karte,
        ])->save();
    }

    /**
     * 画面で指名を記録した来店はvisit_staff_nominationsが正本。visit単位の旧列は「主担当が指名されたか」を表す。
     * 未記録の予約来店は従来どおり予約の明示指名だけを使い、担当者の存在から指名を推測しない。
     *
     * @return array{0:?bool,1:?int,2:mixed}
     */
    private function nominationSnapshot(Visit $visit, ?Reservation $reservation, mixed $completedAt): array
    {
        if ($visit->nominations_recorded_at !== null) {
            $nominated = VisitStaffNomination::query()->where('visit_id', $visit->id)->pluck('staff_id')
                ->filter()->map(fn ($id): int => (int) $id)->all();
            $primaryNominated = $visit->primary_staff_id !== null && in_array((int) $visit->primary_staff_id, $nominated, true);

            return [$primaryNominated, $primaryNominated ? (int) $visit->primary_staff_id : null, $visit->nominations_recorded_at];
        }
        if ($reservation === null || $reservation->is_staff_requested === null) {
            return [null, null, null];
        }
        if ($reservation->is_staff_requested && $reservation->staff_id !== null) {
            VisitStaffNomination::query()->firstOrCreate(
                ['visit_id' => $visit->id, 'staff_id' => $reservation->staff_id],
                ['staff_name_snapshot' => $reservation->staff?->display_name],
            );
        }

        return [
            (bool) $reservation->is_staff_requested,
            $reservation->is_staff_requested ? $reservation->staff_id : null,
            $completedAt,
        ];
    }

    /**
     * 紹介の有無。紹介者の記録または来店動機「紹介」ならtrue、来店動機が別の値ならfalse、
     * どちらも未入力ならNULL（未入力を「紹介なし」と混同しない）。
     */
    private function referredSnapshot(Customer $customer): ?bool
    {
        if ($customer->referrer_customer_id !== null || trim((string) $customer->referrer_name) !== '') {
            return true;
        }
        if ($customer->acquisition_channel_id === null) {
            return null;
        }

        return DB::table('acquisition_channels')->where('id', $customer->acquisition_channel_id)->value('code') === 'referral';
    }

    private function completedResult(Reservation $reservation): VisitCompletionResult
    {
        $visit = Visit::query()->where('reservation_id', $reservation->id)->first();
        if ($visit === null || $visit->status !== VisitStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => __('messages.visit_completion.legacy_completed_without_visit'),
            ]);
        }

        return new VisitCompletionResult(
            reservation: $reservation,
            visit: $visit,
            accountingPending: $visit->checkout === null || $visit->checkout->status === CheckoutStatus::Draft,
        );
    }

    private function assertReusableDraftVisit(Visit $visit, Reservation $reservation, string $operationId): void
    {
        if ($visit->status !== VisitStatus::Draft
            || (int) $visit->customer_id !== (int) $reservation->customer_id
            || ($visit->completion_operation_id !== null && $visit->completion_operation_id !== $operationId)) {
            throw ValidationException::withMessages([
                'visit' => __('messages.visit_completion.visit_conflict'),
            ]);
        }
    }

    private function finalizeTreatments(
        Visit $visit,
        Reservation $reservation,
        string $operationId,
        ?Authenticatable $actor,
    ): void {
        $treatments = VisitTreatment::query()
            ->where('visit_id', $visit->id)
            ->lockForUpdate()
            ->get();

        if ($treatments->isEmpty()) {
            $treatments = $this->seedTreatmentsFromReservation($visit, $reservation, "visit-completion:{$operationId}", $actor);
        }

        foreach ($treatments as $treatment) {
            $this->assignReservationStaff($treatment, $reservation, $actor);
            $this->visitFacts->completeTreatment($treatment, $actor);
        }
    }

    /**
     * 予約の内容から下書きの施術実績を作る（来店・会計入力の初期表示と、会計なし完了の両方で使う）。
     * 実施内容は来店・会計入力で変更できる。完了後は施術実績（visit_treatments）が正本で、予約を後から
     * 変更しても完了済みの実績は変わらない。
     *
     * @return Collection<int, VisitTreatment>
     */
    public function seedTreatmentsFromReservation(Visit $visit, Reservation $reservation, string $operationKeyPrefix, ?Authenticatable $actor): Collection
    {
        $reservation->loadMissing(['service.analysisCategory', 'staff', 'segments.service.analysisCategory']);
        // 現行予約は予約作成時の標準時間＋bufferでends_atを確定している。その予約枠からbufferを除いた時間
        // （延長を含む）を初期実績にする（既存completedのbackfillには使わない）。予約後にservice.duration_minが
        // 変更されても当時確保した枠を再現できる。延長で追加した予定構成（はり30分など）は別の施術として並べる。
        $scheduledMinutes = $reservation->bookedMinutes();
        $segments = $reservation->segments;
        $baseMinutes = max(1, $scheduledMinutes - (int) $segments->sum('minutes'));
        // 予約台帳のstarts_atはJSTの壁時計値。施術実績はUTC instantで保存し、
        // 時間帯別稼働率が9時間ずれて集計されないようにする。
        $cursor = CarbonImmutable::parse(
            $reservation->starts_at->format('Y-m-d H:i:s'),
            $this->businessTime->timezone(),
        )->utc();
        $plan = [[$reservation->service, $baseMinutes], ...$segments->map(fn ($segment): array => [$segment->service, (int) $segment->minutes])->all()];
        $treatments = collect();
        foreach ($plan as $position => [$service, $minutes]) {
            $treatment = $this->visitFacts->addTreatment($visit, $service, [
                'actual_minutes' => $minutes,
                'actual_started_at' => $cursor,
                'actual_ended_at' => $cursor->addMinutes($minutes),
                'sort_order' => $position,
                'booth_id' => $reservation->booth_id,
                'operation_key' => "{$operationKeyPrefix}:treatment:{$position}",
            ], $actor);
            $this->assignReservationStaff($treatment, $reservation, $actor);
            $treatments->push($treatment);
            $cursor = $cursor->addMinutes($minutes);
        }

        return $treatments;
    }

    private function assignReservationStaff(VisitTreatment $treatment, Reservation $reservation, ?Authenticatable $actor): void
    {
        $assignmentsExist = VisitTreatmentStaff::query()
            ->where('visit_treatment_id', $treatment->id)
            ->exists();
        if (! $assignmentsExist && $reservation->staff !== null) {
            $this->visitFacts->assignStaff($treatment, $reservation->staff, [
                'actual_minutes' => (int) $treatment->actual_minutes,
                'actual_started_at' => $treatment->actual_started_at,
                'actual_ended_at' => $treatment->actual_ended_at,
                'sort_order' => 0,
            ], $actor);
        }
    }

    /** trueは会計情報未作成のため後続確定待ち。 */
    private function finalizeExistingCheckout(Visit $visit, ?Authenticatable $actor): bool
    {
        $checkout = Checkout::query()->where('visit_id', $visit->id)->lockForUpdate()->first();
        if ($checkout === null) {
            return true;
        }
        if ($checkout->status === CheckoutStatus::Draft) {
            $this->checkouts->finalize($checkout, $actor);

            return false;
        }
        if ($checkout->status === CheckoutStatus::Finalized) {
            return false;
        }

        throw ValidationException::withMessages([
            'checkout' => __('messages.visit_completion.voided_checkout'),
        ]);
    }

    private function operationIdFor(Reservation $reservation): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "ark:reservation-completion:{$reservation->getKey()}")->toString();
    }
}
