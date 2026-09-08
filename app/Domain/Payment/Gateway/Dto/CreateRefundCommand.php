<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway\Dto;

final readonly class CreateRefundCommand
{
    public function __construct(
        public string $paymentIntentId,
        public int $amount,
        public string $idempotencyKey,
    ) {}
}
