<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Reservation\ReservationService;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ReservationCancellationRefundTest extends TestCase
{
    // RefreshDatabase はテスト全体を transaction で包むため使わない。
    // Stripe 呼び出しが transaction 外であることを実際に検証するには transactionLevel()===0 が必要。
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $this->seed([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_captured_card_payment_is_fully_refunded_more_than_48_hours_before(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(72, 5000);

        app(ReservationService::class)->cancel($reservation, '予定変更', $customer->user);

        $refund = PaymentRefund::query()->sole();
        $this->assertSame(5000, $refund->amount);
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(5000, $payment->refresh()->refunded_amount);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.cancel_refunded',
            'entity_id' => (string) $reservation->id,
        ]);
        $this->assertSame(
            [0],
            array_column($this->gateway()->callsFor(FakeStripeGateway::REFUND), 'transaction_level'),
        );
    }

    public function test_captured_card_payment_is_half_refunded_about_36_hours_before(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(36, 5001);

        app(ReservationService::class)->cancel($reservation, null, $customer->user);

        $this->assertSame(2500, PaymentRefund::query()->sole()->amount);
        $this->assertSame(2500, $payment->refresh()->refunded_amount);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
    }

    public function test_captured_card_payment_is_not_refunded_less_than_24_hours_before(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(12, 5000);

        app(ReservationService::class)->cancel($reservation, null, $customer->user);

        $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(0, $payment->refunded_amount);
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertSame([], $this->gateway()->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_non_card_reservations_cancel_without_refund_or_error(): void
    {
        foreach ([PaymentMethod::Onsite, PaymentMethod::Ticket, PaymentMethod::Membership] as $method) {
            $customer = Customer::factory()->create();
            $reservation = Reservation::factory()->create([
                'customer_id' => $customer->user_id,
                'starts_at' => now()->addDays(3),
                'ends_at' => now()->addDays(3)->addHour(),
                'payment_method' => $method,
                'payment_status' => ReservationPaymentStatus::Unpaid,
                'status' => ReservationStatus::Confirmed,
            ]);

            app(ReservationService::class)->cancel($reservation, null, $customer->user);

            $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
        }

        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertSame([], $this->gateway()->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_second_cancel_does_not_create_another_refund(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(72, 5000);
        $service = app(ReservationService::class);
        $service->cancel($reservation, null, $customer->user);

        try {
            $service->cancel($reservation, null, $customer->user);
            $this->fail('キャンセル済み予約を再キャンセルできました。');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertSame(5000, $payment->refresh()->refunded_amount);
        $this->assertCount(1, $this->gateway()->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_gateway_refund_failure_keeps_cancellation_and_flags_payment_for_attention(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(72, 5000);
        app(FakeStripeGateway::class)->queue(
            FakeStripeGateway::REFUND,
            new PaymentGatewayDeclinedException('card_declined'),
        );

        $canceled = app(ReservationService::class)->cancel(
            $reservation,
            '返金失敗テスト',
            $customer->user,
        );

        $this->assertSame(ReservationStatus::Canceled, $canceled->status);
        $freshPayment = $payment->fresh();
        $this->assertNotNull($freshPayment);
        $this->assertTrue($freshPayment->needs_attention);
        $this->assertSame(PaymentStatus::Succeeded, $freshPayment->status);
        $this->assertSame(RefundStatus::Failed, PaymentRefund::query()->sole()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.cancel_refund_failed',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_admin_cancel_route_triggers_policy_refund(): void
    {
        [$reservation, $payment] = $this->capturedReservation(72, 5000);
        $manager = $this->roleUser('manager');

        $this->actingAs($manager)
            ->patch("/admin/reservations/{$reservation->id}/cancel", ['reason' => '店舗都合'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame($manager->id, PaymentRefund::query()->sole()->created_by);
    }

    public function test_customer_self_cancel_route_triggers_policy_refund(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(72, 5000);
        $customer->user->assignRole('customer');

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$reservation->id}", ['reason' => '予定変更'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.reservations.index'));

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame($customer->user_id, PaymentRefund::query()->sole()->created_by);
    }

    public function test_cancellation_refund_syncs_external_partial_refund_and_clamps_to_remaining_amount(): void
    {
        [$reservation, $payment, $customer] = $this->capturedReservation(72, 5000);
        $this->gateway()->setPaymentIntent(new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: 'succeeded',
            amount: 5000,
            amountCapturable: 0,
            amountReceived: 5000,
            currency: 'jpy',
            chargeId: (string) $payment->stripe_charge_id,
            refundedAmount: 1000,
        ));

        app(ReservationService::class)->cancel($reservation, '外部一部返金後', $customer->user);

        $this->assertSame(4000, PaymentRefund::query()->sole()->amount);
        $this->assertSame(5000, $payment->refresh()->refunded_amount);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
    }

    public function test_admin_with_customer_profile_can_cancel_started_reservation(): void
    {
        $manager = $this->roleUser('manager');
        Customer::factory()->create(['user_id' => $manager->id]);
        $reservationCustomer = Customer::factory()->create();
        $reservation = Reservation::factory()->create([
            'customer_id' => $reservationCustomer->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
            'payment_method' => PaymentMethod::Onsite,
            'payment_status' => ReservationPaymentStatus::Unpaid,
            'status' => ReservationStatus::Confirmed,
        ]);

        $this->actingAs($manager)
            ->patch("/admin/reservations/{$reservation->id}/cancel", ['reason' => '店舗判断'])
            ->assertSessionHasNoErrors();

        $this->assertSame(ReservationStatus::Canceled, $reservation->refresh()->status);
    }

    /** @return array{Reservation, Payment, Customer} */
    private function capturedReservation(int $hoursUntilStart, int $amount): array
    {
        $customer = Customer::factory()->create();
        $startsAt = CarbonImmutable::now()->addHours($hoursUntilStart);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'payment_method' => PaymentMethod::Single,
            'payment_status' => ReservationPaymentStatus::Paid,
            'status' => ReservationStatus::Confirmed,
        ]);
        $payment = Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Single,
            'status' => PaymentStatus::Succeeded,
            'amount' => $amount,
            'refunded_amount' => 0,
            'stripe_payment_intent_id' => 'pi_cancel_'.$reservation->id,
            'stripe_charge_id' => 'ch_cancel_'.$reservation->id,
            'paid_at' => now(),
            'created_by' => $customer->user_id,
        ]);
        $this->gateway()->setPaymentIntent(new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: 'succeeded',
            amount: $amount,
            amountCapturable: 0,
            amountReceived: $amount,
            currency: 'jpy',
            chargeId: (string) $payment->stripe_charge_id,
        ));

        return [$reservation, $payment, $customer];
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function gateway(): FakeStripeGateway
    {
        return app(FakeStripeGateway::class);
    }
}
