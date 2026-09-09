<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Enums\Membership\MembershipStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipUsageTransaction;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F-02: 利用権申込の 3DS/SCA 完了フロー（Fake gateway）。
 * - pending を「成功」と表示しない
 * - client へ渡すのは publishable key と PaymentIntent client_secret のみ
 * - 二重申込で subscription を二重作成しない
 * - 認証完了はサーバーが Stripe retrieve で判定する（client 申告を信用しない）
 * - ownership: 他人の membership を操作できない
 */
final class MembershipScaCheckoutTest extends TestCase
{
    use DatabaseMigrations;

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->seed(RolePermissionSeeder::class);
        config()->set('stripe.key', 'pk_test_fake_publishable');
        config()->set('stripe.secret', 'sk_test_fake_secret_value');
        $this->gateway = app(FakeMembershipStripeGateway::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        return $customer;
    }

    private function plan(): MembershipPlan
    {
        return MembershipPlan::factory()->create([
            'is_active' => true,
            'usage_count_per_period' => 4,
            'price' => 24000,
            'stripe_price_id' => 'price_test_1',
        ]);
    }

    // ---- SCA 必要 ----

    public function test_subscribe_with_sca_required_redirects_to_confirm_and_keeps_pending(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation('pi_secret_abc');

        $this->actingAs($customer->user)
            ->post('/mypage/membership/subscribe', [
                'membership_plan_id' => $plan->id,
                'payment_method_id' => 'pm_test_card',
            ])
            ->assertRedirect(route('mypage.membership.confirm'))
            ->assertSessionMissing('success'); // pending を成功と表示しない

        $membership = Membership::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertSame(MembershipStatus::Pending, $membership->status);
        $this->assertNotNull($membership->stripe_subscription_id);
        $this->assertSame(0, MembershipUsageTransaction::query()->where('type', 'GRANT')->count());
    }

    public function test_confirm_page_exposes_only_client_secret_and_publishable_key(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation('pi_secret_xyz');
        $this->actingAs($customer->user)->post('/mypage/membership/subscribe', [
            'membership_plan_id' => $plan->id,
            'payment_method_id' => 'pm_test_card',
        ]);

        $response = $this->actingAs($customer->user)->get('/mypage/membership/confirm')->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Customer/Membership/Confirm')
            ->where('stripe.client_secret', 'pi_secret_xyz')
            ->where('stripe.publishable_key', 'pk_test_fake_publishable')
            ->where('plan.name', $plan->name));

