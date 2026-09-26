<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Accounting\CheckoutService;
use App\Domain\Reporting\CourseSalesService;
use App\Enums\Visit\VisitStatus;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSalesServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_course_sales_and_usage_are_separate_and_targets_give_difference(): void
    {
        $ticket = TicketProduct::factory()->create(['name' => '8回券60分', 'price' => 61600, 'is_active' => true]);
        $single = Service::factory()->create(['name' => '一般60分', 'is_active' => true]);
        $customer = Customer::factory()->create();

        // 回数券の購入（来店なし会計）＝売上。
        $sale = app(CheckoutService::class)->createStoreDraft($customer, '2026-09-05');
        $this->line($sale, ['item_type' => 'ticket', 'ticket_product_id' => $ticket->id, 'item_name_snapshot' => '8回券60分'], 61600, '2026-09-05');
        // 回数券での来店2回（別の顧客も1回）＝利用。売上には入れない。
        $wallet = TicketWallet::factory()->create(['customer_id' => $customer->user_id, 'ticket_product_id' => $ticket->id]);
        $this->ticketVisit($customer, $wallet, '2026-09-10');
        $this->ticketVisit($customer, $wallet, '2026-09-17');
        $other = Customer::factory()->create();
        $this->ticketVisit($other, TicketWallet::factory()->create(['customer_id' => $other->user_id, 'ticket_product_id' => $ticket->id]), '2026-09-18');
        // 単発メニューの来店会計＝売上と利用。
        $visit = Visit::factory()->create(['customer_id' => $customer->user_id, 'business_date' => '2026-09-12', 'status' => VisitStatus::Completed, 'visit_sequence' => 9]);
        $this->line(app(CheckoutService::class)->createDraft($visit), ['item_type' => 'service', 'service_id' => $single->id, 'item_name_snapshot' => '一般60分'], 6600, '2026-09-12');

        $service = app(CourseSalesService::class);
        $service->setTarget('2026-09', 'ticket', $ticket->id, 100000, null, null);
        $report = $service->forMonth(2026, 9);
        $rows = collect($report['rows'])->keyBy(fn (array $row): string => $row['course_type'].':'.$row['course_id']);

        $ticketRow = $rows['ticket:'.$ticket->id];
        $this->assertSame(61600, $ticketRow['sales_amount']);
        $this->assertSame(1, $ticketRow['sales_quantity']);
        $this->assertSame(3, $ticketRow['usage_count']);
        $this->assertSame(2, $ticketRow['user_count']);
        $this->assertSame(100000, $ticketRow['target_amount']);
        $this->assertSame(-38400, $ticketRow['difference_amount']);
        $this->assertSame(0.616, $ticketRow['achievement_rate']);

        $singleRow = $rows['service:'.$single->id];
        $this->assertSame(6600, $singleRow['sales_amount']);
        $this->assertSame(1, $singleRow['usage_count']);
        $this->assertNull($singleRow['target_amount']);
        $this->assertNull($singleRow['difference_amount']);
        $this->assertSame(68200, $report['totals']['sales_amount']);

        $service->setTarget('2026-09', 'ticket', $ticket->id, null, null, null);
        $this->assertNull(collect($service->forMonth(2026, 9)['rows'])->firstWhere('course_id', $ticket->id)['target_amount']);
    }

    public function test_target_editing_requires_settings_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $ticket = TicketProduct::factory()->create();
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->givePermissionTo(['admin.access', 'reports.view', 'sales.view']);
        $this->actingAs($viewer)->get(route('admin.reports.course-sales'))->assertOk();
        $this->actingAs($viewer)->put(route('admin.reports.course-sales.targets'), [
            'month' => '2026-09', 'course_type' => 'ticket', 'course_id' => $ticket->id, 'target_amount' => 1000,
        ])->assertForbidden();

        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->put(route('admin.reports.course-sales.targets'), [
            'month' => '2026-09', 'course_type' => 'ticket', 'course_id' => $ticket->id, 'target_amount' => 1000,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('course_sales_targets', ['course_type' => 'ticket', 'course_id' => $ticket->id, 'target_amount' => 1000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'course_sales_target.saved']);
        $this->actingAs($admin)->put(route('admin.reports.course-sales.targets'), [
            'month' => '2026-09', 'course_type' => 'service', 'course_id' => 999999, 'target_amount' => 1000,
        ])->assertSessionHasErrors('course_id');
    }

    /** @param array<string, mixed> $attributes */
    private function line($checkout, array $attributes, int $gross, string $date): void
    {
        $service = app(CheckoutService::class);
        $service->addLine($checkout, [...$attributes, 'quantity' => 1, 'unit_amount' => $gross, 'tax_rate_bps' => 1000,
            'net_amount' => $gross - intdiv($gross, 11), 'tax_amount' => intdiv($gross, 11), 'gross_amount' => $gross, 'is_staff_allocatable' => false]);
        $service->addTender($checkout, PaymentMethod::factory()->create(), $gross, ['received_at' => CarbonImmutable::parse($date.' 12:00', 'Asia/Tokyo')->utc()]);
        $service->syncTotals($checkout);
        $service->finalize($checkout);
    }

    private function ticketVisit(Customer $customer, TicketWallet $wallet, string $date): void
    {
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id, 'starts_at' => CarbonImmutable::parse($date.' 10:00', 'UTC'), 'status' => 'completed']);
        TicketReservationUsage::factory()->create(['reservation_id' => $reservation->id, 'ticket_wallet_id' => $wallet->id, 'status' => 'consumed', 'consumed_at' => now()]);
        Visit::factory()->create(['customer_id' => $customer->user_id, 'reservation_id' => $reservation->id, 'business_date' => $date, 'status' => VisitStatus::Completed, 'visit_sequence' => null]);
    }
}
