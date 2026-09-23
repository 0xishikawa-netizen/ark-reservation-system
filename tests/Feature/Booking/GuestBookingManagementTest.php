<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\ReservationCheckoutSaga;
use App\Domain\Reservation\GuestReservationTokenService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Models\ReservationGuestToken;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class GuestBookingManagementTest extends TestCase
{
    // Stripe 呼び出しが transaction 外であることも Fake Gateway で検証する。
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-22 09:00:00'));
        config()->set('stripe.key', 'pk_test_guest_checkout');
        config()->set('stripe.secret', 'sk_test_guest_secret_must_not_leak');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_checkout_renders_public_stripe_values_and_reuses_the_payment(): void
    {
        [$reservation, $token] = $this->guestReservation(PaymentMethod::Single);
        $url = route('booking.confirmation.checkout', ['selector' => $token]);

        $first = $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Booking/Checkout')
                ->where('reservation.id', $reservation->id)
                ->where('stripe.publishable_key', 'pk_test_guest_checkout')
                ->where('stripe.client_secret', 'pi_fake_secret_for_tests_only')
                ->missing('stripe.secret'));

        $this->assertStringNotContainsString(
            'sk_test_guest_secret_must_not_leak',
            $first->getContent(),
        );

        $this->get($url)->assertOk();

        $this->assertDatabaseCount('payments', 1);
        $this->assertCount(
            1,
            app(FakeStripeGateway::class)->callsFor(FakeStripeGateway::CREATE),
        );
    }

    public function test_second_start_checkout_for_a_guest_reuses_the_same_payment_row(): void
    {
        [$reservation] = $this->guestReservation(PaymentMethod::Single);
        $saga = app(ReservationCheckoutSaga::class);

        $first = $saga->startCheckout($reservation, null);
        $second = $saga->startCheckout($reservation->refresh(), null);

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($first->payment->id, $second->payment->id);
        $this->assertNull($first->payment->created_by);
        $this->assertSame(
            $first->payment->payment_operation_id,
            $second->payment->payment_operation_id,
        );
        $this->assertCount(
            1,
            app(FakeStripeGateway::class)->callsFor(FakeStripeGateway::CREATE),
        );
    }

    public function test_guest_payment_sync_advances_the_reservation_without_exposing_secret_key(): void
    {
        [$reservation, $token] = $this->guestReservation(PaymentMethod::Single);
        $this->get(route('booking.confirmation.checkout', ['selector' => $token]))->assertOk();
        $payment = Payment::query()->sole();
        app(FakeStripeGateway::class)->setPaymentIntent(new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: 'requires_capture',
            amount: (int) $payment->amount,
            amountCapturable: (int) $payment->amount,
            amountReceived: 0,
            currency: (string) $payment->currency,
        ));

        $response = $this->post(route('booking.confirmation.payment.sync', ['selector' => $token]))
            ->assertRedirect(route('booking.confirmation.show', ['selector' => $token]));

        $this->assertStringNotContainsString(
            'sk_test_guest_secret_must_not_leak',
            $response->getContent(),
        );
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->payment_status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    public function test_checkout_redirects_to_confirmation_when_payment_is_not_applicable(): void
    {
        [, $token] = $this->guestReservation(PaymentMethod::Onsite);

        $this->get(route('booking.confirmation.checkout', ['selector' => $token]))
            ->assertRedirect(route('booking.confirmation.show', ['selector' => $token]));

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_can_reschedule_with_the_confirmation_version(): void
    {
        [$reservation, $token, , $staff] = $this->guestReservation(PaymentMethod::Onsite);

        $this->put(route('booking.confirmation.reschedule', ['selector' => $token]), [
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-02 11:00:00',
            'version' => 0,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('booking.confirmation.show', ['selector' => $token]));

        $reservation->refresh();
        $this->assertSame('2026-10-02 11:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $reservation->version);

        $this->get(route('booking.confirmation.show', ['selector' => $token]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Booking/Confirmation')
                ->where('reservation.version', 1)
                ->where('reservation.can_reschedule', true)
                ->where('reservation.can_cancel', true));

        $this->putJson(route('booking.confirmation.reschedule', ['selector' => $token]), [
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-02 12:00:00',
            'version' => 0,
        ])->assertConflict();

        $this->assertSame('2026-10-02 11:00:00', $reservation->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_guest_can_cancel_a_past_pending_payment_and_release_its_payment_and_slots(): void
    {
        [$reservation, $token, , $staff] = $this->guestReservation(
            PaymentMethod::Single,
            CarbonImmutable::parse('2026-09-21 10:00:00'),
        );
        ReservationResourceSlot::query()->create([
            'reservation_id' => $reservation->id,
            'resource_type' => 'staff',
            'resource_id' => $staff->user_id,
            'slot_start' => $reservation->starts_at,
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Single,
            'status' => PaymentStatus::Pending,
            'stripe_payment_intent_id' => null,
            'created_by' => null,
        ]);

        $this->get(route('booking.confirmation.show', ['selector' => $token]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reservation.can_cancel', true)
                ->where('reservation.can_reschedule', false));

        $this->delete(route('booking.confirmation.cancel', ['selector' => $token]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('booking.confirmation.show', ['selector' => $token]));

        $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
        $this->assertSame(ReservationPaymentStatus::Voided, $reservation->payment_status);
        $this->assertNull($reservation->payment_expires_at);
        $this->assertSame(PaymentStatus::Voided, $payment->refresh()->status);
        $this->assertDatabaseMissing('reservation_resource_slots', [
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_guest_can_cancel_a_pending_reservation_after_payment_decline(): void
    {
        [$reservation, $token] = $this->guestReservation(
            PaymentMethod::Single,
            CarbonImmutable::parse('2026-09-21 10:00:00'),
            ReservationStatus::PendingPayment,
            ReservationPaymentStatus::Failed,
        );
        Payment::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Single,
            'status' => PaymentStatus::Failed,
            'created_by' => null,
        ]);

        $this->delete(route('booking.confirmation.cancel', ['selector' => $token]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('booking.confirmation.show', ['selector' => $token]));

        $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
        $this->assertSame(ReservationPaymentStatus::Failed, $reservation->payment_status);
    }

    public function test_guest_cancel_uses_customer_eligibility_rules_and_null_actor_fails_refund_safely(): void
    {
        [$pastReservation, $pastToken] = $this->guestReservation(
            PaymentMethod::Onsite,
            CarbonImmutable::parse('2026-09-21 10:00:00'),
        );

        $this->delete(route('booking.confirmation.cancel', ['selector' => $pastToken]))
            ->assertSessionHasErrors('starts_at');
        $this->assertSame(ReservationStatus::Confirmed, $pastReservation->refresh()->status);

        [$paidReservation, $paidToken] = $this->guestReservation(
            PaymentMethod::Single,
            CarbonImmutable::now()->addHours(72),
            ReservationStatus::Confirmed,
            ReservationPaymentStatus::Paid,
        );
        $payment = Payment::factory()->create([
            'customer_id' => $paidReservation->customer_id,
            'reservation_id' => $paidReservation->id,
            'kind' => PaymentKind::Single,
            'status' => PaymentStatus::Succeeded,
            'amount' => 5000,
            'refunded_amount' => 0,
            'stripe_payment_intent_id' => 'pi_guest_cancel',
            'stripe_charge_id' => 'ch_guest_cancel',
            'paid_at' => now(),
            'created_by' => null,
        ]);

        $this->delete(route('booking.confirmation.cancel', ['selector' => $paidToken]), [
            'reason' => '予定変更',
        ])->assertRedirect(route('booking.confirmation.show', ['selector' => $paidToken]));

        $this->assertSame(ReservationStatus::Canceled, $paidReservation->refresh()->status);
        $this->assertTrue($payment->refresh()->needs_attention);
        $this->assertSame('cancel_refund_failed', $payment->failure_code);
        $this->assertSame(0, PaymentRefund::query()->count());
    }

    public function test_mismatched_selector_or_validator_cannot_access_or_mutate_another_reservation(): void
    {
        [$reservationA, $tokenA] = $this->guestReservation(PaymentMethod::Single);
        [$reservationB, $tokenB] = $this->guestReservation(PaymentMethod::Onsite);
        [$selectorA, $validatorA] = explode('.', $tokenA, 2);
        [$selectorB, $validatorB] = explode('.', $tokenB, 2);

        $this->get(route('booking.confirmation.show', ['selector' => $tokenA]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reservation.id', $reservationA->id));
        $this->assertNotSame($reservationB->id, $reservationA->id);

        foreach (["{$selectorA}.{$validatorB}", "{$selectorB}.{$validatorA}"] as $index => $mixedToken) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($index + 10)]);
            $this->getJson(route('booking.confirmation.show', ['selector' => $mixedToken]))
                ->assertNotFound();
            $this->get(route('booking.confirmation.checkout', ['selector' => $mixedToken]))
                ->assertNotFound();
            $this->post(route('booking.confirmation.payment.sync', ['selector' => $mixedToken]))
                ->assertNotFound();
            $this->put(route('booking.confirmation.reschedule', ['selector' => $mixedToken]), [
                'starts_at' => '2026-10-02 11:00:00',
                'version' => 0,
            ])->assertNotFound();
            $this->delete(route('booking.confirmation.cancel', ['selector' => $mixedToken]))
                ->assertNotFound();
        }

        $this->assertSame(ReservationStatus::PendingPayment, $reservationA->refresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservationB->refresh()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_expired_token_is_rejected_on_every_guest_reservation_endpoint(): void
    {
        [, $token] = $this->guestReservation(PaymentMethod::Single);
        ReservationGuestToken::query()->update(['expires_at' => now()->subSecond()]);

        $this->getJson(route('booking.confirmation.show', ['selector' => $token]))->assertNotFound();
        $this->get(route('booking.confirmation.checkout', ['selector' => $token]))->assertNotFound();
        $this->post(route('booking.confirmation.payment.sync', ['selector' => $token]))->assertNotFound();
        $this->put(route('booking.confirmation.reschedule', ['selector' => $token]), [
            'starts_at' => '2026-10-02 11:00:00',
            'version' => 0,
        ])->assertNotFound();
        $this->delete(route('booking.confirmation.cancel', ['selector' => $token]))->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_malformed_or_missing_token_returns_generic_not_found_on_new_routes(): void
    {
        [, $validToken] = $this->guestReservation(PaymentMethod::Single);
        [$existingSelector] = explode('.', $validToken, 2);
        $wrongValidator = "{$existingSelector}.wrong-validator";
        $unknownToken = 'unknown-selector.wrong-validator';
        $malformed = 'malformed-token';

        $requests = [
            ['GET', 'booking.confirmation.checkout', []],
            ['POST', 'booking.confirmation.payment.sync', []],
            ['PUT', 'booking.confirmation.reschedule', [
                'starts_at' => '2026-10-02 11:00:00',
                'version' => 0,
            ]],
            ['DELETE', 'booking.confirmation.cancel', []],
        ];

        foreach ($requests as $index => [$method, $routeName, $data]) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($index + 10)]);
            $wrongResponse = $this->call(
                $method,
                route($routeName, ['selector' => $wrongValidator]),
                $data,
            )->assertNotFound();
            $unknownResponse = $this->call(
                $method,
                route($routeName, ['selector' => $unknownToken]),
                $data,
            )->assertNotFound();
            $this->assertSame($wrongResponse->getContent(), $unknownResponse->getContent());

            $this->call(
                $method,
                route($routeName, ['selector' => $malformed]),
                $data,
            )->assertNotFound();
        }

        $this->get('/booking/confirmation/checkout')->assertNotFound();
        $this->post('/booking/confirmation/payment/sync')->assertNotFound();
        $this->put('/booking/confirmation')->assertNotFound();
        $this->delete('/booking/confirmation')->assertNotFound();
    }

    /**
     * @return array{Reservation, string, Service, Staff}
     */
    private function guestReservation(
        PaymentMethod $method,
        ?CarbonImmutable $startsAt = null,
        ?ReservationStatus $status = null,
        ?ReservationPaymentStatus $paymentStatus = null,
    ): array {
        $startsAt ??= CarbonImmutable::parse('2026-10-01 10:00:00');
        $customer = Customer::factory()->create(['created_via' => 'web']);
        $customer->user->forceFill(['password' => null])->save();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'price' => 5000,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);

        foreach (['2026-10-01', '2026-10-02'] as $date) {
            StaffShift::query()->create([
                'staff_id' => $staff->user_id,
                'work_date' => $date,
                'start_at' => '09:00:00',
                'end_at' => '18:00:00',
            ]);
        }

        $status ??= $method === PaymentMethod::Single
            ? ReservationStatus::PendingPayment
            : ReservationStatus::Confirmed;
        $paymentStatus ??= $method === PaymentMethod::Single
            ? ReservationPaymentStatus::PendingPayment
            : ReservationPaymentStatus::Unpaid;
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'source' => ReservationSource::ArkWeb,
            'payment_method' => $method,
            'payment_status' => $paymentStatus,
            'payment_expires_at' => $status === ReservationStatus::PendingPayment
                ? CarbonImmutable::now()->addMinutes(10)
                : null,
            'status' => $status,
            'version' => 0,
            'created_by' => null,
        ]);

        return [
            $reservation,
            app(GuestReservationTokenService::class)->issue($reservation),
            $service,
            $staff,
        ];
    }
}