        $html = $response->getContent();
        $this->assertStringNotContainsString('sk_test_fake_secret_value', $html);
        $this->assertStringNotContainsString('sk_', $html);
        $this->assertStringNotContainsString('stripe_subscription_id', $html);
    }

    public function test_sync_after_confirmation_activates_membership(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation();
        $this->actingAs($customer->user)->post('/mypage/membership/subscribe', [
            'membership_plan_id' => $plan->id,
            'payment_method_id' => 'pm_test_card',
        ]);
        $membership = Membership::query()->where('customer_id', $customer->user_id)->firstOrFail();

        // client 側 Stripe.js 認証完了を模す
        $this->gateway->completeConfirmation((string) $membership->stripe_subscription_id);

        $this->actingAs($customer->user)
            ->post('/mypage/membership/payment/sync')
            ->assertRedirect(route('mypage.membership.show'))
            ->assertSessionHas('success');

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
    }

    public function test_sync_before_confirmation_does_not_report_success(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation();
        $this->actingAs($customer->user)->post('/mypage/membership/subscribe', [
            'membership_plan_id' => $plan->id,
            'payment_method_id' => 'pm_test_card',
        ]);

        // 認証未完了のまま sync
        $this->actingAs($customer->user)
            ->post('/mypage/membership/payment/sync')
            ->assertRedirect(route('mypage.membership.show'))
            ->assertSessionMissing('success')
            ->assertSessionHas('info');

        $membership = Membership::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertSame(MembershipStatus::Pending, $membership->status);
    }

    // ---- SCA 不要（通常カード）----

    public function test_subscribe_without_sca_completes_immediately(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        // Fake の既定は create → active

        $this->actingAs($customer->user)
            ->post('/mypage/membership/subscribe', [
                'membership_plan_id' => $plan->id,
                'payment_method_id' => 'pm_test_card',
            ])
            ->assertRedirect(route('mypage.membership.show'))
            ->assertSessionHas('success');

        $this->assertSame(
            MembershipStatus::Active,
            Membership::query()->where('customer_id', $customer->user_id)->firstOrFail()->status,
        );
    }

    // ---- 二重申込 ----

    public function test_double_submit_does_not_create_two_subscriptions(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation();

        $payload = ['membership_plan_id' => $plan->id, 'payment_method_id' => 'pm_test_card'];
        $this->actingAs($customer->user)->post('/mypage/membership/subscribe', $payload)
            ->assertRedirect(route('mypage.membership.confirm'));
        // 2 回目（ブラウザ二重送信相当）
        $this->actingAs($customer->user)->post('/mypage/membership/subscribe', $payload)
            ->assertRedirect(route('mypage.membership.confirm'));

        $this->assertSame(1, Membership::query()->where('customer_id', $customer->user_id)->count());
        $this->assertSame(1, $this->gateway->callCount('create_subscription'));
    }

    // ---- ownership ----

    public function test_customer_cannot_sync_or_view_confirm_of_another_customer(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation();
        $this->actingAs($a->user)->post('/mypage/membership/subscribe', [
            'membership_plan_id' => $plan->id,
            'payment_method_id' => 'pm_test_card',
        ]);
        $aMembership = Membership::query()->where('customer_id', $a->user_id)->firstOrFail();
        $this->gateway->completeConfirmation((string) $aMembership->stripe_subscription_id);

        // B は自分の pending が無いので confirm は show へ、sync も自分の membership が無いので何も起きない
        $this->actingAs($b->user)->get('/mypage/membership/confirm')
            ->assertRedirect(route('mypage.membership.show'));
        $this->actingAs($b->user)->post('/mypage/membership/payment/sync')
            ->assertRedirect(route('mypage.membership.show'));

        // A の membership は B の操作で active にならない
        $this->assertSame(MembershipStatus::Pending, $aMembership->fresh()->status);
        // client がボディで membership/subscription/customer id を渡しても無視される（route param 無し）
        $this->actingAs($b->user)->post('/mypage/membership/payment/sync', [
            'membership_id' => $aMembership->id,
            'subscription_id' => $aMembership->stripe_subscription_id,
            'customer_id' => $a->user_id,
        ])->assertRedirect(route('mypage.membership.show'));
        $this->assertSame(MembershipStatus::Pending, $aMembership->fresh()->status);
    }

    // ---- webhook: invoice.paid after confirmation grants once ----

    public function test_invoice_paid_after_confirmation_grants_once(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $this->gateway->nextCreateRequiresConfirmation();
        $this->actingAs($customer->user)->post('/mypage/membership/subscribe', [
            'membership_plan_id' => $plan->id,
            'payment_method_id' => 'pm_test_card',
        ]);
        $membership = Membership::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $subId = (string) $membership->stripe_subscription_id;
        $this->gateway->completeConfirmation($subId);
        $this->gateway->setSubscription($subId, new SubscriptionResult(
            stripeSubscriptionId: $subId, stripeStatus: 'active', cancelAtPeriodEnd: false,
            currentPeriodStart: now()->startOfMonth()->toDateString(),
            currentPeriodEnd: now()->startOfMonth()->addMonth()->toDateString(),
            latestInvoiceStatus: 'paid',
        ));

        config()->set('stripe.webhook_secret', 'whsec_test_sca');
        $event = ['id' => 'evt_sca_paid', 'type' => 'invoice.paid', 'created' => time(),
            'data' => ['object' => ['object' => 'invoice', 'id' => 'in_sca', 'subscription' => $subId,
                'amount_paid' => 24000, 'amount_due' => 24000]]];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$payload, 'whsec_test_sca');
        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$ts},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)->where('type', 'GRANT')->count());
    }
}
