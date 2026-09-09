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
        // 初回 invoice の PaymentIntent client_secret（3DS/SCA 完了に必要・ブラウザ用）。
        // dahlia: latest_invoice.confirmation_secret.client_secret / 旧: latest_invoice.payment_intent.client_secret。
        // secret key ではない。値そのものはログに出さない。
        public ?string $clientSecret = null,
    ) {}

    /**
     * 顧客側の追加認証（3DS/SCA）が必要か。
     * default_incomplete で作成した subscription が incomplete のまま＝初回 invoice 未確定。
     */
    public function requiresConfirmation(): bool
    {
        return $this->stripeStatus === 'incomplete' && $this->clientSecret !== null;
    }
}
