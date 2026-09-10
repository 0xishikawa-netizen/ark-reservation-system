<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Domain\Payment\ReservationAdjustmentService;
use App\Domain\Payment\ReservationCheckoutSaga;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ReservationAdjustmentServiceTest extends TestCase
{
    use DatabaseMigrations;

    private FakeStripeGateway $gateway;

    private ReservationAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        Carbon::setTestNow('2026-09-10 12:00:00');

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
        $this->service = app(ReservationAdjustmentService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_net_received_counts_only_captured_less_refunded_payments(): void
    {
        $reservation = Reservation::factory()->create();
        $this->payment($reservation, 5000, PaymentStatus::Succeeded);
        $this->payment($reservation, 3000, PaymentStatus::PartiallyRefunded, refunded: 1000);
        $this->payment($reservation, 2500, PaymentStatus::Refunded, refunded: 2500);
        $this->payment($reservation, 9000, PaymentStatus::Pending);

        $this->assertSame(7000, $this->service->netReceived($reservation));
    }

    public function test_positive_delta_creates_addon_with_parent_deadline_and_intent(): void
    {
        [$reservation, $original, $actor] = $this->paidReservation(5000);

        $result = $this->service->requestAdjustment($reservation, 7200, $actor);
        $addon = Payment::query()->where('kind', PaymentKind::SingleAddon->value)->sole();

        $this->assertSame('addon_created', $result['outcome']);
        $this->assertSame(2200, $addon->amount);
        $this->assertSame($original->id, $addon->parent_payment_id);
        $this->assertSame(PaymentStatus::Pending, $addon->status);
        $this->assertSame('2026-09-10 12:10:00', $addon->payment_expires_at?->toDateTimeString());
        $this->assertNotNull($addon->stripe_payment_intent_id);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CREATE));
        $this->assertSame(0, $this->gateway->callsFor(FakeStripeGateway::CREATE)[0]['transaction_level']);
    }

    public function test_same_final_amount_reuses_addon_without_second_create(): void
    {
        [$reservation, , $actor] = $this->paidReservation(5000);

        $first = $this->service->requestAdjustment($reservation, 7000, $actor);
        $second = $this->service->requestAdjustment($reservation->refresh(), 7000, $actor);

        $this->assertSame('addon_created', $first['outcome']);
        $this->assertSame('addon_reused', $second['outcome']);
        $this->assertSame($first['addon_payment_id'], $second['addon_payment_id']);
        $this->assertSame(1, Payment::query()->where('kind', PaymentKind::SingleAddon->value)->count());
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CREATE));
    }

    public function test_price_raised_again_voids_old_addon_and_creates_one_full_gap(): void
    {
        [$reservation, , $actor] = $this->paidReservation(5000);
        $this->service->requestAdjustment($reservation, 7000, $actor);
        $old = Payment::query()->where('kind', PaymentKind::SingleAddon->value)->sole();

        $result = $this->service->requestAdjustment($reservation->refresh(), 8500, $actor);
        $new = Payment::query()
            ->where('kind', PaymentKind::SingleAddon->value)
            ->where('status', PaymentStatus::Pending->value)
            ->sole();

        $this->assertSame('addon_created', $result['outcome']);
        $this->assertSame(PaymentStatus::Voided, $old->refresh()->status);
        $this->assertSame(3500, $new->amount);
        $this->assertSame(3500, $this->service->pendingAddonTotal($reservation));
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CANCEL));
        $this->assertCount(2, $this->gateway->callsFor(FakeStripeGateway::CREATE));
    }

    public function test_paid_addon_increases_net_then_exact_difference_is_refunded(): void
    {
        [$reservation, $original, $actor] = $this->paidReservation(5000);
        $this->service->requestAdjustment($reservation, 8000, $actor);
        $addon = Payment::query()->where('kind', PaymentKind::SingleAddon->value)->sole();
        $this->gateway->setPaymentIntent($this->intent($addon, 'requires_capture'));

        app(ReservationCheckoutSaga::class)->syncAndAdvance($addon);

        $this->assertSame(PaymentStatus::Succeeded, $addon->refresh()->status);
        $this->assertSame(8000, $this->service->netReceived($reservation));

        $result = $this->service->requestAdjustment($reservation->refresh(), 4000, $actor);

        $this->assertSame('refunded', $result['outcome']);
        $this->assertSame(4000, $result['refunded_amount']);
        $this->assertSame(4000, (int) PaymentRefund::query()->sum('amount'));
        $this->assertSame(3000, $addon->refresh()->refunded_amount);
        $this->assertSame(1000, $original->refresh()->refunded_amount);
        $this->assertLessThanOrEqual($addon->amount, $addon->refunded_amount);
        $this->assertLessThanOrEqual($original->amount, $original->refunded_amount);
    }

    public function test_zero_delta_creates_no_payment_or_refund_and_is_audited(): void
    {
        [$reservation, , $actor] = $this->paidReservation(5000);

        $result = $this->service->requestAdjustment($reservation, 5000, $actor);

        $this->assertSame('no_change', $result['outcome']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, PaymentRefund::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.adjustment_no_change']);
    }

    public function test_initial_negative_delta_refunds_only_the_difference(): void
    {
        [$reservation, $original, $actor] = $this->paidReservation(5000);

        $result = $this->service->requestAdjustment($reservation, 4100, $actor);

        $this->assertSame('refunded', $result['outcome']);
        $this->assertSame(900, $result['refunded_amount']);
        $this->assertSame(900, $original->refresh()->refunded_amount);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $original->id,
            'amount' => 900,
            'status' => 'succeeded',
        ]);
    }

    public function test_addon_intent_failure_keeps_final_amount_and_marks_attention(): void
    {
        [$reservation, , $actor] = $this->paidReservation(5000);
        $this->gateway->queue(FakeStripeGateway::CREATE, new PaymentGatewayException('temporary'));

        $result = $this->service->requestAdjustment($reservation, 6500, $actor);
        $addon = Payment::query()->where('kind', PaymentKind::SingleAddon->value)->sole();

        $this->assertSame('addon_failed', $result['outcome']);
        $this->assertSame(6500, $reservation->refresh()->final_amount);
        $this->assertSame(PaymentStatus::Pending, $addon->status);
        $this->assertTrue($addon->needs_attention);
        $this->assertSame(1, AuditLog::query()->where('action', 'reservation.addon_payment_failed')->count());
    }

    /** @return array{0: Reservation, 1: Payment, 2: User} */
    private function paidReservation(int $amount): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['price' => $amount]);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'status' => 'confirmed',
            'payment_method' => 'single',
            'payment_status' => 'paid',
        ]);
        $payment = $this->payment($reservation, $amount, PaymentStatus::Succeeded);
        $actor = User::factory()->create();

        return [$reservation, $payment, $actor];
    }

    private function payment(
        Reservation $reservation,
        int $amount,
        PaymentStatus $status,
        int $refunded = 0,
    ): Payment {
        return Payment::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Single,
            'amount' => $amount,
            'status' => $status,
            'refunded_amount' => $refunded,
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->numerify('########'),
            'stripe_charge_id' => $status === PaymentStatus::Pending ? null : 'ch_'.fake()->unique()->numerify('########'),
            'authorized_at' => $status === PaymentStatus::Pending ? null : now(),
            'paid_at' => in_array($status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)
                ? now()
                : null,
        ]);
    }

    private function intent(Payment $payment, string $status): PaymentIntentResult
    {
        return new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: $status,
            amount: (int) $payment->amount,
            amountCapturable: $status === 'requires_capture' ? (int) $payment->amount : 0,
            amountReceived: $status === 'succeeded' ? (int) $payment->amount : 0,
            currency: (string) $payment->currency,
            chargeId: $status === 'succeeded' ? 'ch_addon' : null,
        );
    }
}
