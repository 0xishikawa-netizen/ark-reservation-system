<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Business\SalesTargetService;
use App\Domain\Reporting\DailyReportService;
use App\Domain\Reporting\MonthlyBusinessSummary;
use App\Domain\Reporting\MonthlyReportService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Enums\Reporting\SalesBasis;
use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\PaymentMethod;
use App\Models\RevenueAllocation;
use App\Models\RevenueRecognitionContract;
use App\Models\StoreCalendarDay;
use App\Models\Visit;
use App\Models\VisitTreatment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MonthlyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_contains_exact_calendar_days_for_28_29_30_and_31_day_months(): void
    {
        $this->assertCount(28, $this->report(2026, 2, asOf: '2026-02-28')->dailyRows);
        $this->assertCount(29, $this->report(2028, 2, asOf: '2028-02-29')->dailyRows);
        $this->assertCount(30, $this->report(2026, 4, asOf: '2026-04-30')->dailyRows);
        $this->assertCount(31, $this->report(2026, 10, asOf: '2026-10-31')->dailyRows);
    }

    public function test_month_totals_equal_the_sum_of_shared_daily_summaries_and_rates_are_weighted(): void
    {
        $this->visits('2026-10-01', 1, true);
        $this->visits('2026-10-02', 9, false);
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金']);
        $this->checkout($this->visit('2026-10-01'), $cash, 1100, '2026-10-01 01:00:00');
        $this->checkout($this->visit('2026-10-02'), $cash, 2200, '2026-10-02 01:00:00');

        $monthly = $this->report(2026, 10, asOf: '2026-10-31');
        $daily = app(DailyReportService::class)->forRange('2026-10-01', '2026-10-31');

        $this->assertSame(array_sum(array_map(fn ($day): int => $day->visitCount, $daily)), $monthly->totals['visit_count']);
        $this->assertSame(array_sum(array_map(fn ($day): int => $day->paymentDateRevenue, $daily)), $monthly->totals['payment_date_revenue']);
        $this->assertSame(array_sum(array_map(fn ($day): int => $day->treatmentDateRevenue, $daily)), $monthly->totals['treatment_date_revenue']);
        $this->assertSame(1, $monthly->ratios['future_reservation_rate']->numerator);
        $this->assertSame(12, $monthly->ratios['future_reservation_rate']->denominator);
        $this->assertSame(1 / 12, $monthly->ratios['future_reservation_rate']->value);
    }

    public function test_closed_days_are_excluded_from_denominators_but_their_facts_remain(): void
    {
        StoreCalendarDay::query()->create(['business_date' => '2026-10-01', 'status' => StoreCalendarDay::STATUS_CLOSED]);
        $this->visits('2026-10-01', 4, false); // 木曜の休業日に残る過去実績
        $this->visits('2026-10-03', 2, false); // 土曜
        $this->visits('2026-10-05', 1, false); // 月曜（祝日相当でも曜日どおり）

        $summary = $this->report(2026, 10, asOf: '2026-10-05');

        $this->assertSame(7, $summary->totals['visit_count']);
        $this->assertTrue($summary->dailyRows[0]['is_closed']);
        $this->assertSame(4, $summary->dailyRows[0]['visit_count']);
        $this->assertSame(4, $summary->businessDays['elapsed']);
        $this->assertSame(2, $summary->businessDays['elapsed_weekdays']);
        $this->assertSame(2, $summary->businessDays['elapsed_weekends']);
        $this->assertSame(2.5, $summary->averages['weekday_visits']);
        $this->assertSame(1.0, $summary->averages['weekend_visits']);
        $this->assertSame(26, $summary->businessDays['remaining']);
    }

    public function test_target_progress_remaining_days_and_zero_division_are_safe(): void
    {
        app(SalesTargetService::class)->setDefaultAmount(2_000_000, null);
        app(SalesTargetService::class)->setMonthly('2026-10', 3_000_000, null);
        $cash = PaymentMethod::factory()->create();
        $this->checkout($this->visit('2026-10-01'), $cash, 1_000_000, '2026-10-01 01:00:00');

        $middle = $this->report(2026, 10, asOf: '2026-10-21');
        $this->assertSame(3_000_000, $middle->target);
        $this->assertSame(1_000_000, $middle->progress['actual_amount']);
        $this->assertSame(-2_000_000, $middle->progress['difference_amount']);
        $this->assertSame(2_000_000, $middle->progress['remaining_required_amount']);
        $this->assertSame(10, $middle->businessDays['remaining']);
        $this->assertEquals(200_000, $middle->progress['required_daily_average']);

        $monthEnd = $this->report(2026, 10, asOf: '2026-10-31');
        $this->assertSame(0, $monthEnd->businessDays['remaining']);
        $this->assertNull($monthEnd->progress['required_daily_average']);

        $this->checkout($this->visit('2026-10-22'), $cash, 2_500_000, '2026-10-22 01:00:00');
        $exceeded = $this->report(2026, 10, asOf: '2026-10-22');
        $this->assertSame(500_000, $exceeded->progress['difference_amount']);
        $this->assertSame(0, $exceeded->progress['remaining_required_amount']);
        $this->assertSame(3_500_000 / 3_000_000, $exceeded->progress['achievement_rate']);

        $defaultTarget = $this->report(2026, 11, asOf: '2026-11-01');
        $this->assertSame(2_000_000, $defaultTarget->target);

        $future = $this->report(2027, 1);
        $this->assertSame(0, $future->businessDays['elapsed']);
        $this->assertSame(31, $future->businessDays['remaining']);
        $this->assertNull($future->averages['daily_visits']);
    }

    public function test_sales_basis_switches_without_mixing_and_overlap_allocation_is_not_double_counted(): void
    {
        $cash = PaymentMethod::factory()->create();
        $visit = $this->visit('2026-09-25');
        $treatment = VisitTreatment::factory()->create([
            'visit_id' => $visit->id, 'status' => VisitTreatmentStatus::Completed,
            'actual_minutes' => 60, 'analysis_category_code_snapshot' => 'M',
        ]);
        $line = $this->checkout($visit, $cash, 11_000, '2026-10-01 01:00:00', $treatment);
        $overlapContract = RevenueRecognitionContract::factory()->create([
            'source_checkout_line_id' => $line->id, 'contract_amount' => 500,
        ]);
        RevenueAllocation::factory()->create([
            'revenue_recognition_contract_id' => $overlapContract->id,
            'visit_id' => $visit->id, 'visit_treatment_id' => $treatment->id,
            'recognized_on' => '2026-09-25', 'amount' => 500, 'allocation_no' => 1,
        ]);
        RevenueAllocation::factory()->create([
            'recognized_on' => '2026-09-25', 'amount' => 1000, 'allocation_no' => 1,
        ]);

        $payment = $this->report(2026, 9, SalesBasis::PaymentDate, '2026-09-30');
        $treatmentBasis = $this->report(2026, 9, SalesBasis::TreatmentDate, '2026-09-30');

        $this->assertSame(0, $payment->totals['selected_revenue']);
        $this->assertSame(12_000, $treatmentBasis->totals['selected_revenue']);
        $this->assertSame(11_000, $treatmentBasis->totals['direct_treatment_revenue']);
        $this->assertSame(1000, $treatmentBasis->totals['allocated_treatment_revenue']);
    }

    public function test_dynamic_disabled_payment_methods_and_tax_snapshots_are_summed_by_day_and_month(): void
    {
        $disabled = PaymentMethod::factory()->create(['code' => 'legacy', 'name' => '旧決済', 'is_enabled' => false]);
        $other = PaymentMethod::factory()->create(['code' => 'new_method', 'name' => '新決済']);
        $this->checkout($this->visit('2026-10-01'), $disabled, 1080, '2026-10-01 01:00:00', taxRateBps: 800);
        $this->checkout($this->visit('2026-10-02'), $other, 1050, '2026-10-02 01:00:00', taxRateBps: 500);

        $summary = $this->report(2026, 10, asOf: '2026-10-31');

        $this->assertSame(['legacy', 'new_method'], array_column($summary->paymentMethods, 'code'));
        $this->assertSame([1080, 1050], array_column($summary->paymentMethods, 'amount'));
        $this->assertSame(2130, array_sum(array_column($summary->taxBuckets, 'gross_amount')));
        $this->assertSame([500, 800], array_column($summary->taxBuckets, 'tax_rate_bps'));
        $this->assertSame(80, $summary->dailyRows[0]['tax_totals'][0]['tax_amount']);
        $this->assertSame(50, $summary->dailyRows[1]['tax_totals'][0]['tax_amount']);
    }

    public function test_first_and_second_half_boundary_and_unknowns_are_preserved(): void
    {
        $this->visits('2026-10-15', 1, null);
        $this->visits('2026-10-16', 2, null);

        $summary = $this->report(2026, 10, asOf: '2026-10-31');

        $this->assertSame(1, $summary->periods['first']['visit_count']);
        $this->assertSame(2, $summary->periods['second']['visit_count']);
        $this->assertSame(3, $summary->totals['future_reservation_unknown_count']);
        $this->assertSame(3, $summary->totals['unknown_analysis_category_visit_count']);
    }

    public function test_query_count_is_constant_instead_of_daily_n_plus_one(): void
    {
        $queries = 0;
        $counting = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });

        $this->report(2026, 10, asOf: '2026-10-31');
        $counting = false;

        $this->assertLessThanOrEqual(12, $queries);
    }

    private function report(int $year, int $month, SalesBasis $basis = SalesBasis::PaymentDate, ?string $asOf = null): MonthlyBusinessSummary
    {
        return app(MonthlyReportService::class)->forMonth($year, $month, $basis, $asOf);
    }

    private function visit(string $date, ?bool $futureReservation = false, int $sequence = 2): Visit
    {
        return Visit::factory()->create([
            'business_date' => $date, 'status' => VisitStatus::Completed,
            'future_reservation_exists_at_checkout' => $futureReservation, 'visit_sequence' => $sequence,
        ]);
    }

    private function visits(string $date, int $count, ?bool $futureReservation): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->visit($date, $futureReservation, $index + 2);
        }
    }

    private function checkout(
        Visit $visit,
        PaymentMethod $method,
        int $gross,
        string $receivedAt,
        ?VisitTreatment $treatment = null,
        int $taxRateBps = 1000,
    ): CheckoutLine {
        $tax = intdiv($gross * $taxRateBps, 10_000 + $taxRateBps);
        $checkout = Checkout::factory()->create([
            'visit_id' => $visit->id, 'status' => CheckoutStatus::Draft,
            'subtotal_amount' => $gross - $tax, 'tax_amount' => $tax, 'total_amount' => $gross,
        ]);
        $line = CheckoutLine::factory()->create([
            'checkout_id' => $checkout->id, 'visit_treatment_id' => $treatment?->id,
            'unit_amount' => $gross, 'net_amount' => $gross - $tax, 'tax_amount' => $tax, 'gross_amount' => $gross,
            'tax_category_code_snapshot' => 'rate_'.$taxRateBps,
            'tax_category_name_snapshot' => '税率'.$taxRateBps,
            'tax_rate_bps' => $taxRateBps,
        ]);
        CheckoutTender::factory()->create([
            'checkout_id' => $checkout->id, 'payment_method_id' => $method->id,
            'amount' => $gross, 'status' => CheckoutTenderStatus::Received, 'received_at' => $receivedAt,
        ]);
        $checkout->update(['status' => CheckoutStatus::Finalized, 'finalized_at' => $receivedAt]);

        return $line;
    }
}
