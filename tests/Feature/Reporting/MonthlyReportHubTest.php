<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\CourseSalesService;
use App\Domain\Reporting\DailyLedgerService;
use App\Domain\Reporting\MonthlyOverviewService;
use App\Domain\Reporting\MonthlyReportService;
use App\Domain\Reporting\ReservationAnalysisService;
use App\Domain\Reporting\StaffSalesService;
use App\Enums\Reporting\SalesBasis;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Phase11MasterSeeder;
use Database\Seeders\Phase11OperationalE2ESeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Task 11-31: 月次レポート（概要・日計明細・予約分析・コース/物販・スタッフ売上の時間あたり）が、
 * 月計と同じ Fact から二重計上なく作られること。
 */
final class MonthlyReportHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27 12:00:00'));
        $this->seed([RolePermissionSeeder::class, Phase11MasterSeeder::class, Phase11OperationalE2ESeeder::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_daily_ledger_is_built_from_facts_and_matches_the_monthly_report(): void
    {
        $monthly = app(MonthlyReportService::class)->forMonth(2026, 9, SalesBasis::PaymentDate);
        $ledger = app(DailyLedgerService::class)->forMonth(2026, 9);

        $this->assertSame('R8.9', $ledger['legacy_sheet_name']);
        // 1 完了来店 = 1 行。来店数は月計と一致する。
        $this->assertCount($monthly->totals['visit_count'], $ledger['rows']);
        $this->assertSame($monthly->totals['visit_count'], $ledger['totals']['visit_count']);
        // 来店の会計＋店頭販売の合計が、月計の決済日基準売上と一致する（同日決済の検証データ）。
        $this->assertSame($monthly->totals['payment_date_revenue'], $ledger['totals']['visit_gross'] + $ledger['totals']['store_gross']);
        $row = collect($ledger['rows'])->first(fn (array $r): bool => $r['sales'] !== null && count($r['sales']['payments']) > 1);
        $this->assertNotNull($row, '分割支払の来店が行に出る');
        $this->assertSame($row['sales']['gross'], array_sum(array_column($row['sales']['payments'], 'amount')));
        $this->assertNotEmpty($row['treatments']);
    }

    public function test_overview_uses_the_same_totals_and_lists_attention_items(): void
    {
        $monthly = app(MonthlyReportService::class)->forMonth(2026, 9, SalesBasis::PaymentDate);
        $overview = app(MonthlyOverviewService::class)->forMonth(2026, 9, SalesBasis::PaymentDate);

        $this->assertSame($monthly->totals['gross_sales'], $overview['sales']['gross']);
        $this->assertSame($monthly->totals['net_sales'], $overview['sales']['net']);
        $this->assertSame($monthly->totals['visit_count'], $overview['visits']['visit_count']);
        $this->assertSame(intdiv($overview['sales']['visit_gross'], $monthly->totals['visit_count']), $overview['sales']['average_per_visit']);
        $this->assertSame(
            ['accounting_pending', 'draft_checkout', 'payment_mismatch', 'allocation_incomplete', 'staff_minutes_mismatch',
                'nomination_unknown', 'reservation_outside_shift', 'new_customer_attributes'],
            array_column($overview['attention'], 'code'),
        );
        // 検証データの会計は支払一致・配分済み。
        $attention = collect($overview['attention'])->keyBy('code');
        $this->assertSame(0, $attention['payment_mismatch']['count']);
        $this->assertSame(0, $attention['allocation_incomplete']['count']);
    }

    public function test_reservation_analysis_counts_statuses_and_lead_time_without_new_inputs(): void
    {
        $report = app(ReservationAnalysisService::class)->forMonth(2026, 9);

        $totals = $report['totals'];
        $this->assertGreaterThan(0, $totals['reservation_count']);
        $this->assertSame($totals['reservation_count'], array_sum($report['lead_time']['buckets']) + $report['lead_time']['unknown']);
        $this->assertSame($totals['reservation_count'], array_sum(array_column($report['by_weekday'], 'total')));
        $this->assertSame($totals['completed'], array_sum(array_column($report['by_staff'], 'completed')));
    }

    public function test_course_page_adds_product_sales_and_staff_sales_add_per_hour_amounts(): void
    {
        $course = app(CourseSalesService::class)->forMonth(2026, 9);
        $this->assertArrayHasKey('products', $course);
        $this->assertSame($course['products']['total_gross'], array_sum(array_column($course['products']['rows'], 'gross')));

        $staff = app(StaffSalesService::class)->forMonth(2026, 9);
        foreach ($staff['staff_rows'] as $row) {
            $this->assertArrayHasKey('sales_per_occupied_hour', $row);
            if ($row['occupied_minutes'] !== null && $row['occupied_minutes'] > 0) {
                $this->assertSame(intdiv($row['total_amount'] * 60, $row['occupied_minutes']), $row['sales_per_occupied_hour']);
            }
        }
    }

    public function test_hub_pages_render_with_the_existing_permissions(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        foreach (['overview' => 'Overview', 'daily-ledger' => 'DailyLedger', 'reservation-analysis' => 'ReservationAnalysis'] as $path => $component) {
            $this->actingAs($admin)->get("/admin/reports/{$path}?year=2026&month=9")->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component("Admin/Reports/{$component}")->where('report.month_key', '2026-09'));
        }
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');
        $this->actingAs($staff)->get('/admin/reports/overview')->assertForbidden();
        $this->actingAs($staff)->get('/admin/reports/daily-ledger')->assertForbidden();
    }
}
