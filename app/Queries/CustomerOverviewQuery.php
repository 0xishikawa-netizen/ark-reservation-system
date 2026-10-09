<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketWalletStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TicketWallet;

final class CustomerOverviewQuery
{
    public function __construct(private readonly AdminMembershipQuery $membershipQuery) {}

    /**
     * @param  bool  $canViewPayments  支払い要約は reservations.view 保持者のみに見せる
     *                                 （Phase 8 Task 8-7 / 決済一覧と同じ権限境界）。
     * @return array<string, mixed>
     */
    public function for(int $customerId, bool $canViewPayments = true): array
    {
        $now = now();

        $recentReservations = Reservation::query()
            ->select(['id', 'service_id', 'staff_id', 'starts_at', 'status'])
            ->where('customer_id', $customerId)
            ->with(['service:id,name', 'staff:user_id,display_name'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (Reservation $reservation): array => [
                'id' => (int) $reservation->id,
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i'),
                'service_name' => (string) $reservation->service->name,
                'staff_name' => $reservation->staff?->display_name,
                'status' => $reservation->status->value,
                'status_label' => self::reservationStatusLabel($reservation->status),
            ])
            ->values()
            ->all();

        $reservationTotals = Reservation::query()
            ->where('customer_id', $customerId)
            ->selectRaw(
                'COUNT(*) AS total, COALESCE(SUM(CASE WHEN starts_at > ? THEN 1 ELSE 0 END), 0) AS upcoming',
                [$now],
            )
            ->firstOrFail();

        $payments = $canViewPayments ? $this->payments($customerId) : null;

        $ticketTotals = TicketWallet::query()
            ->where('customer_id', $customerId)
            ->where('status', TicketWalletStatus::Active->value)
            ->selectRaw('COUNT(*) AS active_wallet_count, COALESCE(SUM(balance), 0) AS total_available')
            ->firstOrFail();

        return [
            'recent_reservations' => $recentReservations,
            'reservation_totals' => [
                'total' => (int) $reservationTotals->getAttribute('total'),
                'upcoming' => (int) $reservationTotals->getAttribute('upcoming'),
            ],
            'payments' => $payments,
            'tickets' => [
                'active_wallet_count' => (int) $ticketTotals->getAttribute('active_wallet_count'),
                'total_available' => (int) $ticketTotals->getAttribute('total_available'),
            ],
            'membership' => $this->membership($customerId),
        ];
    }

    /** @return array<string, mixed> */
    private function payments(int $customerId): array
    {
        $recent = Payment::query()
            ->select(['id', 'amount', 'currency', 'status', 'kind', 'needs_attention', 'created_at'])
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (Payment $payment): array => [
                'id' => (int) $payment->id,
                'amount' => (int) $payment->amount,
                'currency' => (string) $payment->currency,
                'status' => $payment->status->value,
                'status_label' => self::paymentStatusLabel($payment->status),
                'kind_label' => self::paymentKindLabel($payment->kind),
                'needs_attention' => (bool) $payment->needs_attention,
                'created_at' => $payment->created_at->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();

        $totals = Payment::query()
            ->where('customer_id', $customerId)
            ->selectRaw(
                'COUNT(*) AS total_count, '
                    .'COALESCE(SUM(CASE WHEN needs_attention = ? THEN 1 ELSE 0 END), 0) AS needs_attention_count',
                [true],
            )
            ->firstOrFail();

        return [
            'recent' => $recent,
            'total_count' => (int) $totals->getAttribute('total_count'),
            'needs_attention_count' => (int) $totals->getAttribute('needs_attention_count'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function membership(int $customerId): ?array
    {
        $membership = $this->membershipQuery->forCustomer($customerId);

        if ($membership === null) {
            return null;
        }

        /** @var array<string, mixed> $plan */
        $plan = $membership['plan'];

        return [
            'plan' => [
                'name' => (string) $plan['name'],
                'price' => (int) $plan['price'],
                'usage_count_per_period' => (int) $plan['usage_count_per_period'],
                'billing_interval' => (string) $plan['billing_interval'],
                'is_active' => (bool) $plan['is_active'],
            ],
            'status' => (string) $membership['status'],
            'status_label' => (string) $membership['status_label'],
            'current_period_start' => $membership['current_period_start'],
            'current_period_end' => $membership['current_period_end'],
            'cancel_at_period_end' => (bool) $membership['cancel_at_period_end'],
            'available' => (int) $membership['available'],
            ...(array_key_exists('grace_until', $membership)
                ? ['grace_until' => $membership['grace_until']]
                : []),
        ];
    }

    private static function reservationStatusLabel(ReservationStatus $status): string
    {
        return match ($status) {
            ReservationStatus::PendingPayment => __('messages.query_labels.reservation_pending_payment'),
            ReservationStatus::PendingExternalSync => __('messages.query_labels.reservation_pending_external_sync'),
            ReservationStatus::Confirmed => __('messages.query_labels.reservation_confirmed'),
            ReservationStatus::Completed => __('messages.query_labels.reservation_completed_short'),
            ReservationStatus::NoShow => __('messages.query_labels.reservation_no_show'),
            ReservationStatus::Canceled => __('messages.query_labels.reservation_canceled'),
            ReservationStatus::Expired => __('messages.customer_dashboard.reservation_expired'),
        };
    }

    private static function paymentStatusLabel(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => __('messages.query_labels.payment_pending'),
            PaymentStatus::Authorized => __('messages.query_labels.payment_authorized'),
            PaymentStatus::Succeeded => __('messages.query_labels.payment_succeeded'),
            PaymentStatus::Voided => __('messages.query_labels.payment_authorization_voided'),
            PaymentStatus::Failed => __('messages.query_labels.payment_failed'),
            PaymentStatus::Refunded => __('messages.customer_dashboard.payment_refunded'),
            PaymentStatus::PartiallyRefunded => __('messages.query_labels.payment_partially_refunded'),
        };
    }

    private static function paymentKindLabel(PaymentKind $kind): string
    {
        return match ($kind) {
            PaymentKind::Single => __('messages.query_labels.kind_reservation'),
            PaymentKind::SingleAddon => __('messages.customer_dashboard.kind_single_addon'),
            PaymentKind::TicketPurchase => __('messages.query_labels.kind_ticket_purchase'),
            PaymentKind::MembershipInvoice => __('messages.query_labels.kind_membership_fee'),
        };
    }
}
