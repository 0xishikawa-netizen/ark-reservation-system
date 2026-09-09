<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Membership\Gateway\Dto\MembershipCheckoutResult;
use App\Models\Customer;
use App\Models\MembershipPlan;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 顧客の利用権申込を束ねる最小の明示オーケストレーション（巨大 Saga フレームワークにしない）。
 * 現状は subscription 作成の 1 経路のみ。将来ステップが増えたらここに追記する。
 */
final class MembershipCheckoutSaga
{
    public function __construct(
        private readonly MembershipSubscriptionService $subscriptions,
    ) {}

    public function execute(
        Customer $customer,
        MembershipPlan $plan,
        ?string $paymentMethodId = null,
        ?Authenticatable $actor = null,
    ): MembershipCheckoutResult {
        return $this->subscriptions->startSubscription($customer, $plan, $paymentMethodId, $actor);
    }
}
