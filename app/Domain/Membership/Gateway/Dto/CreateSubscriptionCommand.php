<?php

declare(strict_types=1);

namespace App\Domain\Membership\Gateway\Dto;

final readonly class CreateSubscriptionCommand
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public int $customerUserId,
        public string $stripeCustomerId,
        public string $priceId,
        public string $membershipOperationId,
        public string $idempotencyKey,
        public ?string $paymentMethodId = null,
        public array $metadata = [],
    ) {}
}
