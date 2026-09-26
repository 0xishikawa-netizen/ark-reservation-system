<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\AnnualReportService;
use App\Domain\Reporting\CustomerAnalyticsService;
use App\Domain\Reporting\DailyReportService;
use App\Domain\Reporting\MonthlyReportService;
use App\Domain\Reporting\StaffUtilizationService;
use App\Domain\Reporting\TimeBandUtilizationService;
use App\Models\Checkout;
use App\Models\Reservation;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\Phase11OperationalE2ESeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Phase11OperationalE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_local_scenario_runs_through_completion_and_reconciles_all_reports(): void
    {
        $this->seed(Phase11OperationalE2ESeeder::class);
        $this->seed(Phase11OperationalE2ESeeder::class);

        $this->assertSame(7, Visit::query()->whereHas('customer.user', fn ($query) => $query->where('name', 'like', 'PHASE11_TEST_%'))->count());
        $this->assertSame(7, Reservation::query()->where('status', 'completed')->where('notes', 'like', 'PHASE11_TEST_%')->count());
        $this->assertSame(7, Checkout::query()->where('status', 'finalized')->where('operation_id', 'like', 'PHASE11_TEST_%')->count());

        $daily = app(DailyReportService::class)->forDate('2026-09-15');
        $this->assertSame(4, $daily->visitCount);
        $this->assertSame(1, $daily->longVisitCount);
        $this->assertSame(1, $daily->futureReservationCount);
        $this->assertSame(3, $daily->firstVisitCount);
        $this->assertSame(1, $daily->firstVisitReservationCount);
        $this->assertSame(4, $daily->analysisCategoryVisitCounts['M']);
        $this->assertSame(38_500, $daily->paymentDateRevenue);
        $this->assertSame(['cash' => 32_500, 'paypay' => 6000], collect($daily->paymentMethodTotals)->pluck('amount', 'code')->all());
        $this->assertSame(3500, array_sum(array_column($daily->taxTotals, 'tax_amount')));

        $monthly = app(MonthlyReportService::class)->forMonth(2026, 9, asOfDate: '2026-09-25');
        $this->assertSame(6, $monthly->totals['visit_count']);
        $this->assertSame(49_500, $monthly->totals['payment_date_revenue']);
        $this->assertSame(1, $monthly->totals['long_visit_count']);
        $this->assertSame(1, $monthly->totals['future_reservation_count']);

        $customers = app(CustomerAnalyticsService::class)->forMonth(2026, 9, '2026-09-25');
        $this->assertSame(4, $customers->newCustomers);
        $this->assertSame(1, $customers->returningCustomers);
        $this->assertSame(1, $customers->churnCustomers);
        $this->assertSame(1, $customers->reach['2']['numerator']);
        $this->assertSame(0, $customers->reach['6']['numerator']);
        $this->assertSame(0, $customers->reach['10']['numerator']);

        $staffA = Staff::query()->where('display_name', 'PHASE11_TEST_担当A')->firstOrFail();
        $staffB = Staff::query()->where('display_name', 'PHASE11_TEST_担当B')->firstOrFail();
        $staff = app(StaffUtilizationService::class)->forMonth(2026, 9, asOfDate: '2026-09-25');
        $a = collect($staff['monthly_rows'])->firstWhere('staff_id', $staffA->user_id);
        $b = collect($staff['monthly_rows'])->firstWhere('staff_id', $staffB->user_id);
        $this->assertSame(5, $a['patient_count']);
        $this->assertSame(1, $b['patient_count']);
        $this->assertSame(165, $a['occupied_minutes']);
        $this->assertSame(105, $b['occupied_minutes']);
        $this->assertSame(1, $a['future_reservation_count']);
        $this->assertSame(1, $a['nomination_count']);
        $dayA = collect($staff['daily_rows'])->first(fn ($row) => $row['staff_id'] === $staffA->user_id && $row['business_date'] === '2026-09-15');
        $this->assertSame(480, $dayA['working_minutes']);
        $this->assertSame('actual', $dayA['working_minutes_source']);
        $this->assertSame(330, $dayA['bookable_minutes']);

        $bands = app(TimeBandUtilizationService::class)->forMonth(2026, 9, asOfDate: '2026-09-25');
        $aMorning = collect($bands['daily_rows'])->first(fn ($row) => $row['staff_id'] === $staffA->user_id && $row['business_date'] === '2026-09-15' && $row['band_code'] === '10_12');
        $bNoon = collect($bands['daily_rows'])->first(fn ($row) => $row['staff_id'] === $staffB->user_id && $row['business_date'] === '2026-09-15' && $row['band_code'] === '12_15');
        $this->assertSame(75, $aMorning['occupied_minutes']);
        $this->assertSame(105, $bNoon['occupied_minutes']);
        $this->assertSame(0, $bands['outside_band_minutes']);

        $annual = app(AnnualReportService::class)->forYear(2026, asOfDate: '2026-09-25');
        $this->assertSame(49_500, $annual['months'][8]['payment_date_revenue']);
        $this->assertSame(6, $annual['months'][8]['visit_count']);
        $this->assertSame(55_000, $annual['totals']['payment_date_revenue']);
        $this->assertSame(7, $annual['totals']['visit_count']);
        $this->assertSame(7, User::query()->where('name', 'like', 'PHASE11_TEST_%')->count());
    }
}
