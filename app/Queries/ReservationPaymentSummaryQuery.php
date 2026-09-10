<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Payment\ReservationAdjustmentService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;

final class ReservationPaymentSummaryQuery
{
    public function __construct(
        private readonly ReservationAdjustmentService $adjustments,
    ) {}

    /** @return array<string, mixed> */
    public function get(Reservation $reservation): array
    {
        $reservation->loadMissing(['service:id,price', 'payments.refunds']);
        $payments = $reservation->payments->sortByDesc('id')->values();
        $captured = $payments->filter(static fn (Payment $payment): bool => in_array(
            $payment->status,
            [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded],
            true,
        ));
        $original = $this->adjustments->originalPayment($reservation);
        $netReceived = $this->adjustments->netReceived($reservation);
        $finalAmount = $reservation->final_amount === null ? null : (int) $reservation->final_amount;
        $inFlight = $this->adjustments->inFlightAddon($reservation);

        return [
            'original_amount' => $original === null
                ? (int) $reservation->service->price
                : (int) $original->amount,
            'captured_total' => $captured->sum(
                static fn (Payment $payment): int => (int) $payment->amount,
            ),
            'refunded_total' => $payments->sum(static fn (Payment $payment): int => $payment->refunds
                ->filter(static fn (PaymentRefund $refund): bool => $refund->status === RefundStatus::Succeeded)
                ->sum(static fn (PaymentRefund $refund): int => (int) $refund->amount)),
            'net_received' => $netReceived,
            'final_amount' => $finalAmount,
            'delta' => $finalAmount === null ? 0 : $finalAmount - $netReceived,
            'in_flight_addon' => $inFlight === null ? null : [
                'id' => (int) $inFlight->id,
                'amount' => (int) $inFlight->amount,
                'status' => $inFlight->status->value,
            ],
            'payments' => $payments->map(fn (Payment $payment): array => [
                'id' => (int) $payment->id,
                'kind' => $payment->kind->value,
                'kind_label' => self::kindLabel($payment->kind),
                'amount' => (int) $payment->amount,
                'status' => $payment->status->value,
                'status_label' => self::statusLabel($payment->status),
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'stripe_charge_id' => $payment->stripe_charge_id,
                'refunded_amount' => (int) $payment->refunded_amount,
                'needs_attention' => (bool) $payment->needs_attention,
                'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
                'refunds' => $payment->refunds->sortByDesc('id')->values()
                    ->map(static fn (PaymentRefund $refund): array => [
                        'amount' => (int) $refund->amount,
                        'status' => $refund->status->value,
                        'reason' => (string) $refund->reason,
                        'created_at' => $refund->created_at?->format('Y-m-d H:i:s'),
                    ])->all(),
            ])->all(),
        ];
    }

    public static function kindLabel(PaymentKind $kind): string
    {
        return match ($kind) {
            PaymentKind::Single => '予約決済',
            PaymentKind::SingleAddon => '追加のお支払い',
            PaymentKind::TicketPurchase => '回数券購入',
            PaymentKind::MembershipInvoice => '会員利用料',
        };
    }

    public static function statusLabel(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => '手続き中',
            PaymentStatus::Authorized => '与信済み（未確定）',
            PaymentStatus::Succeeded => '支払い済み',
            PaymentStatus::Voided => '取消済み',
            PaymentStatus::Failed => '失敗',
            PaymentStatus::PartiallyRefunded => '一部返金',
            PaymentStatus::Refunded => '返金済み',
        };
    }
}
