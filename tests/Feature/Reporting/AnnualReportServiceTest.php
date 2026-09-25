<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Business\SalesTargetService;
use App\Domain\Reporting\AnnualReportService;
use App\Domain\Reporting\MonthlyReportService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnnualReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_twelve_months_match_monthly_read_model_and_annual_rates_are_weighted(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $this->visit($first, '2026-01-10', 1, true);
        $this->visit($second, '2026-01-11', 1, false);
        $this->visit($first, '2026-02-10', 2, false);
        $report = $this->report(2026, '2026-12-31');

        $this->assertCount(12, $report['months']);
        foreach ($report['months'] as $row) {
            $monthly = app(MonthlyReportService::class)->forMonth(2026, $row['month'], asOfDate: $row['as_of_date']);
            $this->assertSame($monthly->totals['visit_count'], $row['visit_count']);
            $this->assertSame($monthly->totals['payment_date_revenue'], $row['payment_date_revenue']);
            $this->assertSame($monthly->totals['treatment_date_revenue'], $row['treatment_date_revenue']);
        }
        $this->assertSame(3, $report['totals']['visit_count']);
        $this->assertSame(1, $report['totals']['future_reservation_count']);
        $this->assertSame(1 / 3, $report['totals']['reservation_rate']);
        $this->assertSame(2, $report['totals']['new_customers']);
        $this->assertSame(1, $report['totals']['reached_2']);
        $this->assertSame(0.5, $report['totals']['reach_2_rate']);
        $this->assertSame(0, $report['totals']['reached_6']);
        $this->assertSame(0, $report['totals']['reached_10']);
    }

    public function test_payment_and_treatment_basis_remain_separate_and_target_uses_twelve_month_sum(): void
    {
        app(SalesTargetService::class)->setDefaultAmount(1000, null);
        $customer = Customer::factory()->create();
        $visit = $this->visit($customer, '2026-01-31', 1, false);
        $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id,
            'status' => 'completed', 'actual_minutes' => 60]);
        $checkout = Checkout::factory()->create(['visit_id' => $visit->id, 'status' => CheckoutStatus::Draft,
            'subtotal_amount' => 1000, 'tax_amount' => 0, 'total_amount' => 1000]);
        CheckoutLine::factory()->create(['checkout_id' => $checkout->id, 'visit_treatment_id' => $treatment->id,
            'unit_amount' => 1000, 'net_amount' => 1000, 'tax_amount' => 0, 'gross_amount' => 1000,
            'tax_category_code_snapshot' => 'zero', 'tax_rate_bps' => 0]);
        CheckoutTender::factory()->create(['checkout_id' => $checkout->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'status' => CheckoutTenderStatus::Received, 'amount' => 1000,
            'received_at' => '2026-02-01 01:00:00']);
        $checkout->update(['status' => CheckoutStatus::Finalized, 'finalized_at' => '2026-02-01 01:00:00']);

        $payment = app(AnnualReportService::class)->forYear(2026, 'payment_date', '2026-12-31');
        $treatmentBasis = app(AnnualReportService::class)->forYear(2026, 'treatment_date', '2026-12-31');
        $this->assertSame(0, $payment['months'][0]['selected_revenue']);
        $this->assertSame(1000, $payment['months'][1]['selected_revenue']);
        $this->assertSame(1000, $treatmentBasis['months'][0]['selected_revenue']);
        $this->assertSame(0, $treatmentBasis['months'][1]['selected_revenue']);
        $this->assertSame(12_000, $payment['totals']['target_amount']);
        $this->assertSame(1000 / 12_000, $payment['totals']['achievement_rate']);
    }

    public function test_leap_year_partial_month_future_and_missing_target_remain_distinct(): void
    {
        $report = $this->report(2028, '2028-02-29');
        $this->assertCount(12, $report['months']);
        $this->assertSame('2028-02-29', $report['months'][1]['as_of_date']);
        $this->assertTrue($report['months'][2]['is_future']);
        $this->assertSame('2028-02-29', $report['months'][2]['as_of_date']);
        $this->assertNull($report['totals']['target_amount']);
        $this->assertSame(12, $report['totals']['target_missing_months']);
        $this->assertNull($report['totals']['achievement_rate']);
        $this->assertNull($report['totals']['reservation_rate']);
        $this->assertNull($report['totals']['reach_2_rate']);
        $this->assertNull($report['totals']['bookable_utilization_rate']);
    }

    public function test_year_boundary_churn_and_month_as_of_are_correct(): void
    {
        $customer = Customer::factory()->create();
        $this->visit($customer, '2025-11-20', 1, false);
        $this->visit($customer, '2026-01-15', 2, false);
        $report = $this->report(2026, '2026-01-15');
        $this->assertSame(1, $report['months'][0]['returning_customers']);
        $this->assertSame(1, $report['months'][0]['churn_customers']);
        $this->assertTrue($report['months'][1]['is_future']);
        $this->assertSame(1, $report['totals']['visit_count']);
        $this->assertNull($report['totals']['churn_customers']);
        $this->assertSame(11, $report['totals']['churn_unknown_months']);
    }

    public function test_staff_and_time_band_annual_rates_use_total_minutes(): void
    {
        $staff = Staff::factory()->create();
        StaffShift::query()->create(['staff_id' => $staff->user_id, 'work_date' => '2026-01-10',
            'start_at' => '10:00', 'end_at' => '12:00']);
        $visit = $this->visit(Customer::factory()->create(), '2026-01-10', 1, false);
        $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id,
            'status' => 'completed', 'actual_minutes' => 60]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id,
            'staff_id' => $staff->user_id, 'actual_minutes' => 60,
            'actual_started_at' => '2026-01-10 01:00:00', 'actual_ended_at' => '2026-01-10 02:00:00']);
        $report = $this->report(2026, '2026-12-31');
        $this->assertSame(60, $report['totals']['occupied_minutes']);
        $this->assertSame(120, $report['totals']['bookable_minutes']);
        $this->assertSame(0.5, $report['totals']['bookable_utilization_rate']);
        $this->assertSame(60, $report['time_bands'][0]['occupied_minutes']);
        $this->assertSame(120, $report['time_bands'][0]['bookable_minutes']);
        $this->assertSame(0.5, $report['time_bands'][0]['bookable_utilization_rate']);
    }

    /** @return array<string,mixed> */
    private function report(int $year, string $asOf): array
    {
        return app(AnnualReportService::class)->forYear($year, asOfDate: $asOf);
    }

    private function visit(Customer $customer, string $date, int $sequence, bool $future): Visit
    {
        return Visit::factory()->create(['customer_id' => $customer->user_id,
            'business_date' => $date, 'status' => 'completed', 'visit_sequence' => $sequence,
            'future_reservation_exists_at_checkout' => $future]);
    }
}
