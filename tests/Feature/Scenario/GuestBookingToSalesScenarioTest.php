<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Domain\Reporting\DailyReportService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Models\Checkout;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ゲスト予約の事前決済が来店会計まで一度だけ引き継がれ、予約・決済・来店・売上の
 * どこにも二重計上や取りこぼしが生じないことを証明する業務シナリオ。
 */
final class GuestBookingToSalesScenarioTest extends TestCase
{
    use DatabaseMigrations;

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('stripe.key', 'pk_test_guest_sales_scenario');

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

    /**
     * 事故防止: ゲストのカード決済を二重作成・二重売上にせず、確定済み来店会計1件へ収束させる。
     */
    public function test_guest_booking_payment_visit_checkout_and_daily_sales_are_one_consistent_flow(): void
    {
        $taxCategory = TaxCategory::query()->create([
            'code' => 'standard',
            'name' => '標準税率',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        TaxRate::query()->create([
            'tax_category_id' => $taxCategory->id,
            'rate_bps' => 1000,
            'effective_from' => '2019-10-01',
        ]);
        $stripeMethod = PaymentMethod::factory()->create([
            'code' => 'stripe',
            'name' => 'カード事前決済',
            'is_enabled' => true,
            'external_provider' => 'stripe',
        ]);
        $service = Service::factory()->create([
            'name' => 'シナリオ整体60',
            'duration_min' => 60,
            'price' => 5000,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
            'tax_category_id' => $taxCategory->id,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-10',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        $this->getJson('/booking/availability?'.http_build_query([
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'date' => '2026-10-10',
        ]))->assertOk()->assertJsonFragment(['starts_at' => '2026-10-10 10:00:00']);

        $booking = $this->post('/booking', [
            'name' => '売上シナリオ ゲスト',
            'phone' => '09012345678',
            'email' => 'guest-sales-scenario@example.com',
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-10 10:00:00',
            'payment_method' => 'single',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $reservation = Reservation::query()->sole();
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $checkoutPath = (string) parse_url((string) $booking->headers->get('Location'), PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#^/booking/confirmation/[^/]+/checkout$#', $checkoutPath);

        $this->get($checkoutPath)->assertOk();
        $payment = Payment::query()->sole();
        $this->gateway->setPaymentIntent(new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: 'requires_capture',
            amount: 5000,
            amountCapturable: 5000,
            amountReceived: 0,
            currency: 'jpy',
        ));
        $syncPath = preg_replace('#/checkout$#', '/payment/sync', $checkoutPath);
        $this->assertIsString($syncPath);
        $this->post($syncPath)->assertRedirect();

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->payment_status);

        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $this->actingAs($admin)
            ->post(route('admin.reservations.visit', $reservation))
            ->assertRedirect();
        $visit = Visit::query()->where('reservation_id', $reservation->id)->sole();

        $this->actingAs($admin)->put(route('admin.visits.checkout.update', $visit), [
            'primary_staff_id' => $staff->user_id,
            'nominated_staff_ids' => [],
            'treatments' => [[
                'service_id' => $service->id,
                'actual_minutes' => 60,
                'started_at' => '10:00',
                'staff' => [[
                    'staff_id' => $staff->user_id,
                    'actual_minutes' => 60,
                ]],
            ]],
            'lines' => [[
                'item_type' => 'service',
                'service_id' => $service->id,
                'quantity' => 1,
                'unit_amount' => 5000,
                'treatment_index' => 0,
                'is_staff_allocatable' => true,
                'allocations' => [[
                    'staff_id' => $staff->user_id,
                    'amount' => 5000,
                ]],
            ]],
            'tenders' => [[
                'payment_method_id' => $stripeMethod->id,
                'amount' => 5000,
            ]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post(route('admin.visits.complete', $visit))
            ->assertSessionHasNoErrors();

        $checkout = Checkout::query()->where('visit_id', $visit->id)->sole();
        $daily = app(DailyReportService::class)->forDate('2026-10-10');

        $this->assertSame(VisitStatus::Completed, $visit->refresh()->status);
        $this->assertSame(ReservationStatus::Completed, $reservation->refresh()->status);
        $this->assertSame(CheckoutStatus::Finalized, $checkout->status);
        $this->assertSame(5000, (int) $checkout->total_amount);
        $this->assertSame(1, $daily->visitCount);
        $this->assertSame(5000, $daily->paymentDateRevenue);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('checkouts', 1);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CREATE));
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CAPTURE));
    }
}
