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
                $messages[] = 'お支払い手続き中のご予約があります。';
                break;
            }
        }

        if ($membership !== null) {
            if ($membership['status'] === 'grace') {
                $messages[] = '利用権のお支払いを確認中です。ご利用は継続できます。';
            } elseif ($membership['status'] === 'paused') {
                $messages[] = '利用権が一時停止中です。お支払い方法をご確認ください。';
            } elseif ($membership['status'] === 'pending') {
                $messages[] = '利用権のお申し込みを確認中です。';
            }
        }

        return array_slice($messages, 0, 3);
    }

    private static function reservationStatusLabel(string $status): string
    {
        return match ($status) {
            'pending_payment' => 'お支払い手続き中',
            'pending_external_sync' => '確定処理中',
            'confirmed' => '確定',
            'completed' => '来店済み',
            'no_show' => '来店なし',
            'canceled' => 'キャンセル済み',
            'expired' => '期限切れ',
            default => $status,
        };
    }

    private static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'お支払い手続き中',
            'authorized' => '予約確保中',
            'succeeded', 'paid' => '支払い完了',
            'failed' => 'お支払いに失敗',
            'voided' => '取消済み',
            'refunded' => '返金済み',
            'partially_refunded' => '一部返金済み',
            default => 'ー',
        };
    }

    private static function paymentKindLabel(string $kind): string
    {
        return match ($kind) {
            'single' => 'カード決済',
            'single_addon' => '追加のお支払い',
            'membership_invoice' => '利用権のお支払い',
            'ticket_purchase' => '回数券のご購入',
            default => 'お支払い',
        };
    }
}
