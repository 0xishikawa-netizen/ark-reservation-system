<?php

declare(strict_types=1);

namespace App\Domain\Visit;

use App\Domain\Accounting\CheckoutService;
use App\Domain\Membership\MembershipReservationService;
use App\Domain\Reservation\ReservationStateMachine;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
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

    public function completeReservation(
        Reservation $reservation,
        ?Authenticatable $actor = null,
        ?string $operationId = null,
    ): VisitCompletionResult {
        $operationId ??= $this->operationIdFor($reservation);

        return DB::transaction(function () use ($reservation, $actor, $operationId): VisitCompletionResult {
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

            // 既存の追記型台帳・dedupe keyをそのまま利用し、別系統の減算を作らない。
            $this->tickets->consume($lockedReservation, $actor);
            $this->memberships->consume($lockedReservation, $actor);

            $sequence = $visit->visit_sequence ?? ((int) Visit::query()
                ->where('customer_id', $lockedReservation->customer_id)
                ->whereNotNull('visit_sequence')
                ->where('id', '!=', $visit->id)
                ->max('visit_sequence') + 1);
            $hasFutureReservation = Reservation::query()
                ->active()
                ->where('customer_id', $lockedReservation->customer_id)
                ->where('id', '!=', $lockedReservation->id)
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

            $visit->forceFill([
                'status' => VisitStatus::Completed,
                'completed_at' => $completedAt,
                'visit_sequence' => $sequence,
                'first_visit_gender_snapshot' => $sequence === 1 ? $customer->gender : null,
                'first_visit_age_years_snapshot' => $firstVisitAge,
                'future_reservation_exists_at_checkout' => $hasFutureReservation,
                'future_reservation_snapshot_at' => $completedAt,
                // 予約担当者の存在から指名を推測しない。既存完了VisitはNULLのまま。
                'staff_requested_at_checkout' => $lockedReservation->is_staff_requested,
                'requested_staff_id_at_checkout' => $lockedReservation->is_staff_requested ? $lockedReservation->staff_id : null,
            ])->save();

            (new ReservationStateMachine)->apply(
                $lockedReservation,
                'status',
                ReservationStatus::Completed->value,
            );
            $lockedReservation->forceFill(['attended_at' => $completedAt])->save();

            $this->audit->log(
                'reservation.completed',
                $lockedReservation,
                "予約完了 #{$lockedReservation->id} visit#{$visit->id}",
                $actor,
            );

            return new VisitCompletionResult(
                reservation: $lockedReservation->refresh(),
                visit: $visit->refresh(),
                accountingPending: $accountingPending,
            );
        });
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
            // 現行予約は予約作成時の標準時間＋bufferでends_atを確定している。新規ライブ完了時だけ、
            // その予約枠からbufferを除いた時間を初期実績にする（既存completedのbackfillには使わない）。
            // これにより、予約後にservice.duration_minが変更されても当時確保した枠を再現できる。
            $scheduledMinutes = max(
                1,
                (int) $reservation->starts_at->diffInMinutes($reservation->ends_at) - (int) $reservation->buffer_min,
            );
            // 予約台帳のstarts_atはJSTの壁時計値。施術実績はUTC instantで保存し、
            // 時間帯別稼働率が9時間ずれて集計されないようにする。
            $actualStart = CarbonImmutable::parse(
                $reservation->starts_at->format('Y-m-d H:i:s'),
                $this->businessTime->timezone(),
            )->utc();
            $treatments = collect([$this->visitFacts->addTreatment($visit, $reservation->service, [
                'actual_minutes' => $scheduledMinutes,
                'actual_started_at' => $actualStart,
                'actual_ended_at' => $actualStart->addMinutes($scheduledMinutes),
                'sort_order' => 0,
                'operation_key' => "visit-completion:{$operationId}:treatment:0",
            ], $actor)]);
        }

        foreach ($treatments as $treatment) {
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
            $this->visitFacts->completeTreatment($treatment, $actor);
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
