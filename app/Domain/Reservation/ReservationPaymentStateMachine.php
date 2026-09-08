<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Enums\Reservation\PaymentStatus;
use App\Support\StateMachine\StateMachine;

/**
 * reservations.payment_status の遷移（PLAN §7 / DB_SCHEMA §5）。
 *
 * 巻き戻し遷移は定義しない。到着が遅れた古い Stripe イベントで
 * paid → authorized のように状態が後退することを構造的に防ぐ。
 */
class ReservationPaymentStateMachine extends StateMachine
{
    public function assertCanTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assert($from->value, $to->value);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            PaymentStatus::Unpaid->value => [
                PaymentStatus::PendingPayment->value,
            ],
            PaymentStatus::PendingPayment->value => [
                PaymentStatus::Authorized->value,
                PaymentStatus::Voided->value,
                PaymentStatus::Failed->value,
            ],
            PaymentStatus::Authorized->value => [
                PaymentStatus::Paid->value,
                PaymentStatus::Voided->value,
                PaymentStatus::Failed->value,
            ],
            PaymentStatus::Paid->value => [
                PaymentStatus::Refunded->value,
                PaymentStatus::PartiallyRefunded->value,
            ],
            PaymentStatus::PartiallyRefunded->value => [
                PaymentStatus::Refunded->value,
            ],
            // failed のみ再試行のため unpaid へ戻せる（新しい決済試行の開始点）。
            PaymentStatus::Failed->value => [
                PaymentStatus::Unpaid->value,
            ],
            PaymentStatus::Voided->value => [],
            PaymentStatus::Refunded->value => [],
        ];
    }
}
