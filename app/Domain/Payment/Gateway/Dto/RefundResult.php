<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway\Dto;

final readonly class RefundResult
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $paymentIntentId = null,
        public ?string $chargeId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}
