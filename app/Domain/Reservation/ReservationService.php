<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Domain\Ticket\TicketReservationService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Enums\Reservation\SyncStatus;
use App\Exceptions\NonBoundaryStartException;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Models\Booth;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\SlotKey;
use App\Support\StateMachine\InvalidStateTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TicketReservationService $tickets,
    ) {}

    /** @throws ValidationException|SlotUnavailableException */
    public function create(ReservationInput $in): Reservation
    {
        $service = Service::query()->findOrFail($in->serviceId);
        $endsAt = $in->startsAt->addMinutes($service->duration_min);

        $this->validateReservationDetails(
            service: $service,
            staffId: $in->staffId,
            boothId: $in->boothId,
            startsAt: $in->startsAt,
            endsAt: $endsAt,
            adminContext: $in->adminContext,
        );

        $slots = $this->occupiedSlots($in->startsAt, $endsAt, $in->adminContext);

        try {
            $reservation = DB::transaction(function () use ($in, $endsAt, $slots): Reservation {
                $reservation = Reservation::query()->create([
                    'customer_id' => $in->customerId,
                    'service_id' => $in->serviceId,
                    'staff_id' => $in->staffId,
                    'booth_id' => $in->boothId,
                    'starts_at' => $in->startsAt,
                    'ends_at' => $endsAt,
                    'source' => $in->source,
                    'payment_method' => $in->paymentMethod,
                    'payment_status' => PaymentStatus::Unpaid,
                    'status' => ReservationStatus::Confirmed,
                    'sync_status' => SyncStatus::NotRequired,
                    'version' => 0,
                    'notes' => $in->notes,
                    'created_by' => $in->actorUserId,
                ]);

                ReservationResourceSlot::insert($this->slotRows(
                    reservation: $reservation,
                    staffId: $in->staffId,
                    boothId: $in->boothId,
                    slots: $slots,
                ));

                if ($in->paymentMethod === PaymentMethod::Ticket) {
                    $this->tickets->hold($reservation, $this->actor($in->actorUserId));
                }

                return $reservation;
            });
        } catch (QueryException $exception) {
            $this->throwSlotConflictForIntegrityViolation($exception);
        }

        $this->auditLogger->log(
            'reservation.created',
            $reservation,
            "予約作成 #{$reservation->id} {$reservation->starts_at->format('Y-m-d H:i')}",
            $this->actor($in->actorUserId),
        );

        return $reservation;
    }

    /** @throws ValidationException|SlotUnavailableException|StaleReservationException */
    public function reschedule(RescheduleInput $in): Reservation
    {
        try {
            $reservation = DB::transaction(function () use ($in): Reservation {
                $reservation = Reservation::query()
                    ->whereKey($in->reservationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($reservation->version !== $in->expectedVersion) {
                    throw new StaleReservationException;
                }

                if ($reservation->status !== ReservationStatus::Confirmed) {
                    $this->throwValidation('status', '確定済みの予約のみ変更できます。');
                }

                if (! $in->adminContext && ! $reservation->starts_at->isFuture()) {
                    $this->throwValidation('starts_at', '開始済みの予約は変更できません。');
                }

                $service = $reservation->service()->firstOrFail();
                $endsAt = $in->startsAt->addMinutes($service->duration_min);

                $this->validateReservationDetails(
                    service: $service,
                    staffId: $in->staffId,
                    boothId: $in->boothId,
                    startsAt: $in->startsAt,
                    endsAt: $endsAt,
                    adminContext: $in->adminContext,
                );

                ReservationResourceSlot::query()
                    ->where('reservation_id', $reservation->id)
                    ->delete();

                $slots = $this->occupiedSlots($in->startsAt, $endsAt, $in->adminContext);

                ReservationResourceSlot::insert($this->slotRows(
                    reservation: $reservation,
                    staffId: $in->staffId,
                    boothId: $in->boothId,
                    slots: $slots,
                ));

                $changes = [
                    'starts_at' => $in->startsAt,
                    'ends_at' => $endsAt,
                    'staff_id' => $in->staffId,
                    'booth_id' => $in->boothId,
                    'version' => $reservation->version + 1,
                ];

                if ($in->updateNotes) {
                    $changes['notes'] = $in->notes;
                }

                $reservation->update($changes);

                return $reservation;
            });
        } catch (QueryException $exception) {
            $this->throwSlotConflictForIntegrityViolation($exception);
        }

        $this->auditLogger->log(
            'reservation.rescheduled',
            $reservation,
            "予約変更 #{$reservation->id} {$reservation->starts_at->format('Y-m-d H:i')}",
            $this->actor($in->actorUserId),
        );

        return $reservation;
    }

    /** @throws ValidationException */
    public function cancel(
        Reservation $reservation,
        ?string $reason,
        ?Authenticatable $actor,
    ): Reservation {
        $customerContext = $actor instanceof User && $actor->customer !== null;

        $reservation = DB::transaction(function () use ($reservation, $reason, $customerContext, $actor): Reservation {
            $reservation = $this->lockReservation($reservation);

            if ($customerContext && ! $reservation->starts_at->isFuture()) {
                $this->throwValidation('starts_at', '開始済みの予約はキャンセルできません。');
            }

            $this->applyStatus($reservation, ReservationStatus::Canceled);

            $reservation->forceFill([
                'canceled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            ReservationResourceSlot::query()
                ->where('reservation_id', $reservation->id)
                ->delete();

            $this->tickets->release($reservation, $actor);

            return $reservation;
        });

        $this->auditLogger->log(
            'reservation.canceled',
            $reservation,
            "予約キャンセル #{$reservation->id}",
            $actor,
        );

        return $reservation;
    }

    /** @throws ValidationException */
    public function markCompleted(
        Reservation $reservation,
        ?Authenticatable $actor,
    ): Reservation {
        $reservation = DB::transaction(function () use ($reservation, $actor): Reservation {
            $reservation = $this->lockReservation($reservation);
            $this->applyStatus($reservation, ReservationStatus::Completed);
            $reservation->forceFill(['attended_at' => now()])->save();
            $this->tickets->consume($reservation, $actor);

            return $reservation;
        });

        $this->auditLogger->log(
            'reservation.completed',
            $reservation,
            "予約完了 #{$reservation->id}",
            $actor,
        );

        return $reservation;
    }

    /** @throws ValidationException */
    public function markNoShow(
        Reservation $reservation,
        ?Authenticatable $actor,
    ): Reservation {
        $reservation = DB::transaction(function () use ($reservation, $actor): Reservation {
            $reservation = $this->lockReservation($reservation);
            $this->applyStatus($reservation, ReservationStatus::NoShow);
            $this->tickets->handleNoShow($reservation, $actor);

            return $reservation;
        });

        $this->auditLogger->log(
            'reservation.no_show',
            $reservation,
            "予約無断キャンセル #{$reservation->id}",
            $actor,
        );

        return $reservation;
    }

    /** @throws ValidationException */
    private function validateReservationDetails(
        Service $service,
        ?int $staffId,
        ?int $boothId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $adminContext,
    ): void {
        if (! $service->is_active) {
            $this->throwValidation('service_id', 'このサービスは現在利用できません。');
        }

        if (! $adminContext && ! $service->is_online_bookable) {
            $this->throwValidation('service_id', 'このサービスはオンライン予約できません。');
        }

        if ($service->requires_staff && $staffId === null) {
            $this->throwValidation('staff_id', 'このサービスには担当スタッフの指定が必要です。');
        }

        if ($staffId === null && $boothId === null) {
            $this->throwValidation('resources', '担当スタッフまたはブースを指定してください。');
        }

        if ($staffId !== null) {
            $isAssigned = DB::table('service_staff')
                ->where('service_id', $service->id)
                ->where('staff_id', $staffId)
                ->exists();

            if (! $isAssigned) {
                $this->throwValidation('staff_id', 'このスタッフはサービスを担当できません。');
            }

            $staff = Staff::query()->find($staffId);

            if ($staff === null || ! $staff->is_bookable) {
                $this->throwValidation('staff_id', 'このスタッフは現在予約できません。');
            }

            $withinShift = $startsAt->isSameDay($endsAt)
                && StaffShift::query()
                    ->where('staff_id', $staffId)
                    ->whereDate('work_date', $startsAt->toDateString())
                    ->whereTime('start_at', '<=', $startsAt->format('H:i:s'))
                    ->whereTime('end_at', '>=', $endsAt->format('H:i:s'))
                    ->exists();

            if (! $withinShift) {
                $this->throwValidation('starts_at', '指定時間はスタッフの勤務時間外です。');
            }
        }

        if ($boothId !== null) {
            $booth = Booth::query()->find($boothId);

            if ($booth === null || ! $booth->is_active) {
                $this->throwValidation('booth_id', 'このブースは現在利用できません。');
            }
        }

        if (! $adminContext && ! $startsAt->isFuture()) {
            $this->throwValidation('starts_at', '過去の日時は予約できません。');
        }
    }

    /** @return list<CarbonImmutable> */
    private function occupiedSlots(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $adminContext,
    ): array {
        $adminFreeTime = $adminContext
            && (bool) config('reservation.allow_admin_free_time', false);

        try {
            return SlotKey::fromSettings()->occupiedSlots($startsAt, $endsAt, $adminFreeTime);
        } catch (NonBoundaryStartException) {
            $this->throwValidation('starts_at', '開始時刻を予約枠の境界に合わせてください。');
        }
    }

    /**
     * @param  list<CarbonImmutable>  $slots
     * @return list<array{resource_type: string, resource_id: int, slot_start: CarbonImmutable, reservation_id: int, created_at: \Illuminate\Support\Carbon}>
     */
    private function slotRows(
        Reservation $reservation,
        ?int $staffId,
        ?int $boothId,
        array $slots,
    ): array {
        $createdAt = now();
        $resources = [];

        if ($staffId !== null) {
            $resources[] = [ResourceType::Staff, $staffId];
        }

        if ($boothId !== null) {
            $resources[] = [ResourceType::Booth, $boothId];
        }

        $rows = [];

        foreach ($resources as [$type, $resourceId]) {
            foreach ($slots as $slot) {
                $rows[] = [
                    'resource_type' => $type->value,
                    'resource_id' => $resourceId,
                    'slot_start' => $slot,
                    'reservation_id' => (int) $reservation->id,
                    'created_at' => $createdAt,
                ];
            }
        }

        return $rows;
    }

    /** @throws ValidationException */
    private function applyStatus(Reservation $reservation, ReservationStatus $status): void
    {
        try {
            (new ReservationStateMachine)->apply($reservation, 'status', $status->value);
        } catch (InvalidStateTransitionException) {
            $this->throwValidation('status', 'この予約はその操作を実行できません。');
        }
    }

    private function actor(?int $actorUserId): ?Authenticatable
    {
        if ($actorUserId !== null) {
            return User::query()->find($actorUserId);
        }

        return auth()->user();
    }

    private function lockReservation(Reservation $reservation): Reservation
    {
        return Reservation::query()
            ->whereKey($reservation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @throws SlotUnavailableException */
    private function throwSlotConflictForIntegrityViolation(QueryException $exception): never
    {
        if ((string) $exception->getCode() === '23000') {
            throw new SlotUnavailableException($exception);
        }

        throw $exception;
    }

    /** @throws ValidationException */
    private function throwValidation(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
