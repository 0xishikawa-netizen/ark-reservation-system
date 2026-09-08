<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway\Dto;

final readonly class CreatePaymentIntentCommand
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $captureMethod,
        public string $idempotencyKey,
        public ?string $customerId = null,
        public array $metadata = [],
    ) {}
}
