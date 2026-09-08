<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\WebhookEventStatus;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'whsec_test_signature_secret_for_tests';

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stripe.webhook_secret', self::SECRET);

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    // ---------- 署名検証 ----------

    public function test_valid_signature_is_accepted(): void
    {
        $payment = $this->cardPayment();
        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));

        $this->postEvent($this->event('evt_1', 'payment_intent.amount_capturable_updated', $payment))
            ->assertOk();

        $this->assertSame(WebhookEventStatus::Processed, WebhookEvent::query()->first()->status);
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_processed(): void
    {
        $payment = $this->cardPayment();
        $payload = json_encode($this->event('evt_bad', 'payment_intent.succeeded', $payment), JSON_THROW_ON_ERROR);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => 't='.time().',v1=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $payment = $this->cardPayment();
        $payload = json_encode($this->event('evt_x', 'payment_intent.succeeded', $payment), JSON_THROW_ON_ERROR);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_webhook_route_does_not_require_csrf_token(): void
    {
        // CSRF ミドルウェアを有効にしたままでも 419 にならないこと。
        $payment = $this->cardPayment();
        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));

        $this->withMiddleware()
            ->postEvent($this->event('evt_csrf', 'payment_intent.amount_capturable_updated', $payment))
            ->assertOk();
    }

    // ---------- 冪等性 ----------

    public function test_duplicate_event_is_not_processed_twice(): void
    {
        $payment = $this->cardPayment();
        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $event = $this->event('evt_dup', 'payment_intent.amount_capturable_updated', $payment);

        $this->postEvent($event)->assertOk();
        $capturesAfterFirst = count($this->gateway->callsFor(FakeStripeGateway::CAPTURE));

        $this->postEvent($event)->assertOk();
        $this->postEvent($event)->assertOk();

        $this->assertSame(1, WebhookEvent::query()->count(), 'event が重複記録された');
        $this->assertSame(3, (int) WebhookEvent::query()->first()->attempts);
        $this->assertCount(
            $capturesAfterFirst,
            $this->gateway->callsFor(FakeStripeGateway::CAPTURE),
            '再送で capture が再実行された',
        );
    }

    // ---------- 順序逆転 ----------

    public function test_out_of_order_event_never_rolls_back_status(): void
    {
        $payment = $this->cardPayment();

        // 先に capture 完了まで進める
        $this->gateway->setPaymentIntent($this->intent($payment, 'succeeded', received: 5000));
        $this->postEvent($this->event('evt_new', 'payment_intent.succeeded', $payment))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(ReservationStatus::Confirmed, $payment->reservation->status);

        // 後から「与信のみ」の古いイベントが届く。
        // Stripe の現在状態は succeeded のままなので巻き戻らない。
        $this->postEvent($this->event('evt_old', 'payment_intent.amount_capturable_updated', $payment))
            ->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status, 'status が巻き戻った');
        $this->assertSame(ReservationStatus::Confirmed, $payment->reservation->refresh()->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $payment->reservation->payment_status);
    }

    public function test_stale_stripe_state_does_not_downgrade_local_status(): void
    {
        $payment = $this->cardPayment();
        $this->gateway->setPaymentIntent($this->intent($payment, 'succeeded', received: 5000));
        $this->postEvent($this->event('evt_a', 'payment_intent.succeeded', $payment))->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);

        // Stripe 側の応答が古い（requires_capture）状態で返ってきたと仮定しても後退しない。
        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $this->postEvent($this->event('evt_b', 'payment_intent.amount_capturable_updated', $payment))
            ->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    // ---------- その他 ----------

    public function test_phase6_events_are_ignored_not_processed(): void
    {
        $payment = $this->cardPayment();

        $this->postEvent($this->event('evt_inv', 'invoice.paid', $payment))->assertOk();

        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::query()->first()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_unknown_payment_intent_is_ignored(): void
    {
        $event = [
            'id' => 'evt_unknown',
            'type' => 'payment_intent.succeeded',
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => ['object' => 'payment_intent', 'id' => 'pi_not_ours']],
        ];

        $this->postEvent($event)->assertOk();

        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::query()->first()->status);
    }

    public function test_webhook_events_table_stores_no_payload(): void
    {
        $columns = Schema::getColumnListing('webhook_events');

        foreach (['payload', 'body', 'raw', 'data', 'request'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "webhook_events に {$forbidden} を保存してはいけない");
        }
    }

    // ---------- helpers ----------

    /** @param array<string, mixed> $event */
    private function postEvent(array $event): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type, Payment $payment): array
    {
        $object = str_starts_with($type, 'charge.')
            ? ['object' => 'charge', 'id' => 'ch_1', 'payment_intent' => $payment->stripe_payment_intent_id]
            : ['object' => 'payment_intent', 'id' => $payment->stripe_payment_intent_id];

        return [
            'id' => $id,
            'type' => $type,
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => $object],
        ];
    }

    private function intent(
        Payment $payment,
        string $status,
        int $capturable = 0,
        int $received = 0,
    ): PaymentIntentResult {
        return new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: $status,
            amount: (int) $payment->amount,
            amountCapturable: $capturable,
            amountReceived: $received,
            currency: (string) $payment->currency,
            chargeId: $status === 'succeeded' ? 'ch_fake_1' : null,
        );
    }

    private function cardPayment(): Payment
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['price' => 5000]);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'status' => ReservationStatus::PendingPayment->value,
            'payment_status' => ReservationPaymentStatus::PendingPayment->value,
            'payment_method' => 'single',
            'payment_expires_at' => now()->addMinutes(10),
        ]);

        return Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'amount' => 5000,
            'currency' => 'jpy',
            'status' => PaymentStatus::Pending->value,
            'stripe_payment_intent_id' => 'pi_webhook_test_1',
        ]);
    }
}
