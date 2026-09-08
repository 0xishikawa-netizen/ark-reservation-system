<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TicketTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * HTTP 経路での通し確認（Stripe Test Mode 相当・FakeStripeGateway 使用。実 Live API は使わない）。
 *
 *   1. 予約 → カード → authorization → capture → paid → 顧客画面 → 管理画面
 *   2. 予約 → authorization → 期限切れ → cancel（void）
 *   3. paid → 管理者 refund
 */
final class CardCheckoutE2ETest extends TestCase
{
    use DatabaseMigrations;

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('stripe.key', 'pk_test_dummy');

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_full_card_flow_from_booking_to_paid_and_visible_in_admin(): void
    {
        [$customer, $service, $staff] = $this->masters();

        // --- 1. 予約（カード） ---
        $this->actingAs($customer->user)
            ->post('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 10:00:00',
                'payment_method' => 'card',
            ])
            ->assertRedirect();

        $reservation = Reservation::query()->firstOrFail();
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        // カード予約は回数券を消費しない
        $this->assertSame(0, TicketTransaction::query()->count());

        // --- 2. 決済画面（PaymentIntent 作成） ---
        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertOk();

        $payment = Payment::query()->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        // --- 3. Payment Element で与信成立 ---
        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));

        // --- 4. sync → capture → paid ---
        $this->actingAs($customer->user)
            ->post("/mypage/reservations/{$reservation->id}/payment/sync")
            ->assertRedirect();

        $payment->refresh();
        $reservation->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->payment_status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNull($reservation->payment_expires_at);

        // --- 5. 顧客の予約画面 ---
        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}")
            ->assertOk();

        // --- 6. 管理の決済画面 ---
        $manager = $this->userWithRole('manager');
        $this->actingAs($manager)->get('/admin/payments')->assertOk();
        $this->actingAs($manager)->get("/admin/payments/{$payment->id}")->assertOk();
    }

    public function test_authorization_is_voided_when_payment_deadline_passes(): void
    {
        [$customer, $service, $staff] = $this->masters();

        $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'card',
        ])->assertRedirect();

        $reservation = Reservation::query()->firstOrFail();

        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertOk();

        $payment = Payment::query()->firstOrFail();
        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $this->actingAs($customer->user)
            ->post("/mypage/reservations/{$reservation->id}/payment/sync");

        // 与信だけ成立させ、capture させないために予約を pending へ戻す状況を作る
        Payment::query()->whereKey($payment->getKey())->update([
            'status' => PaymentStatus::Authorized->value,
            'paid_at' => null,
        ]);
        Reservation::query()->whereKey($reservation->getKey())->update([
            'status' => ReservationStatus::PendingPayment->value,
            'payment_status' => ReservationPaymentStatus::Authorized->value,
            'payment_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(PaymentStatus::Voided, $payment->refresh()->status);
        $this->assertSame(ReservationStatus::Expired, $reservation->refresh()->status);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CANCEL));
        $this->assertSame(0, $reservation->resourceSlots()->count(), '枠が解放されていない');
    }

    public function test_manager_can_refund_a_paid_reservation_end_to_end(): void
    {
        [$customer, $service, $staff] = $this->masters();

        $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'payment_method' => 'card',
        ]);

        $reservation = Reservation::query()->firstOrFail();
        $this->actingAs($customer->user)->get("/mypage/reservations/{$reservation->id}/checkout");
        $payment = Payment::query()->firstOrFail();

        $this->gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $this->actingAs($customer->user)
            ->post("/mypage/reservations/{$reservation->id}/payment/sync");

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);

        // 全額返金
        $this->actingAs($this->userWithRole('manager'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 5000,
                'reason' => '施術を提供できなかったため',
            ])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(5000, (int) $payment->refunded_amount);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'amount' => 5000,
            'status' => 'succeeded',
        ]);

        // 監査に残る（client_secret やカード情報は含まない）
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.refunded']);
    }

    // ---------- helpers ----------

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
            chargeId: $status === 'succeeded' ? 'ch_fake_e2e' : null,
        );
    }

    /** @return array{0: Customer, 1: Service, 2: Staff} */
    private function masters(): array
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
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
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }
}
