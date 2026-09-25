<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Reporting\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** 既存資料の値とARKの事実・read modelを、定義差を隠さず照合する。 */
final class ReportReconciliationService
{
    public const DIFFERENCE_CATEGORIES = [
        'business_definition', 'source_inconsistency', 'migration_gap', 'implementation_bug', 'undetermined',
    ];

    public function __construct(
        private readonly MonthlyReportService $monthly,
        private readonly CustomerAnalyticsService $customers,
        private readonly AnnualReportService $annual,
        private readonly StaffUtilizationService $staff,
        private readonly TimeBandUtilizationService $timeBands,
        private readonly ProvidedWorkbookEvidence $providedWorkbook,
    ) {}

    /** @return array<string,mixed> */
    public function forMonth(int $year, int $month, ?int $batchId = null): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('年月の指定が不正です。');
        }
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Asia/Tokyo');
        $end = $start->endOfMonth()->startOfDay();
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $business = $this->monthly->forMonth($year, $month, SalesBasis::PaymentDate, $endDate);
        $customer = $this->customers->forMonth($year, $month, $endDate);
        $annual = $this->annual->forYear($year, SalesBasis::PaymentDate, sprintf('%04d-12-31', $year));
        $staff = $this->staff->forMonth($year, $month, asOfDate: $endDate);
        $bands = $this->timeBands->forMonth($year, $month, asOfDate: $endDate);
        $checks = [];

        $completed = DB::table('visits')->where('status', 'completed')
            ->whereBetween('business_date', [$startDate, $endDate])->count();
        $checks[] = $this->check('completed_visits_equal_visit_count', (int) $completed, $business->totals['visit_count']);
        foreach (['payment_date_revenue', 'treatment_date_revenue', 'visit_count', 'long_visit_count',
            'future_reservation_count', 'first_visit_count', 'first_visit_reservation_count'] as $key) {
            $checks[] = $this->check('daily_to_monthly_'.$key,
                array_sum(array_column($business->dailyRows, $key)), $business->totals[$key]);
            $checks[] = $this->check('monthly_to_annual_'.$key,
                $business->totals[$key], $annual['months'][$month - 1][$key]);
            $checks[] = $this->check('annual_months_to_total_'.$key,
                array_sum(array_column($annual['months'], $key)), $annual['totals'][$key]);
        }
        $totals = $business->totals;
        $checks[] = $this->predicate('reservations_not_above_visits', $totals['future_reservation_count'] <= $totals['visit_count']);
        $checks[] = $this->predicate('first_reservations_not_above_first_visits', $totals['first_visit_reservation_count'] <= $totals['first_visit_count']);
        $checks[] = $this->predicate('long_not_above_visits', $totals['long_visit_count'] <= $totals['visit_count']);
        $checks[] = $this->predicate('cohort_reach_monotone',
            $customer->reach['10']['numerator'] <= $customer->reach['6']['numerator']
            && $customer->reach['6']['numerator'] <= $customer->reach['2']['numerator']
            && $customer->reach['2']['numerator'] <= $customer->newCustomers);
        $checks[] = $this->predicate('annual_cohort_reach_monotone',
            $annual['totals']['reached_10'] <= $annual['totals']['reached_6']
            && $annual['totals']['reached_6'] <= $annual['totals']['reached_2']
            && $annual['totals']['reached_2'] <= $annual['totals']['new_customers']);
        $checks[] = $this->rateCheck('annual_reservation_rate_weighted',
            $annual['totals']['future_reservation_count'], $annual['totals']['visit_count'],
            $annual['totals']['reservation_rate']);
        foreach ([2, 6, 10] as $threshold) {
            $checks[] = $this->rateCheck('annual_reach_'.$threshold.'_rate_weighted',
                $annual['totals']['reached_'.$threshold], $annual['totals']['new_customers'],
                $annual['totals']['reach_'.$threshold.'_rate']);
        }
        $checks[] = $this->check('payment_methods_equal_payment_revenue',
            $business->totals['payment_date_revenue'], array_sum(array_column($business->paymentMethods, 'amount')));
        $checks[] = $this->check('tax_buckets_equal_gross',
            array_sum(array_column($business->taxBuckets, 'gross_amount')),
            array_sum(array_column($business->taxBuckets, 'net_amount')) + array_sum(array_column($business->taxBuckets, 'tax_amount')));
        $checks[] = $this->nullableCount('next_reservation_unknown_not_zeroed', $totals['future_reservation_unknown_count']);
        $checks[] = $this->nullableCount('first_reservation_unknown_not_zeroed', $totals['first_visit_reservation_unknown_count']);
        $checks[] = $this->nullableCount('churn_unknown_not_zeroed', $customer->churnCustomers === null ? 1 : 0);

        $checks = [...$checks, ...$this->factChecks($start, $end->addDay()),
            ...$this->utilizationChecks($staff['monthly_rows'], $bands['overall_rows'])];
        $comparisons = $this->historicalComparisons($batchId, $startDate, $endDate, $business, $customer);
        $sourceWorkbook = $this->providedWorkbook->forPeriod($year, $month);

        return [
            'period' => ['year' => $year, 'month' => $month, 'start' => $startDate, 'end' => $endDate],
            'source_status' => $batchId === null
                ? ($sourceWorkbook === null ? 'not_provided' : 'provided_without_imported_actuals')
                : ($comparisons === [] ? 'no_comparable_rows' : 'available'),
            'source_batch_id' => $batchId,
            'source_workbook_evidence' => $sourceWorkbook,
            'checks' => $checks,
            'check_summary' => [
                'passed' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'pass')),
                'failed' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail')),
                'unknown' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'unknown')),
            ],
            'comparisons' => $comparisons,
            'refund_policy' => 'unresolved_no_automatic_deduction',
            'source_original_verified' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function review(int $historicalMetricId, string $category, string $status, string $reason, int $reviewerId): array
    {
        if (! in_array($category, self::DIFFERENCE_CATEGORIES, true)
            || ! in_array($status, ['confirmed', 'needs_attention'], true) || trim($reason) === '') {
            throw new InvalidArgumentException('差分分類、確認状態、理由を指定してください。');
        }
        $metric = DB::table('historical_metric_values')->where('id', $historicalMetricId)->whereNull('invalidated_at')->first();
        if ($metric === null) {
            throw new InvalidArgumentException('有効な過去集計値が見つかりません。');
        }
        DB::table('historical_metric_reviews')->updateOrInsert(
            ['historical_metric_value_id' => $historicalMetricId],
            ['difference_category' => $category, 'review_status' => $status, 'reason' => trim($reason),
                'reviewed_by' => $reviewerId, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );

        return ['historical_metric_value_id' => $historicalMetricId, 'difference_category' => $category,
            'review_status' => $status, 'reason' => trim($reason), 'reviewed_by' => $reviewerId];
    }

    /** @return list<array<string,mixed>> */
    private function factChecks(CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $startUtc = $start->utc()->format('Y-m-d H:i:s');
        $endUtc = $endExclusive->utc()->format('Y-m-d H:i:s');
        $checkouts = DB::table('checkouts')->where('status', 'finalized')
            ->where('finalized_at', '>=', $startUtc)->where('finalized_at', '<', $endUtc)
            ->get(['id', 'subtotal_amount', 'tax_amount', 'total_amount']);
        $checkoutIds = $checkouts->pluck('id')->all();
        $lines = DB::table('checkout_lines')->whereIn('checkout_id', $checkoutIds)
            ->get(['id', 'checkout_id', 'net_amount', 'tax_amount', 'gross_amount', 'is_staff_allocatable']);
        $tenders = DB::table('checkout_tenders')->whereIn('checkout_id', $checkoutIds)
            ->where('status', 'received')->get(['checkout_id', 'amount']);
        $staffSales = DB::table('staff_revenue_allocations')->whereIn('checkout_line_id', $lines->pluck('id')->all())
            ->get(['checkout_line_id', 'allocated_amount']);
        $lineNet = $lineTax = $lineGross = $allocatedSales = [];
        $taxErrors = 0;
        foreach ($lines as $line) {
            $lineNet[$line->checkout_id] = ($lineNet[$line->checkout_id] ?? 0) + (int) $line->net_amount;
            $lineTax[$line->checkout_id] = ($lineTax[$line->checkout_id] ?? 0) + (int) $line->tax_amount;
            $lineGross[$line->checkout_id] = ($lineGross[$line->checkout_id] ?? 0) + (int) $line->gross_amount;
            $taxErrors += (int) $line->gross_amount !== (int) $line->net_amount + (int) $line->tax_amount ? 1 : 0;
        }
        foreach ($staffSales as $allocation) {
            $allocatedSales[$allocation->checkout_line_id] = ($allocatedSales[$allocation->checkout_line_id] ?? 0)
                + (int) $allocation->allocated_amount;
        }
        $tenderTotals = [];
        foreach ($tenders as $tender) {
            $tenderTotals[$tender->checkout_id] = ($tenderTotals[$tender->checkout_id] ?? 0) + (int) $tender->amount;
        }
        $checkoutErrors = 0;
        foreach ($checkouts as $checkout) {
            $id = $checkout->id;
            $checkoutErrors += (int) $checkout->subtotal_amount !== ($lineNet[$id] ?? 0)
                || (int) $checkout->tax_amount !== ($lineTax[$id] ?? 0)
                || (int) $checkout->total_amount !== ($lineGross[$id] ?? 0)
                || (int) $checkout->total_amount !== ($tenderTotals[$id] ?? 0) ? 1 : 0;
        }
        $staffSalesErrors = 0;
        foreach ($lines as $line) {
            if ($line->is_staff_allocatable) {
                $staffSalesErrors += (int) $line->gross_amount !== ($allocatedSales[$line->id] ?? 0) ? 1 : 0;
            }
        }

        $treatments = DB::table('visit_treatments as t')->join('visits as v', 'v.id', '=', 't.visit_id')
            ->where('v.status', 'completed')->where('t.status', 'completed')
            ->whereBetween('v.business_date', [$start->toDateString(), $endExclusive->subDay()->toDateString()])
            ->get(['t.id', 't.actual_minutes']);
        $assignments = DB::table('visit_treatment_staff')->whereIn('visit_treatment_id', $treatments->pluck('id')->all())
            ->get(['visit_treatment_id', 'actual_minutes']);
        $assignmentMinutes = $assignmentUnknown = [];
        foreach ($assignments as $assignment) {
            $assignmentMinutes[$assignment->visit_treatment_id] = ($assignmentMinutes[$assignment->visit_treatment_id] ?? 0)
                + (int) ($assignment->actual_minutes ?? 0);
            $assignmentUnknown[$assignment->visit_treatment_id] = ($assignmentUnknown[$assignment->visit_treatment_id] ?? 0)
                + ($assignment->actual_minutes === null ? 1 : 0);
        }
        $timeErrors = $timeUnknown = 0;
        foreach ($treatments as $treatment) {
            if ($treatment->actual_minutes === null || ($assignmentUnknown[$treatment->id] ?? 0) > 0) {
                $timeUnknown++;
            } else {
                $timeErrors += (int) $treatment->actual_minutes !== ($assignmentMinutes[$treatment->id] ?? 0) ? 1 : 0;
            }
        }

        $allocationTotals = DB::table('revenue_allocations')
            ->select('revenue_recognition_contract_id')->selectRaw('SUM(amount) AS allocated')
            ->groupBy('revenue_recognition_contract_id');
        $contracts = DB::table('revenue_recognition_contracts as c')
            ->leftJoinSub($allocationTotals, 'a', 'a.revenue_recognition_contract_id', '=', 'c.id')
            ->selectRaw("SUM(CASE WHEN c.status = 'closed' AND COALESCE(a.allocated, 0) <> c.contract_amount THEN 1 ELSE 0 END) AS closed_errors")
            ->selectRaw("SUM(CASE WHEN c.status = 'active' AND COALESCE(a.allocated, 0) > c.contract_amount THEN 1 ELSE 0 END) AS active_overflows")
            ->first();

        return [
            $this->check('checkout_lines_tenders_equal_header', 0, $checkoutErrors),
            $this->check('gross_equals_net_plus_tax', 0, $taxErrors),
            $this->check('staff_allocated_sales_equal_line_sales', 0, $staffSalesErrors),
            $this->check('staff_allocated_time_equal_treatment_time', 0, $timeErrors, $timeUnknown),
            $this->check('closed_contract_allocations_equal_contract', 0, (int) ($contracts->closed_errors ?? 0)),
            $this->check('active_contract_allocations_not_above_contract', 0, (int) ($contracts->active_overflows ?? 0)),
        ];
    }

    /** @param list<array<string,mixed>> $staffRows @param list<array<string,mixed>> $bandRows @return list<array<string,mixed>> */
    private function utilizationChecks(array $staffRows, array $bandRows): array
    {
        $checks = [];
        foreach ([['staff', $staffRows], ['time_band', $bandRows]] as [$kind, $rows]) {
            $errors = $unknown = 0;
            foreach ($rows as $row) {
                $occupied = $row['occupied_minutes'];
                $bookable = $row['bookable_minutes'];
                $reported = $row['bookable_utilization_rate'];
                if ($occupied === null) {
                    $unknown++;
                    if ($reported !== null) {
                        $errors++;
                    }

                    continue;
                }
                $expected = $bookable === 0 ? null : $occupied / $bookable;
                if (($expected === null) !== ($reported === null)
                    || ($expected !== null && abs($expected - $reported) > 0.0000001)) {
                    $errors++;
                }
            }
            $checks[] = $this->check($kind.'_bookable_utilization_rate', 0, $errors, $unknown);
        }

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function historicalComparisons(?int $batchId, string $startDate, string $endDate,
        MonthlyBusinessSummary $business, MonthlyCustomerSummary $customer): array
    {
        if ($batchId === null) {
            return [];
        }
        $batch = DB::table('historical_import_batches')->find($batchId);
        if ($batch === null || $batch->invalidated_at !== null) {
            throw new InvalidArgumentException('有効な取込batchが見つかりません。');
        }
        $ark = [
            ...$business->totals,
            'new_customers' => $customer->newCustomers,
            'returning_customers' => $customer->returningCustomers,
            'churn_customers' => $customer->churnCustomers,
            'reached_2' => $customer->reach['2']['numerator'],
            'reached_6' => $customer->reach['6']['numerator'],
            'reached_10' => $customer->reach['10']['numerator'],
        ];
        $rows = DB::table('historical_metric_values as h')
            ->leftJoin('historical_metric_reviews as r', 'r.historical_metric_value_id', '=', 'h.id')
            ->where('h.batch_id', $batchId)->whereNull('h.invalidated_at')
            ->where('h.period_start', $startDate)->where('h.period_end', $endDate)
            ->orderBy('h.metric_code')->orderBy('h.id')
            ->get(['h.id', 'h.metric_code', 'h.value_integer', 'h.source_row_id',
                'r.difference_category', 'r.review_status', 'r.reason', 'r.reviewed_at']);
        $comparisons = [];
        foreach ($rows as $row) {
            $arkValue = $ark[$row->metric_code] ?? null;
            if (! is_int($arkValue)) {
                $arkValue = null;
            }
            $difference = $arkValue === null ? null : $arkValue - (int) $row->value_integer;
            $comparisons[] = [
                'historical_metric_value_id' => (int) $row->id, 'source_row_id' => (int) $row->source_row_id,
                'metric_code' => $row->metric_code, 'source_value' => (int) $row->value_integer,
                'ark_value' => $arkValue, 'difference' => $difference,
                'comparison_status' => $arkValue === null ? 'not_comparable' : ($difference === 0 ? 'matched' : 'different'),
                'difference_category' => $row->difference_category,
                'review_status' => $row->review_status ?? 'pending', 'reason' => $row->reason,
                'reviewed_at' => $row->reviewed_at,
            ];
        }

        return $comparisons;
    }

    /** @return array<string,mixed> */
    private function check(string $code, ?int $expected, ?int $actual, int $unknownCount = 0): array
    {
        return ['code' => $code, 'expected' => $expected, 'actual' => $actual,
            'unknown_count' => $unknownCount,
            'status' => $expected === null || $actual === null || $unknownCount > 0
                ? ($expected !== null && $actual !== null && $expected !== $actual ? 'fail' : 'unknown')
                : ($expected === $actual ? 'pass' : 'fail')];
    }

    /** @return array<string,mixed> */
    private function predicate(string $code, bool $holds): array
    {
        return $this->check($code, 1, (int) $holds);
    }

    /** @return array<string,mixed> */
    private function nullableCount(string $code, int $unknownCount): array
    {
        return ['code' => $code, 'expected' => null, 'actual' => null,
            'unknown_count' => $unknownCount, 'status' => $unknownCount > 0 ? 'unknown' : 'pass'];
    }

    /** @return array<string,mixed> */
    private function rateCheck(string $code, int $numerator, int $denominator, ?float $actual): array
    {
        $expected = $denominator === 0 ? null : $numerator / $denominator;
        $matches = ($expected === null && $actual === null)
            || ($expected !== null && $actual !== null && abs($expected - $actual) <= 0.0000001);

        return ['code' => $code, 'expected' => $expected, 'actual' => $actual,
            'numerator' => $numerator, 'denominator' => $denominator,
            'unknown_count' => 0, 'status' => $matches ? 'pass' : 'fail'];
    }
}
