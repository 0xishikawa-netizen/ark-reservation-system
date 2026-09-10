<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Domain\Payment\ReservationAdjustmentService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ReservationAddonCheckoutTest extends TestCase
{
    use DatabaseMigrations;

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        config()->set('stripe.key', 'pk_test_addon_public');
        config()->set('stripe.secret', 'sk_test_must_never_be_exposed');

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    public function test_customer_opens_addon_checkout_with_client_secret_but_no_secret_key(): void
    {
        [$customer, $reservation, $addon] = $this->addonFixture();

        $response = $this->actingAs($customer->user)
            ->get(route('mypage.reservations.addon.checkout', $reservation));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Payments/Checkout')
            ->where('payment.id', $addon->id)
            ->where('payment.amount', 2000)
            ->where('stripe.client_secret', 'pi_fake_secret_for_tests_only'));
        $this->assertStringNotContainsString('sk_test', $response->getContent());
        $this->assertStringNotContainsString('sk_live', $response->getContent());
    }

    public function test_sync_captures_addon_without_changing_reservation_status(): void
    {
        [$customer, $reservation, $addon] = $this->addonFixture();
        $this->gateway->setPaymentIntent($this->intent($addon, 'requires_capture'));

        $this->actingAs($customer->user)
            ->post(route('mypage.reservations.addon.payment.sync', $reservation))
            ->assertRedirect(route('mypage.reservations.show', $reservation))
            ->assertSessionHas('success');

        $this->assertSame(PaymentStatus::Succeeded, $addon->refresh()->status);
        $this->assertSame('confirmed', $reservation->refresh()->status->value);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CAPTURE));
    }

    public function test_other_customer_cannot_open_or_sync_addon_checkout(): void
    {
        [, $reservation] = $this->addonFixture();
        $other = Customer::factory()->create();
        $other->user->assignRole('customer');

        $this->actingAs($other->user)
            ->get(route('mypage.reservations.addon.checkout', $reservation))
            ->assertForbidden();
        $this->actingAs($other->user)
            ->post(route('mypage.reservations.addon.payment.sync', $reservation))
            ->assertForbidden();
    }

    public function test_no_in_flight_addon_redirects_to_reservation_show(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);

        $this->actingAs($customer->user)
            ->get(route('mypage.reservations.addon.checkout', $reservation))
            ->assertRedirect(route('mypage.reservations.show', $reservation));
    }

    /** @return array{0: Customer, 1: Reservation, 2: Payment} */
    private function addonFixture(): array
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $service = Service::factory()->create(['price' => 5000]);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'status' => 'confirmed',
            'payment_method' => 'single',
            'payment_status' => 'paid',
        ]);
        Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Single,
            'amount' => 5000,
            'status' => PaymentStatus::Succeeded,
            'stripe_payment_intent_id' => 'pi_original_'.fake()->unique()->numerify('####'),
            'paid_at' => now(),
        ]);
        $actor = User::factory()->create();
        app(ReservationAdjustmentService::class)->requestAdjustment($reservation, 7000, $actor);
        $addon = Payment::query()->where('kind', PaymentKind::SingleAddon->value)->sole();

        return [$customer, $reservation, $addon];
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
            chargeId: $status === 'succeeded' ? 'ch_addon_checkout' : null,
        );
    }
}
