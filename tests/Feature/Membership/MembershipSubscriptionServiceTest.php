<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\Gateway\MembershipStripeGateway;
use App\Domain\Membership\MembershipIdempotencyKeyFactory;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Enums\Membership\MembershipStatus;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MembershipSubscriptionServiceTest extends TestCase
{
    // DatabaseMigrations: FakeMembershipStripeGateway の「DB transaction の外で Stripe を呼ぶ」検査を
    // 実効化するため（RefreshDatabase はテスト全体を transaction で包むため level 0 にならない）。
    use DatabaseMigrations;

    private MembershipSubscriptionService $service;

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 09:00:00');
        $this->gateway = app(FakeMembershipStripeGateway::class);
        $this->service = app(MembershipSubscriptionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(bool $active = true): MembershipPlan
    {
        return MembershipPlan::factory()->create(['is_active' => $active, 'usage_count_per_period' => 4]);
    }

    public function test_start_subscription_creates_and_activates_membership(): void
    {
        $customer = Customer::factory()->create(['stripe_customer_id' => null]);
        $plan = $this->plan();

        $membership = $this->service->startSubscription($customer, $plan);

        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertNotNull($membership->stripe_subscription_id);
        $this->assertNull($membership->pending_operation);
        $this->assertFalse($membership->needs_attention);
        $this->assertNotNull($membership->started_at);
        $this->assertSame('cus_fake_'.$customer->user_id, $customer->fresh()->stripe_customer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.activated']);

        // Stripe HTTP はすべて DB transaction の外で実行された。
        foreach ($this->gateway->calls() as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
    }

    public function test_subscription_create_retry_with_same_operation_id_does_not_double(): void
    {
        $customer = Customer::factory()->create();
        $membership = $this->service->startSubscription($customer, $this->plan());
        $subId = $membership->stripe_subscription_id;

        // 論理操作の retry: 永続化済み operation ID から同じ Idempotency-Key を導出して create を再実行。
        $key = app(MembershipIdempotencyKeyFactory::class)->subscriptionCreate($membership);
        $again = $this->gateway->createSubscription(new \App\Domain\Membership\Gateway\Dto\CreateSubscriptionCommand(
            customerUserId: (int) $customer->user_id,
            stripeCustomerId: (string) $customer->fresh()->stripe_customer_id,
            priceId: 'price_x',
            membershipOperationId: (string) $membership->membership_operation_id,
            idempotencyKey: $key,
        ));

        $this->assertSame($subId, $again->stripeSubscriptionId, '同一 key は同じ subscription を返す');
        $this->assertSame(1, Membership::query()->count());
    }

    public function test_ambiguous_create_failure_keeps_membership_pending_and_flags_attention(): void
    {
        $customer = Customer::factory()->create();
        $this->gateway->failNextCreateWith(new PaymentGatewayTimeoutException('timeout'));

        try {
            $this->service->startSubscription($customer, $this->plan());
            $this->fail('曖昧失敗で例外にならなかった');
        } catch (PaymentGatewayTimeoutException) {
            // ok
        }

        $membership = Membership::query()->firstOrFail();
        $this->assertSame(MembershipStatus::Pending, $membership->status);
        $this->assertTrue($membership->needs_attention);
        $this->assertSame('create', $membership->pending_operation);
        $this->assertNull($membership->stripe_subscription_id);
    }

    public function test_only_one_non_canceled_membership_per_customer(): void
    {
        $customer = Customer::factory()->create();
        $this->service->startSubscription($customer, $this->plan());

        $this->expectException(ValidationException::class);
        $this->service->startSubscription($customer, $this->plan());
    }

    public function test_inactive_plan_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        $this->expectException(ValidationException::class);
        $this->service->startSubscription($customer, $this->plan(active: false));
    }

    public function test_request_and_resume_cancel_at_period_end(): void
    {
        $customer = Customer::factory()->create();
        $membership = $this->service->startSubscription($customer, $this->plan());

        $this->service->requestCancelAtPeriodEnd($membership);
        $membership->refresh();
        $this->assertSame(MembershipStatus::Canceling, $membership->status);
        $this->assertTrue($membership->cancel_at_period_end);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.cancel_requested']);

        $this->service->resumeCancelAtPeriodEnd($membership);
        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertFalse($membership->cancel_at_period_end);
    }

    public function test_cancel_now_requires_reason_and_terminates(): void
    {
        $customer = Customer::factory()->create();
        $membership = $this->service->startSubscription($customer, $this->plan());

        try {
            $this->service->cancelNow($membership, '  ');
            $this->fail('reason 空が拒否されなかった');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->service->cancelNow($membership, '不正利用のため');
        $membership->refresh();
        $this->assertSame(MembershipStatus::Canceled, $membership->status);
        $this->assertNotNull($membership->canceled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.canceled']);
    }

    public function test_sync_from_stripe_advances_forward_only_and_never_rewinds_from_canceled(): void
    {
        $customer = Customer::factory()->create();
        $membership = $this->service->startSubscription($customer, $this->plan());
        $subId = (string) $membership->stripe_subscription_id;

        // past_due → grace
        $this->gateway->setSubscription($subId, new SubscriptionResult($subId, 'past_due', false, '2026-09-01', '2026-10-01', 'open'));
        $this->service->syncFromStripe($membership);
        $this->assertSame(MembershipStatus::Grace, $membership->fresh()->status);

        // canceled → canceled
        $this->gateway->setSubscription($subId, new SubscriptionResult($subId, 'canceled', false, '2026-09-01', '2026-10-01', 'void'));
        $this->service->syncFromStripe($membership);
        $this->assertSame(MembershipStatus::Canceled, $membership->fresh()->status);

        // 遅れて届いた active → canceled のまま巻き戻らない
        $this->gateway->setSubscription($subId, new SubscriptionResult($subId, 'active', false, '2026-10-01', '2026-11-01', 'paid'));
        $this->service->syncFromStripe($membership);
        $this->assertSame(MembershipStatus::Canceled, $membership->fresh()->status);
    }
}
