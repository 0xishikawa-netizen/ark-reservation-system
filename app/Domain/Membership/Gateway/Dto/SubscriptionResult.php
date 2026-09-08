<?php

declare(strict_types=1);

namespace App\Domain\Membership\Gateway\Dto;

/**
 * Stripe subscription の「現在オブジェクト」の要約。イベントの中身ではなくこれで前進判断する。
 */
final readonly class SubscriptionResult
{
    public function __construct(
        public string $stripeSubscriptionId,
        public string $stripeStatus, // active / trialing / past_due / unpaid / incomplete / incomplete_expired / canceled / paused
        public bool $cancelAtPeriodEnd,
        public ?string $currentPeriodStart = null, // Y-m-d
        public ?string $currentPeriodEnd = null,   // Y-m-d
        public ?string $latestInvoiceStatus = null, // paid / open / uncollectible / void
        public ?string $latestInvoiceId = null,
        public ?string $nextPaymentAttempt = null,  // Y-m-d H:i:s（past_due 時の Stripe retry 予定）
    ) {}
}
