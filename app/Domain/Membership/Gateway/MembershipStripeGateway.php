<?php

declare(strict_types=1);

namespace App\Domain\Membership\Gateway;

use App\Domain\Membership\Gateway\Dto\CreateSubscriptionCommand;
use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Models\Customer;

/**
 * Stripe subscription 通信の抽象（課金契約の管理のみ）。
 * 業務状態・残回数・予約可否・no_show 判断はここに置かない。
 */
interface MembershipStripeGateway
{
    /** billable の Stripe customer を確保し、その id を返す（既存 stripe_customer_id を尊重）。 */
    public function ensureCustomer(Customer $customer): string;

    public function createSubscription(CreateSubscriptionCommand $command): SubscriptionResult;

    public function retrieveSubscription(string $subscriptionId): SubscriptionResult;

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $value, string $idempotencyKey): SubscriptionResult;

    public function cancelNow(string $subscriptionId, string $idempotencyKey): SubscriptionResult;
}
