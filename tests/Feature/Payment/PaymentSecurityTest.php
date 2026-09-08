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
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

final class PaymentSecurityTest extends TestCase
{
    // Stripe 呼び出しが transaction 外であることを実際に検証するため、
    // テストを transaction で包む RefreshDatabase は使わない。
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        config()->set('stripe.key', 'pk_test_dummy_publishable');
        config()->set('stripe.secret', 'sk_test_dummy_secret_value_do_not_leak');
    }

    // ---------- CSP / セキュリティヘッダ ----------

    public function test_customer_pages_allow_stripe_but_keep_frame_ancestors_locked(): void
    {
        $user = $this->userWithRole('customer');

        $response = $this->actingAs($user)->get('/mypage/reservations');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://js.stripe.com', $csp, 'Stripe.js が読み込めない');
        $this->assertStringContainsString('frame-src https://js.stripe.com https://hooks.stripe.com', $csp);
        $this->assertStringContainsString('https://api.stripe.com', $csp, 'Stripe API へ接続できない');
        // 自サイトが他所へ埋め込まれるのは常に禁止（緩めない）
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);

        $permissions = (string) $response->headers->get('Permissions-Policy');
        $this->assertStringContainsString('payment=(self "https://js.stripe.com")', $permissions);
        $this->assertStringContainsString('camera=()', $permissions);
    }

    public function test_admin_pages_keep_deny_and_do_not_allow_stripe_frames(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->get('/admin');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-src 'none'", $csp);
        $this->assertStringNotContainsString('js.stripe.com', $csp);
        $this->assertStringContainsString('payment=()', (string) $response->headers->get('Permissions-Policy'));
    }

    // ---------- secrets ----------

    public function test_stripe_secret_key_never_reaches_the_browser(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $reservation = $this->pendingReservation($customer);
        $this->gateway();

        $html = $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('sk_test_dummy_secret_value_do_not_leak', (string) $html);
        $this->assertStringNotContainsString('sk_live', (string) $html);
        // publishable key は渡ってよい
        $this->assertStringContainsString('pk_test_dummy_publishable', (string) $html);
    }

    public function test_client_secret_is_never_persisted_to_the_database(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $reservation = $this->pendingReservation($customer);
        $this->gateway();

        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertOk();

        $payment = Payment::query()->firstOrFail();
        $serialized = json_encode($payment->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('secret', strtolower($serialized));
    }

    // ---------- 顧客の認可 ----------

    public function test_customer_cannot_open_another_customers_checkout(): void
    {
        $owner = Customer::factory()->create();
        $owner->user->assignRole('customer');
        $intruder = Customer::factory()->create();
        $intruder->user->assignRole('customer');
        $reservation = $this->pendingReservation($owner);
        $this->gateway();

        $this->actingAs($intruder->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertForbidden();

        $this->actingAs($intruder->user)
            ->post("/mypage/reservations/{$reservation->id}/payment/sync")
            ->assertForbidden();

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_guest_cannot_reach_checkout(): void
    {
        $customer = Customer::factory()->create();
        $reservation = $this->pendingReservation($customer);

        $this->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertRedirect(route('login'));
    }

    // ---------- 管理側の認可 ----------

    public function test_only_staff_with_reservation_view_can_list_payments(): void
    {
        foreach (['admin', 'manager', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/admin/payments')
                ->assertOk();
        }

        $this->actingAs($this->userWithRole('customer'))
            ->get('/admin/payments')
            ->assertForbidden();
    }

    public function test_refund_requires_permission_and_password_confirmation(): void
    {
        $payment = $this->capturedPayment();

        // customer / staff は permission が無い
        foreach (['customer', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->withSession($this->confirmedPassword())
                ->post("/admin/payments/{$payment->id}/refund", [
                    'amount' => 1000,
                    'reason' => '不正な返金',
                ])
                ->assertForbidden();
        }

        // manager でもパスワード未確認なら通さない
        $this->actingAs($this->userWithRole('manager'))
            ->withSession(['auth.password_confirmed_at' => null])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 1000,
                'reason' => 'パスワード未確認',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, PaymentRefund::query()->count());
        $this->assertSame(0, (int) $payment->refresh()->refunded_amount);
    }

    public function test_manager_can_refund_with_reason_and_confirmed_password(): void
    {
        $payment = $this->capturedPayment();
        $this->gateway();

        $this->actingAs($this->userWithRole('manager'))
            ->withSession($this->confirmedPassword())
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 2000,
                'reason' => '施術中止のため',
            ])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(2000, (int) $payment->refunded_amount);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'amount' => 2000,
            'reason' => '施術中止のため',
        ]);
    }

    public function test_refund_without_reason_is_rejected(): void
    {
        $payment = $this->capturedPayment();

        $this->actingAs($this->userWithRole('manager'))
            ->withSession($this->confirmedPassword())
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 1000,
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, PaymentRefund::query()->count());
    }

    public function test_refund_cannot_exceed_paid_amount(): void
    {
        $payment = $this->capturedPayment();

        $this->actingAs($this->userWithRole('manager'))
            ->withSession($this->confirmedPassword())
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 5001,
                'reason' => '過剰返金の試行',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, (int) $payment->refresh()->refunded_amount);
    }

    public function test_authorized_payment_cannot_be_refunded_via_admin_route(): void
    {
        $payment = $this->capturedPayment();
        // 与信のみ（未 capture）へ戻す
        Payment::query()->whereKey($payment->getKey())->update([
            'status' => PaymentStatus::Authorized->value,
            'paid_at' => null,
        ]);

        $this->actingAs($this->userWithRole('manager'))
            ->withSession($this->confirmedPassword())
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 1000,
                'reason' => '与信を返金しようとした',
            ])
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, PaymentRefund::query()->count());
    }

    // ---------- helpers ----------

    private function gateway(): FakeStripeGateway
    {
        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        return $gateway;
    }

    private function pendingReservation(Customer $customer): Reservation
    {
        $service = Service::factory()->create(['price' => 5000]);

        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'status' => ReservationStatus::PendingPayment->value,
            'payment_status' => ReservationPaymentStatus::PendingPayment->value,
            'payment_method' => 'single',
            'payment_expires_at' => now()->addMinutes(10),
        ]);
    }

    private function capturedPayment(): Payment
    {
        $customer = Customer::factory()->create();
        $reservation = $this->pendingReservation($customer);
        $gateway = $this->gateway();

        $payment = Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'amount' => 5000,
            'currency' => 'jpy',
            'status' => PaymentStatus::Succeeded->value,
            'stripe_payment_intent_id' => 'pi_security_test',
            'paid_at' => now(),
        ]);

        $gateway->setPaymentIntent(new PaymentIntentResult(
            id: 'pi_security_test',
            status: 'succeeded',
            amount: 5000,
            amountCapturable: 0,
            amountReceived: 5000,
            currency: 'jpy',
            chargeId: 'ch_security_test',
        ));

        return $payment;
    }

    /** @return array<string, int> */
    private function confirmedPassword(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp];
    }

    private function userWithRole(string $role): User
    {
        $user = $role === 'customer'
            ? Customer::factory()->create()->user
            : User::factory()->create();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }
}
