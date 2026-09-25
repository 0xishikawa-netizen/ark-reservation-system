<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\Service\ReservationOutboxRecorder;
use App\Domain\Membership\MembershipReservationService;
use App\Domain\Payment\PaymentService;
use App\Domain\Ticket\TicketReservationService;
use App\Domain\Visit\VisitCompletionService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus as CardPaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Enums\Reservation\SyncStatus;
use App\Events\OnlineReservationCreated;
use App\Exceptions\NonBoundaryStartException;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Models\Booth;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use App\Support\StateMachine\InvalidStateTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReservationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TicketReservationService $tickets,
        private readonly MembershipReservationService $memberships,
        private readonly ReservationOutboxRecorder $outbox,
        private readonly CancellationPolicy $cancellationPolicy,
        private readonly PaymentService $payments,
        private readonly BookingWindow $bookingWindow,
        private readonly VisitCompletionService $visitCompletion,
    ) {}

    /** @throws ValidationException|SlotUnavailableException */
    public function create(ReservationInput $in): Reservation
    {
        $service = Service::query()->findOrFail($in->serviceId);
        // 着替え・片付けの余白（バッファ）も枠として押さえるため ends_at に含める。
        // こうすることで重複チェック・空き枠計算は既存ロジックのままバッファを考慮できる。
        $endsAt = $in->startsAt->addMinutes($service->duration_min + $in->bufferMin);

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
                // カード決済（single）は Stripe の与信が済むまで確定しない。
                // 枠は pending_payment + payment_expires_at で HOLD する（PLAN §7）。
                $isCard = $in->paymentMethod === PaymentMethod::Single;

                $reservation = Reservation::query()->create([
                    'customer_id' => $in->customerId,
                    'service_id' => $in->serviceId,
                    'staff_id' => $in->staffId,
                    'is_staff_requested' => $in->staffId !== null && $in->isStaffRequested,
                    'booth_id' => $in->boothId,
                    'starts_at' => $in->startsAt,
                    'ends_at' => $endsAt,
                    'buffer_min' => $in->bufferMin,
                    'source' => $in->source,
                    'payment_method' => $in->paymentMethod,
                    'payment_status' => $isCard
                        ? PaymentStatus::PendingPayment
                        : PaymentStatus::Unpaid,
                    'status' => $isCard
                        ? ReservationStatus::PendingPayment
                        : ReservationStatus::Confirmed,
                    'payment_expires_at' => $isCard ? $this->paymentDeadline() : null,
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

                // 支払い方法ごとに排他。1 予約で ticket と membership を同時消費しない。
                // カード（single）は Stripe 側で処理し、ここでは台帳 HOLD を作らない（Phase 5 の分離要件）。
                if ($in->paymentMethod === PaymentMethod::Ticket) {
                    $this->tickets->hold($reservation, $this->actor($in->actorUserId));
                } elseif ($in->paymentMethod === PaymentMethod::Membership) {
                    $this->memberships->reserve($reservation, $this->actor($in->actorUserId));
                }

                // 外部連携が有効なら同一 transaction で Outbox 行を作る（Phase 9）。無効時は no-op。
                $this->outbox->record($reservation, SyncOperation::Create);

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

        // オンライン予約通知のリアルタイム配信（§9-12）。管理画面からの手入力は
        // OnlineReservationCreated::broadcastWhen() が判定して送信しない。
        // トランザクションのコミット後（＝確実に永続化された後）だけ発行する。
        OnlineReservationCreated::dispatch($reservation->id);

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
                    $this->throwValidation('status', __('messages.reservation.only_confirmed_editable'));
                }

                if (! $in->adminContext && ! $reservation->starts_at->isFuture()) {
                    $this->throwValidation('starts_at', __('messages.reservation.started_not_editable'));
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

                if ($in->isStaffRequested !== null) {
                    $changes['is_staff_requested'] = $in->staffId !== null && $in->isStaffRequested;
                } elseif ($in->staffId === null) {
                    // スタッフ割当が外れた場合、指名フラグも意味を持たないため一緒に落とす。
                    $changes['is_staff_requested'] = false;
                }

                $reservation->update($changes);

                $this->outbox->record($reservation, SyncOperation::Update);

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
        bool $customerContext = false,
    ): Reservation {
        $customerContext = $customerContext
            || ($actor instanceof User && $actor->customer !== null);

        $persisted = Reservation::query()->findOrFail($reservation->getKey());

        if ($persisted->status === ReservationStatus::PendingPayment) {
            $unfinishedPayment = $persisted->payments()
                ->where('kind', PaymentKind::Single->value)
                ->whereIn('status', [
                    CardPaymentStatus::Pending->value,
                    CardPaymentStatus::Authorized->value,
                ])
                ->latest('id')
                ->first();

            // 与信取消は予約更新の transaction 外で完了させ、結果不明のまま枠を解放しない。
            if ($unfinishedPayment !== null) {
                $this->payments->cancel($unfinishedPayment);
            }
        }

        $reservation = DB::transaction(function () use ($reservation, $reason, $customerContext, $actor): Reservation {
            $reservation = $this->lockReservation($reservation);

            if ($customerContext
                && $reservation->status !== ReservationStatus::PendingPayment
                && ! $reservation->starts_at->isFuture()) {
                $this->throwValidation('starts_at', __('messages.reservation.started_not_cancelable'));
            }

            $wasPendingPayment = $reservation->status === ReservationStatus::PendingPayment;

            $this->applyStatus($reservation, ReservationStatus::Canceled);

            if ($wasPendingPayment && in_array($reservation->payment_status, [
                PaymentStatus::PendingPayment,
                PaymentStatus::Authorized,
            ], true)) {
                (new ReservationPaymentStateMachine)->apply(
                    $reservation,
                    'payment_status',
                    PaymentStatus::Voided->value,
                );
            }

            $reservation->forceFill([
                'canceled_at' => now(),
                'cancel_reason' => $reason,
                'payment_expires_at' => $wasPendingPayment ? null : $reservation->payment_expires_at,
            ])->save();

            ReservationResourceSlot::query()
                ->where('reservation_id', $reservation->id)
                ->delete();

            $this->tickets->release($reservation, $actor);
            $this->memberships->release($reservation, $actor);

            $this->outbox->record($reservation, SyncOperation::Cancel);

            return $reservation;
        });

        $this->auditLogger->log(
            'reservation.canceled',
            $reservation,
            "予約キャンセル #{$reservation->id}",
            $actor,
        );

        // Stripe 返金は予約キャンセル transaction の commit 後にだけ実行する。
        $payment = $reservation->payments()
            ->where('kind', PaymentKind::Single->value)
            ->where('status', CardPaymentStatus::Succeeded->value)
            ->whereColumn('refunded_amount', '<', 'amount')
            ->latest('id')
            ->first();

        if ($payment !== null) {
            $percent = $this->cancellationPolicy->refundPercentFor($reservation, now());
            $amount = $this->cancellationPolicy->refundableAmount($payment, $percent);

            if ($amount > 0) {
                $actorId = $actor instanceof User ? (int) $actor->id : null;

                if ($actorId === null) {
                    $this->recordCancelRefundFailure($reservation, $payment, $amount, $percent, $actor);
                } else {
                    try {
                        // PaymentService の既存契約は Authenticatable を受け取るため、検証済み User を渡す。
                        $refund = $this->payments->refund(
                            $payment,
                            $amount,
                            "キャンセルポリシーによる返金（{$percent}%）",
                            $actor,
                        );
                    } catch (Throwable) {
                        $this->recordCancelRefundFailure($reservation, $payment, $amount, $percent, $actor);

                        return $reservation;
                    }

                    if (in_array($refund->status, [
                        RefundStatus::Succeeded,
                        RefundStatus::Pending,
                    ], true)) {
                        $settlementNote = $refund->status === RefundStatus::Pending
                            ? '・Stripe完了待ち'
                            : '';
                        $this->auditLogger->log(
                            'reservation.cancel_refunded',
                            $reservation,
                            "予約キャンセル返金 #{$reservation->id} {$amount}円（{$percent}%{$settlementNote}）",
                            $actor,
                        );
                    } elseif ($refund->status === RefundStatus::Failed) {
                        $this->recordCancelRefundFailure($reservation, $payment, $amount, $percent, $actor);
                    }
                }
            }
        }

        return $reservation;
    }

    private function recordCancelRefundFailure(
        Reservation $reservation,
        Payment $payment,
        int $amount,
        int $percent,
        ?Authenticatable $actor,
    ): void {
        $freshPayment = $payment->fresh();

        if ($freshPayment !== null) {
            $changes = ['needs_attention' => true];

            if ($freshPayment->failure_code === null) {
                $changes['failure_code'] = 'cancel_refund_failed';
                $changes['failure_message'] = 'キャンセルに伴う自動返金の確認が必要です。';
            }

            $freshPayment->forceFill($changes)->save();
        }

        $this->auditLogger->log(
            'reservation.cancel_refund_failed',
            $reservation,
            "予約キャンセル返金失敗 #{$reservation->id} {$amount}円（{$percent}%）要確認",
            $actor,
        );
    }

    /** @throws ValidationException */
    public function markCompleted(
        Reservation $reservation,
        ?Authenticatable $actor,
    ): Reservation {
        return $this->visitCompletion->completeReservation($reservation, $actor)->reservation;
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
            $this->memberships->handleNoShow($reservation, $actor);

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
            $this->throwValidation('service_id', __('messages.reservation.service_unavailable'));
        }

        if (! $adminContext && ! $service->is_online_bookable) {
            $this->throwValidation('service_id', __('messages.reservation.service_not_online_bookable'));
        }

        if ($service->requires_staff && $staffId === null) {
            $this->throwValidation('staff_id', __('messages.reservation.staff_required_for_service'));
        }

        if ($staffId === null && $boothId === null) {
            $this->throwValidation('resources', __('messages.reservation.resource_required'));
        }

        if ($staffId !== null) {
            $isAssigned = DB::table('service_staff')
                ->where('service_id', $service->id)
                ->where('staff_id', $staffId)
                ->exists();

            if (! $isAssigned) {
                $this->throwValidation('staff_id', __('messages.reservation.staff_not_assigned'));
            }

            $staff = Staff::query()->find($staffId);

            if ($staff === null || ! $staff->is_bookable) {
                $this->throwValidation('staff_id', __('messages.reservation.staff_not_bookable'));
            }

            $withinShift = $startsAt->isSameDay($endsAt)
                && StaffShift::query()
                    ->where('staff_id', $staffId)
                    ->whereDate('work_date', $startsAt->toDateString())
                    ->whereTime('start_at', '<=', $startsAt->format('H:i:s'))
                    ->whereTime('end_at', '>=', $endsAt->format('H:i:s'))
                    ->exists();

            if (! $withinShift) {
                $this->throwValidation('starts_at', __('messages.reservation.outside_shift'));
            }

            if ($this->hasScheduleBlockOverlap('staff_id', $staffId, $startsAt, $endsAt)) {
                $this->throwValidation('staff_id', __('messages.reservation.staff_block_overlap'));
            }
        }

        if ($boothId !== null) {
            $booth = Booth::query()->find($boothId);

            if ($booth === null || ! $booth->is_active) {
                $this->throwValidation('booth_id', __('messages.reservation.booth_unavailable'));
            }

            if ($this->hasScheduleBlockOverlap('booth_id', $boothId, $startsAt, $endsAt)) {
                $this->throwValidation('booth_id', __('messages.reservation.booth_block_overlap'));
            }
        }

        if (! $adminContext && ! $startsAt->isFuture()) {
            $this->throwValidation('starts_at', __('messages.reservation.past_datetime'));
        }

        // 店舗休業日は物理的に不可能な予約として、管理者手動も含め一律で拒否する（#11）。
        if ($this->bookingWindow->isClosedDate($startsAt)) {
            $this->throwValidation('starts_at', __('messages.reservation.closed_date'));
        }

        if (! $this->bookingWindow->isWithinCalendarHours($startsAt, $endsAt)) {
            $this->throwValidation('starts_at', __('messages.reservation.outside_calendar_hours'));
        }

        // 予約受付期間・直前締切は「顧客の予約」にのみ効かせる（管理者手動は従来どおり）。
        if (! $adminContext) {
            $reason = $this->bookingWindow->customerRejectionReason($startsAt);

            if ($reason !== null) {
                $this->throwValidation('starts_at', $reason);
            }
        }
    }

    /**
     * 予定ブロック（休憩・ミーティング等）との重複判定。メニュー所要時間全体で判定する（§35）。
     */
    private function hasScheduleBlockOverlap(
        string $column,
        int $resourceId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): bool {
        if (! $startsAt->isSameDay($endsAt)) {
            return false;
        }

        return DB::table('staff_schedule_blocks')
            ->where($column, $resourceId)
            ->whereDate('work_date', $startsAt->toDateString())
            ->whereTime('start_at', '<', $endsAt->format('H:i:s'))
            ->whereTime('end_at', '>', $startsAt->format('H:i:s'))
            ->exists();
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
            $this->throwValidation('starts_at', __('messages.reservation.non_boundary_start'));
        }
    }

    /**
     * @param  list<CarbonImmutable>  $slots
     * @return list<array{resource_type: string, resource_id: int, slot_start: CarbonImmutable, reservation_id: int, created_at: Carbon}>
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
            $this->throwValidation('status', __('messages.reservation.invalid_transition'));
        }
    }

    /**
     * 支払い期限。settings('reservation.hold_minutes') を正とする（ハードコードしない）。
     */
    private function paymentDeadline(): CarbonImmutable
    {
        $minutes = (int) app(Settings::class)->get('reservation.hold_minutes', 10);

        if ($minutes < 1) {
            $minutes = 10;
        }

        return CarbonImmutable::now()->addMinutes($minutes);
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
