<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 9.6 の認証変更（Passkey 撤去 / TOTP 一本化）が
 * Phase 4（回数券）/ Phase 5（Stripe）を壊していないことの確認。
 *
 * とくに「TOTP を確認済みの manager」が従来どおり機微操作（返金）を行えることを保証する。
 */
final class MfaNonRegressionTest extends TestCase
{
    // Stripe 呼び出しが transaction 外であることを実際に検証するため
    // RefreshDatabase（テスト全体を transaction で包む）は使わない。
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------- Phase 5 非退行 ----------

    public function test_manager_with_confirmed_totp_can_reach_the_payments_screen(): void
    {
        $manager = $this->staffUser('manager', confirmedTotp: true);

        $this->actingAs($manager)->get('/admin/payments')->assertOk();
    }

    public function test_manager_with_confirmed_totp_can_execute_a_refund(): void
    {
        $manager = $this->staffUser('manager', confirmedTotp: true);
        $payment = $this->capturedPayment();

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 3000,
                'reason' => 'TOTP 有効ユーザーの再認証後の返金',
            ])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(3000, (int) $payment->refunded_amount);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(1, PaymentRefund::query()->count());
    }

    public function test_refund_still_requires_reauthentication_after_the_mfa_change(): void
    {
        $manager = $this->staffUser('manager', confirmedTotp: true);
        $payment = $this->capturedPayment();

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => null])
            ->post("/admin/payments/{$payment->id}/refund", [
                'amount' => 3000,
                'reason' => '再認証なしの返金',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, PaymentRefund::query()->count());
    }

    public function test_card_reservation_flow_is_unchanged(): void
    {
        $gateway = $this->gateway();
        [$customer, $service, $staff] = $this->masters();

        $reservation = app(ReservationService::class)->create($this->input(
            $customer, $service, $staff, PaymentMethod::Single,
        ));

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::PendingPayment, $reservation->payment_status);
        $this->assertNotNull($reservation->payment_expires_at);
        // カード予約は回数券を消費しない
        $this->assertSame(0, TicketReservationUsage::query()->count());
        $this->assertSame([], $gateway->calls());
    }

    // ---------- Phase 4 非退行 ----------

    public function test_ticket_reservation_hold_and_release_are_unchanged(): void
    {
        $gateway = $this->gateway();
        [$customer, $service, $staff] = $this->masters();
        $wallet = $this->grantTicket($customer);

        $reservation = app(ReservationService::class)->create($this->input(
            $customer, $service, $staff, PaymentMethod::Ticket,
        ));

        $usage = TicketReservationUsage::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(TicketReservationUsageStatus::Held, $usage->status);
        $this->assertSame(4, app(TicketLedgerService::class)->available($wallet->refresh()));
        // 回数券予約は Stripe を呼ばない
        $this->assertSame([], $gateway->calls());

        app(ReservationService::class)->cancel($reservation, 'テスト', $customer->user);

        $this->assertSame(
            TicketReservationUsageStatus::Released,
            $usage->refresh()->status,
        );
        $this->assertSame(5, app(TicketLedgerService::class)->available($wallet->refresh()));
    }

    // ---------- helpers ----------

    private function gateway(): FakeStripeGateway
    {
        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);

        return $gateway;
    }

    private function capturedPayment(): Payment
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['price' => 5000]);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'status' => ReservationStatus::Confirmed->value,
            'payment_status' => ReservationPaymentStatus::Paid->value,
            'payment_method' => 'single',
        ]);

        $gateway = $this->gateway();
        $gateway->setPaymentIntent(new PaymentIntentResult(
            id: 'pi_mfa_nonreg',
            status: 'succeeded',
            amount: 5000,
            amountCapturable: 0,
            amountReceived: 5000,
            currency: 'jpy',
            chargeId: 'ch_mfa_nonreg',
        ));

        return Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'amount' => 5000,
            'currency' => 'jpy',
            'status' => PaymentStatus::Succeeded->value,
            'stripe_payment_intent_id' => 'pi_mfa_nonreg',
            'paid_at' => now(),
        ]);
    }

    /** @return array{0: Customer, 1: Service, 2: Staff} */
    private function masters(): array
    {
        $customer = Customer::factory()->create();
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

    private function input(
        Customer $customer,
        Service $service,
        Staff $staff,
        PaymentMethod $method,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: $method,
        );
    }

    private function grantTicket(Customer $customer): TicketWallet
    {
        $product = TicketProduct::factory()->create(['total_count' => 5]);
        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => 5,
            'balance' => 0,
            'expires_at' => CarbonImmutable::parse('2027-01-01'),
        ]);

        app(TicketLedgerService::class)->append(
            wallet: $wallet,
            type: TicketTransactionType::Grant,
            delta: 5,
            dedupeKey: "grant:{$wallet->id}:mfa-nonreg",
            reason: 'テスト付与',
        );

        return $wallet->refresh();
    }

    private function staffUser(string $role, bool $confirmedTotp = false): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Staff::factory()->create(['user_id' => $user->id]);

        if ($confirmedTotp) {
            $user->forceFill([
                'two_factor_secret' => encrypt('secret'),
                'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $user->refresh();
    }
}
