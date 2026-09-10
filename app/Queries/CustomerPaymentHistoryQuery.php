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
            'pending' => 'お支払い手続き中',
            'authorized' => '予約確保中（確定処理中）',
            'succeeded', 'paid' => '支払い完了',
            'failed' => 'お支払いに失敗',
            'voided' => '取消済み',
            'refunded' => '返金済み',
            'partially_refunded' => '一部返金済み',
            default => 'ー',
        };
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'single' => 'カード決済（予約）',
            'single_addon' => '追加のお支払い',
            'membership_invoice' => '利用権のお支払い',
            'ticket_purchase' => '回数券のご購入',
            default => 'お支払い',
        };
    }
}
