<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Payment;

/**
 * Customer Portal のダッシュボード集約（read のみ・すべて customer scope）。
 * 既存 Query を再利用し、新しい業務ロジックは持たない。
 * Stripe internal（payment intent id / charge id / needs_attention / 生 failure）は返さない。
 */
final class CustomerDashboardQuery
{
    public function __construct(
        private readonly CustomerReservationListQuery $reservations,
        private readonly CustomerTicketQuery $tickets,
        private readonly CustomerMembershipQuery $membership,
    ) {}

    /** @return array<string, mixed> */
    public function for(int $customerId): array
    {
        $reservations = $this->reservations->get($customerId);
        $upcoming = $reservations['upcoming'];
        $next = $upcoming[0] ?? null;

        $membership = $this->membership->currentFor($customerId);

        $wallets = $this->tickets->walletsFor($customerId);
        $activeWallets = array_values(array_filter(
            $wallets,
            static fn (array $w): bool => $w['status'] === 'active',
        ));
        $totalAvailable = array_sum(array_map(static fn (array $w): int => (int) $w['available'], $activeWallets));
        $nearestExpires = null;
        foreach ($activeWallets as $w) {
            if ($w['available'] > 0) {
                $nearestExpires = $w['expires_at'];
                break;
            }
        }

        $payment = Payment::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->first();

        return [
            'next_reservation' => $next === null ? null : [
                'id' => $next['id'],
                'service_name' => $next['service_name'],
                'staff_name' => $next['staff_name'],
                'starts_at' => $next['starts_at'],
                'status' => $next['status'],
                'status_label' => self::reservationStatusLabel($next['status']),
            ],
            'upcoming_count' => count($upcoming),
            'membership' => $membership === null ? null : [
                'status' => $membership['status'],
                'status_label' => $membership['status_label'],
                'current_period_end' => $membership['current_period_end'],
                'available' => $membership['available'],
                'cancel_at_period_end' => $membership['cancel_at_period_end'],
            ],
            'tickets' => [
                'total_available' => $totalAvailable,
                'nearest_expires_at' => $nearestExpires,
                'wallet_count' => count($activeWallets),
            ],
            'recent_payment' => $payment === null ? null : [
                'amount' => (int) $payment->amount,
                'status_label' => self::paymentStatusLabel($payment->status->value),
                'kind_label' => self::paymentKindLabel($payment->kind->value),
                'created_at' => $payment->created_at->toDateTimeString(),
            ],
            'attention' => $this->attention($upcoming, $membership),
        ];
    }

    /**
     * @param  list<array{status: string}>  $upcoming
     * @param  array<string, mixed>|null  $membership
     * @return list<string>
     */
    private function attention(array $upcoming, ?array $membership): array
    {
        $messages = [];

        foreach ($upcoming as $reservation) {
            if ($reservation['status'] === ReservationStatus::PendingPayment->value) {
                $messages[] = __('messages.customer_dashboard.attention_pending_reservation');
                break;
            }
        }

        if ($membership !== null) {
            if ($membership['status'] === 'grace') {
                $messages[] = __('messages.customer_dashboard.attention_membership_grace');
            } elseif ($membership['status'] === 'paused') {
                $messages[] = __('messages.customer_dashboard.attention_membership_paused');
            } elseif ($membership['status'] === 'pending') {
                $messages[] = __('messages.customer_dashboard.attention_membership_pending');
            }
        }

        return array_slice($messages, 0, 3);
    }

    private static function reservationStatusLabel(string $status): string
    {
        return match ($status) {
            'pending_payment' => __('messages.customer_dashboard.payment_pending'),
            'pending_external_sync' => __('messages.customer_dashboard.reservation_pending_external_sync'),
            'confirmed' => __('messages.customer_dashboard.reservation_confirmed'),
            'completed' => __('messages.customer_dashboard.reservation_completed'),
            'no_show' => __('messages.customer_dashboard.reservation_no_show'),
            'canceled' => __('messages.customer_dashboard.reservation_canceled'),
            'expired' => __('messages.customer_dashboard.reservation_expired'),
            default => $status,
        };
    }

    private static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('messages.customer_dashboard.payment_pending'),
            'authorized' => __('messages.customer_dashboard.payment_authorized'),
            'succeeded', 'paid' => __('messages.customer_dashboard.payment_succeeded'),
            'failed' => __('messages.customer_dashboard.payment_failed'),
            'voided' => __('messages.customer_dashboard.payment_voided'),
            'refunded' => __('messages.customer_dashboard.payment_refunded'),
            'partially_refunded' => __('messages.customer_dashboard.payment_partially_refunded'),
            default => __('messages.common.dash'),
        };
    }

    private static function paymentKindLabel(string $kind): string
    {
        return match ($kind) {
            'single' => __('messages.customer_dashboard.kind_single'),
            'single_addon' => __('messages.customer_dashboard.kind_single_addon'),
            'membership_invoice' => __('messages.customer_dashboard.kind_membership_invoice'),
            'ticket_purchase' => __('messages.customer_dashboard.kind_ticket_purchase'),
            default => __('messages.customer_dashboard.kind_default'),
        };
    }
}
