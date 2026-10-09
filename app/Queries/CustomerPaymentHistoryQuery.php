<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Payment;

/**
 * 顧客向けの支払い履歴（自分の payment のみ）。
 *
 * 顧客に出さない：Stripe internal ID（payment_operation_id / stripe_payment_intent_id / stripe_charge_id）/
 * 生 failure（failure_code / failure_message）/ needs_attention / last_synced_at / provider / capture_method。
 * 未確定状態（pending / authorized）を「成功」と表示しないラベルにする。
 */
final class CustomerPaymentHistoryQuery
{
    /** @return list<array<string, mixed>> */
    public function for(int $customerId, int $limit = 100): array
    {
        return Payment::query()
            ->where('customer_id', $customerId)
            ->with(['reservation:id,starts_at,service_id', 'reservation.service:id,name'])
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(static function (Payment $payment): array {
                $reservation = $payment->reservation;

                return [
                    'id' => (int) $payment->id,
                    'amount' => (int) $payment->amount,
                    'currency' => (string) $payment->currency,
                    'refunded_amount' => (int) $payment->refunded_amount,
                    'status_label' => self::statusLabel($payment->status->value),
                    'kind_label' => self::kindLabel($payment->kind->value),
                    'created_at' => $payment->created_at->toDateTimeString(),
                    'paid_at' => $payment->paid_at?->toDateTimeString(),
                    'reservation' => $reservation === null ? null : [
                        'id' => (int) $reservation->id,
                        'service_name' => $reservation->service?->name,
                        'starts_at' => $reservation->starts_at?->format('Y-m-d H:i'),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('messages.customer_dashboard.payment_pending'),
            'authorized' => __('messages.query_labels.customer_payment_authorized'),
            'succeeded', 'paid' => __('messages.customer_dashboard.payment_succeeded'),
            'failed' => __('messages.customer_dashboard.payment_failed'),
            'voided' => __('messages.customer_dashboard.payment_voided'),
            'refunded' => __('messages.customer_dashboard.payment_refunded'),
            'partially_refunded' => __('messages.customer_dashboard.payment_partially_refunded'),
            default => __('messages.common.dash'),
        };
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'single' => __('messages.query_labels.customer_kind_single'),
            'single_addon' => __('messages.customer_dashboard.kind_single_addon'),
            'membership_invoice' => __('messages.customer_dashboard.kind_membership_invoice'),
            'ticket_purchase' => __('messages.customer_dashboard.kind_ticket_purchase'),
            default => __('messages.customer_dashboard.kind_default'),
        };
    }
}
