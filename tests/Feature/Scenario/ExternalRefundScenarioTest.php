<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe Dashboardで先に行われた外部返金をARKへ同期し、管理画面から残額を超える返金を
 * 発行してStripeとARKの累計が乖離する事故を防ぐ業務シナリオ。
 */
final class ExternalRefundScenarioTest extends TestCase
{
    use DatabaseMigrations;

    private const WEBHOOK_SECRET = 'whsec_test_external_refund_scenario';

    private FakeStripeGateway $gateway;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
        config()->set('stripe.webhook_secret', self::WEBHOOK_SECRET);
        $this->gateway = app(FakeStripeGateway::class);
        $this->manager = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->manager->assignRole('manager');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 事故防止: Stripeですでに全額返金済みならARKはローカル返金行もStripe返金API呼出も作らない。
     */
    public function test_external_full_refund_webhook_blocks_every_admin_refund_without_gateway_call(): void
    {
        $payment = $this->capturedPayment('full');
        $this->gateway->setPaymentIntent($this->intent($payment, 5000));
        $this->postEvent($this->event('evt_external_full', $payment))->assertOk();

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(5000, (int) $payment->refunded_amount);
        $this->assertDatabaseCount('payment_refunds', 0);

        $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 1,
                'reason' => '外部全額返金後の再返金試行',
            ])->assertSessionHasErrors();

        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertCount(0, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    /**
     * 事故防止: 外部一部返金1000円を差し引いた残額4000円だけを返金でき、4001円は拒否する。
     */
    public function test_external_partial_refund_allows_exact_remainder_but_rejects_one_yen_over(): void
    {
        $payment = $this->capturedPayment('partial');
        $this->gateway->setPaymentIntent($this->intent($payment, 1000));
        $this->postEvent($this->event('evt_external_partial', $payment))->assertOk();

        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        $this->assertSame(1000, (int) $payment->refunded_amount);
        $this->assertDatabaseCount('payment_refunds', 0);

        $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 4001,
                'reason' => '外部返金を無視した過剰返金試行',
            ])->assertSessionHasErrors('amount');
        $this->assertCount(0, $this->gateway->callsFor(FakeStripeGateway::REFUND));

        $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 4000,
                'reason' => '外部返金後の残額返金',
            ])->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(5000, (int) $payment->refunded_amount);
        $this->assertSame(4000, PaymentRefund::query()->sole()->amount);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    private function capturedPayment(string $suffix): Payment
    {
        $customer = Customer::factory()->create();

        return Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'status' => PaymentStatus::Succeeded,
            'amount' => 5000,
            'refunded_amount' => 0,
            'stripe_payment_intent_id' => 'pi_external_refund_'.$suffix,
            'stripe_charge_id' => 'ch_external_refund_'.$suffix,
            'paid_at' => now(),
            'created_by' => $customer->user_id,
        ]);
    }

    private function intent(Payment $payment, int $refundedAmount): PaymentIntentResult
    {
        return new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: 'succeeded',
            amount: 5000,
            amountCapturable: 0,
            amountReceived: 5000,
            currency: 'jpy',
            chargeId: (string) $payment->stripe_charge_id,
            refundedAmount: $refundedAmount,
        );
    }

    /** @return array<string, mixed> */
    private function event(string $id, Payment $payment): array
    {
        return [
            'id' => $id,
            'type' => 'charge.refunded',
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => [
                'object' => 'charge',
                'id' => $payment->stripe_charge_id,
                'payment_intent' => $payment->stripe_payment_intent_id,
            ]],
        ];
    }

    /** @param array<string, mixed> $event */
    private function postEvent(array $event): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
