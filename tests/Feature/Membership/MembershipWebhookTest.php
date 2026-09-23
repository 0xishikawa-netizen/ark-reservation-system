<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Payment\WebhookEventStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipUsageTransaction;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MembershipWebhookTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'whsec_test_membership_secret';

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 09:00:00');
        config()->set('stripe.webhook_secret', self::SECRET);
        $this->gateway = app(FakeMembershipStripeGateway::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function membership(string $status = 'active', int $usagePerPeriod = 4): Membership
    {
        $customer = Customer::factory()->create(['stripe_customer_id' => 'cus_x']);
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => $usagePerPeriod]);

        return Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'stripe_subscription_id' => 'sub_wh_1',
            'status' => $status,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
    }

    private function setStripe(string $subId, string $stripeStatus, bool $cancelAtPeriodEnd = false, ?string $nextAttempt = null): void
    {
        $this->gateway->setSubscription($subId, new SubscriptionResult(
            stripeSubscriptionId: $subId,
            stripeStatus: $stripeStatus,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            currentPeriodStart: '2026-09-01',
            currentPeriodEnd: '2026-10-01',
            latestInvoiceStatus: $stripeStatus === 'active' ? 'paid' : 'open',
            nextPaymentAttempt: $nextAttempt,
        ));
    }

    /** @param array<string, mixed> $object */
    private function postEvent(string $id, string $type, array $object): TestResponse
    {
        $event = ['id' => $id, 'type' => $type, 'api_version' => '2024-06-20', 'created' => time(), 'data' => ['object' => $object]];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$payload, self::SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$ts},v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function invoiceObject(string $id, string $subId, int $amountPaid = 24000): array
    {
        return ['object' => 'invoice', 'id' => $id, 'subscription' => $subId, 'amount_paid' => $amountPaid, 'amount_due' => $amountPaid];
    }

    public function test_invoice_paid_records_payment_activates_and_grants_once(): void
    {
        $m = $this->membership('active', usagePerPeriod: 4);
        $this->setStripe('sub_wh_1', 'active');

        $this->postEvent('evt_ip_1', 'invoice.paid', $this->invoiceObject('in_100', 'sub_wh_1'))->assertOk();

        $m->refresh();
        $this->assertSame(MembershipStatus::Active, $m->status);
        $this->assertSame(4, $m->period_available);
        $this->assertDatabaseHas('payments', [
            'payment_operation_id' => 'inv:in_100',
            'kind' => 'membership_invoice',
            'capture_method' => 'automatic',
            'status' => 'succeeded',
        ]);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', 'GRANT')->count());
        $this->assertSame(WebhookEventStatus::Processed, WebhookEvent::query()->where('stripe_event_id', 'evt_ip_1')->first()->status);
    }

    public function test_duplicate_invoice_paid_events_do_not_double_grant(): void
    {
        $m = $this->membership('active', usagePerPeriod: 4);
        $this->setStripe('sub_wh_1', 'active');

        // 同一 event.id の再送 → duplicate。
        $this->postEvent('evt_ip_2', 'invoice.paid', $this->invoiceObject('in_200', 'sub_wh_1'))->assertOk();
        $this->postEvent('evt_ip_2', 'invoice.paid', $this->invoiceObject('in_200', 'sub_wh_1'))->assertOk();
        // 別 event.id・同一 invoice（Stripe が別イベントで再通知） → GRANT は当期 1 回のみ。
        $this->postEvent('evt_ip_3', 'invoice.paid', $this->invoiceObject('in_200', 'sub_wh_1'))->assertOk();

        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', 'GRANT')->count());
        $this->assertSame(1, Payment::query()->where('payment_operation_id', 'inv:in_200')->count());
        $this->assertSame(4, $m->refresh()->period_available);
    }

    public function test_payment_failed_moves_to_grace_with_grace_until_and_stays_bookable(): void
    {
        $m = $this->membership('active');
        $this->setStripe('sub_wh_1', 'past_due', nextAttempt: '2026-09-13 09:00:00');

        $this->postEvent('evt_pf_1', 'invoice.payment_failed', $this->invoiceObject('in_300', 'sub_wh_1', 0))->assertOk();

        $m->refresh();
        $this->assertSame(MembershipStatus::Grace, $m->status);
        $this->assertNotNull($m->grace_until);
        $this->assertTrue($m->status->isBookable(), 'grace 中は予約可');
        $this->assertDatabaseHas('payments', ['payment_operation_id' => 'inv:in_300', 'status' => 'failed']);
    }

    public function test_reversed_order_failed_then_paid_ends_active_and_grants(): void
    {
        $m = $this->membership('active', usagePerPeriod: 4);

        $this->setStripe('sub_wh_1', 'past_due', nextAttempt: '2026-09-13 09:00:00');
        $this->postEvent('evt_r_1', 'invoice.payment_failed', $this->invoiceObject('in_400', 'sub_wh_1', 0))->assertOk();
        $this->assertSame(MembershipStatus::Grace, $m->refresh()->status);

        // Stripe が回復。invoice.paid が後から届く。
        $this->setStripe('sub_wh_1', 'active');
        $this->postEvent('evt_r_2', 'invoice.paid', $this->invoiceObject('in_400', 'sub_wh_1'))->assertOk();

        $m->refresh();
        $this->assertSame(MembershipStatus::Active, $m->status);
        $this->assertNull($m->grace_until);
        $this->assertSame(4, $m->period_available);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('type', 'GRANT')->count());
    }

    public function test_subscription_updated_cancel_at_period_end_moves_to_canceling(): void
    {
        $m = $this->membership('active');
        $this->setStripe('sub_wh_1', 'active', cancelAtPeriodEnd: true);

        $this->postEvent('evt_su_1', 'customer.subscription.updated', ['object' => 'subscription', 'id' => 'sub_wh_1'])->assertOk();

        $m->refresh();
        $this->assertSame(MembershipStatus::Canceling, $m->status);
        $this->assertTrue($m->cancel_at_period_end);
    }

    public function test_subscription_deleted_moves_to_canceled_and_does_not_rewind(): void
    {
        $m = $this->membership('canceling');
        $this->setStripe('sub_wh_1', 'canceled');

        $this->postEvent('evt_sd_1', 'customer.subscription.deleted', ['object' => 'subscription', 'id' => 'sub_wh_1'])->assertOk();
        $this->assertSame(MembershipStatus::Canceled, $m->refresh()->status);

        // 遅れて届いた updated(active) は無視され canceled のまま。
        $this->setStripe('sub_wh_1', 'active');
        $this->postEvent('evt_sd_2', 'customer.subscription.updated', ['object' => 'subscription', 'id' => 'sub_wh_1'])->assertOk();
        $this->assertSame(MembershipStatus::Canceled, $m->refresh()->status);
    }

    public function test_event_for_unknown_subscription_is_ignored(): void
    {
        $this->membership('active');

        $this->postEvent('evt_unk', 'invoice.paid', $this->invoiceObject('in_x', 'sub_not_ours'))->assertOk();

        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::query()->where('stripe_event_id', 'evt_unk')->first()->status);
        $this->assertSame(0, MembershipUsageTransaction::query()->count());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->membership('active');
        $payload = json_encode(['id' => 'evt_bad', 'type' => 'invoice.paid', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => 't='.time().',v1=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
    }
}
