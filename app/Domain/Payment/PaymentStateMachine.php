<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Enums\Payment\PaymentStatus;
use App\Support\StateMachine\StateMachine;

class PaymentStateMachine extends StateMachine
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
            PaymentStatus::Pending->value => [
                PaymentStatus::Authorized->value,
                PaymentStatus::Failed->value,
                PaymentStatus::Voided->value,
            ],
            PaymentStatus::Authorized->value => [
                PaymentStatus::Succeeded->value,
                PaymentStatus::Voided->value,
                PaymentStatus::Failed->value,
            ],
            PaymentStatus::Succeeded->value => [
                PaymentStatus::Refunded->value,
                PaymentStatus::PartiallyRefunded->value,
            ],
            PaymentStatus::PartiallyRefunded->value => [
                PaymentStatus::Refunded->value,
            ],
            PaymentStatus::Voided->value => [],
            PaymentStatus::Failed->value => [],
            PaymentStatus::Refunded->value => [],
        ];
    }
}
