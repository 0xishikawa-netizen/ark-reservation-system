<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\WebhookEventStatus;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * カード予約のキャンセルが会員・ゲストのどちらでも一度だけ全額返金され、Stripe Webhook の
 * 重複・順序逆転で決済状態が巻き戻らないことを証明する業務シナリオ。
 */
final class CardCancelRefundScenarioTest extends TestCase
{
    use DatabaseMigrations;

    private const WEBHOOK_SECRET = 'whsec_test_card_cancel_scenario';

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));
        config()->set('stripe.key', 'pk_test_cancel_scenario');
        config()->set('stripe.webhook_secret', self::WEBHOOK_SECRET);
        $this->gateway = app(FakeStripeGateway::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 事故防止: 会員の二重キャンセル、返金Webhook再送、古い成功通知で二重返金・状態巻戻しを起こさない。
     */
    public function test_member_cancel_refund_remains_consistent_after_duplicate_and_out_of_order_webhooks(): void
    {
        [$service, $staff] = $this->bookableMasters();
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-10 10:00:00',
            'payment_method' => 'card',
        ])->assertSessionHasNoErrors();
        $reservation = Reservation::query()->sole();
        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertOk();
        $payment = Payment::query()->sole();
        $this->authorizeAndCapture($payment, "/mypage/reservations/{$reservation->id}/payment/sync", $customer);

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$reservation->id}", ['reason' => '予定変更'])
            ->assertSessionHasNoErrors();

        $refunded = $this->event('evt_member_refunded', 'charge.refunded', $payment);
        $this->postEvent($refunded)->assertOk();
        $this->postEvent($refunded)->assertOk();
        $this->postEvent($this->event('evt_member_old_succeeded', 'payment_intent.succeeded', $payment))->assertOk();

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$reservation->id}", ['reason' => '二重送信'])
            ->assertSessionHasErrors('status');

        $this->assertRefundedExactlyOnce($reservation, $payment);
        $this->assertSame(2, WebhookEvent::query()->where('stripe_event_id', 'evt_member_refunded')->value('attempts'));
        $this->assertSame(WebhookEventStatus::Processed, WebhookEvent::query()
            ->where('stripe_event_id', 'evt_member_old_succeeded')->sole()->status);
    }

    /**
     * 事故防止: パスワードを持たないゲストでもトークン経路から返金漏れせず、再送で二重返金しない。
     */
    public function test_guest_token_cancel_refunds_once_and_rejects_the_second_request(): void
    {
        [$service, $staff] = $this->bookableMasters();
        $booking = $this->post('/booking', [
            'name' => '返金シナリオ ゲスト',
            'phone' => '08012345678',
            'email' => 'guest-refund-scenario@example.com',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-10 10:00:00',
            'payment_method' => 'single',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $reservation = Reservation::query()->sole();
        $checkoutPath = (string) parse_url((string) $booking->headers->get('Location'), PHP_URL_PATH);
        $this->get($checkoutPath)->assertOk();
        $payment = Payment::query()->sole();
        $this->gateway->setPaymentIntent($this->intent($payment, refundedAmount: 0, status: 'requires_capture'));
        $syncPath = preg_replace('#/checkout$#', '/payment/sync', $checkoutPath);
        $cancelPath = preg_replace('#/checkout$#', '', $checkoutPath);
        $this->assertIsString($syncPath);
        $this->assertIsString($cancelPath);
        $this->post($syncPath)->assertRedirect();

        $this->delete($cancelPath, ['reason' => '予定変更'])->assertSessionHasNoErrors();
        $refunded = $this->event('evt_guest_refunded', 'charge.refunded', $payment);
        $this->postEvent($refunded)->assertOk();
        $this->postEvent($refunded)->assertOk();
        $this->postEvent($this->event('evt_guest_old_succeeded', 'payment_intent.succeeded', $payment))->assertOk();
        $this->delete($cancelPath, ['reason' => '二重送信'])->assertSessionHasErrors('status');

        $this->assertRefundedExactlyOnce($reservation, $payment);
        $this->assertNull($reservation->customer->user->password);
    }

    /** @return array{Service, Staff} */
    private function bookableMasters(): array
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'price' => 5000,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-10',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$service, $staff];
    }

    private function authorizeAndCapture(Payment $payment, string $syncPath, Customer $customer): void
    {
        $this->gateway->setPaymentIntent($this->intent($payment, refundedAmount: 0, status: 'requires_capture'));
        $this->actingAs($customer->user)->post($syncPath)->assertRedirect();
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    private function assertRefundedExactlyOnce(Reservation $reservation, Payment $payment): void
    {
        $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
        $this->assertSame(ReservationPaymentStatus::Refunded, $reservation->payment_status);
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(5000, (int) $payment->refunded_amount);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'amount' => 5000,
            'status' => 'succeeded',
        ]);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    private function intent(Payment $payment, int $refundedAmount, string $status = 'succeeded'): PaymentIntentResult
    {
        return new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: $status,
            amount: 5000,
            amountCapturable: $status === 'requires_capture' ? 5000 : 0,
            amountReceived: $status === 'succeeded' ? 5000 : 0,
            currency: 'jpy',
            chargeId: $status === 'succeeded' ? 'ch_scenario_'.$payment->id : null,
            refundedAmount: $refundedAmount,
        );
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type, Payment $payment): array
    {
        $object = str_starts_with($type, 'charge.')
            ? ['object' => 'charge', 'id' => 'ch_scenario_'.$payment->id, 'payment_intent' => $payment->stripe_payment_intent_id]
            : ['object' => 'payment_intent', 'id' => $payment->stripe_payment_intent_id];

        return [
            'id' => $id,
            'type' => $type,
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => $object],
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
