<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway\Dto;

final readonly class PaymentIntentResult
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public int $amountCapturable,
        public int $amountReceived,
        public string $currency,
        public ?string $chargeId = null,
        public ?string $clientSecret = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public int $refundedAmount = 0,
    ) {}
}
