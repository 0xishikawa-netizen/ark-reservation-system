<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Business\SalesTargetService;
use App\Enums\Reporting\SalesBasis;
use App\Queries\DailyReportQuery;
use App\Queries\MonthlyReportQuery;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class MonthlyReportService
{
    public function __construct(
        private readonly DailyReportService $dailyReports,
        private readonly MonthlyReportQuery $query,
        private readonly SalesTargetService $salesTargets,
        private readonly BusinessTime $businessTime,
    ) {}

    public function forMonth(
        int $year,
        int $month,
        SalesBasis|string $basis = SalesBasis::PaymentDate,
        CarbonInterface|string|null $asOfDate = null,
    ): MonthlyBusinessSummary {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('年月の指定が不正です。');
        }
        $salesBasis = $basis instanceof SalesBasis ? $basis : SalesBasis::tryFrom($basis);
        if ($salesBasis === null) {
            throw new InvalidArgumentException('売上基準の指定が不正です。');
        }

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone())->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $asOf = $this->resolveAsOfDate($start, $end, $asOfDate);
        $closedDates = array_fill_keys($this->query->closedDates($start, $end), true);
        $daily = $this->dailyReports->forRange($start, $end);

        $rows = [];
        foreach ($daily as $summary) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $summary->businessDate, $this->businessTime->timezone());
            $closed = isset($closedDates[$summary->businessDate]);
            $future = $date->gt($asOf);
            $rows[] = [
                ...$summary->jsonSerialize(),
                'day' => $date->day,
                'is_closed' => $closed,
                'is_future' => $future,
                'is_elapsed_business_day' => ! $closed && ! $future,
                'period_half' => $date->day <= 15 ? 'first' : 'second',
                'selected_revenue' => $salesBasis === SalesBasis::PaymentDate
                    ? $summary->paymentDateRevenue
                    : $summary->treatmentDateRevenue,
            ];
        }

        $totals = $this->totals($daily, $salesBasis);
        $actualTotals = $this->totals(array_values(array_filter($daily,
            static fn (DailyBusinessSummary $summary): bool => $summary->businessDate <= $asOf->toDateString())), $salesBasis);
        $elapsedDays = 0;
        $remainingDays = 0;
        $weekdayDays = 0;
        $weekendDays = 0;
        $weekdayVisits = 0;
        $weekendVisits = 0;
        $weekdaySales = 0;
        $weekendSales = 0;
        $elapsedSales = 0;
        $elapsedVisits = 0;
        foreach ($rows as $row) {
            $elapsed = ! $row['is_future'];
            if ($elapsed) {
                $elapsedSales += $row['selected_revenue'];
                $elapsedVisits += $row['visit_count'];
            }
            if ($elapsed && ! $row['is_closed']) {
                $elapsedDays++;
                if ($row['weekday_iso'] <= 5) {
                    $weekdayDays++;
                } else {
                    $weekendDays++;
                }
            } elseif ($row['is_future'] && ! $row['is_closed']) {
                $remainingDays++;
            }
            // 休業日の事実は失わず、平均の分母だけから休業日を除く。
            if ($elapsed && $row['weekday_iso'] <= 5) {
                $weekdayVisits += $row['visit_count'];
                $weekdaySales += $row['selected_revenue'];
            } elseif ($elapsed) {
                $weekendVisits += $row['visit_count'];
                $weekendSales += $row['selected_revenue'];
            }
        }

        $target = $this->salesTargets->forMonth($start);
        // 目標進捗はas_of_dateまで。月合計は全日次factsの合計として別途保持する。
        $actual = $elapsedSales;
        $difference = $target === null ? null : $actual - $target;
        $remaining = $target === null ? null : max($target - $actual, 0);
        $progress = [
            'target_amount' => $target,
            'actual_amount' => $actual,
            'difference_amount' => $difference,
            'remaining_required_amount' => $remaining,
            'achievement_rate' => $target === null || $target === 0 ? null : $actual / $target,
            'required_daily_average' => $remaining === null || $remainingDays === 0 ? null : $remaining / $remainingDays,
        ];

        return new MonthlyBusinessSummary(
            year: $year,
            month: $month,
            monthKey: $start->format('Y-m'),
            asOfDate: $asOf->toDateString(),
            salesBasis: $salesBasis->value,
            dailyRows: $rows,
            totals: $totals,
            actualTotals: $actualTotals,
            ratios: [
                'future_reservation_rate' => new DailyRatio($totals['future_reservation_count'], $totals['visit_count']),
                'first_visit_reservation_rate' => new DailyRatio($totals['first_visit_reservation_count'], $totals['first_visit_count']),
            ],
            actualRatios: [
                'future_reservation_rate' => new DailyRatio($actualTotals['future_reservation_count'], $actualTotals['visit_count']),
                'first_visit_reservation_rate' => new DailyRatio($actualTotals['first_visit_reservation_count'], $actualTotals['first_visit_count']),
            ],
            target: $target,
            progress: $progress,
            businessDays: [
                'calendar_days' => $end->day,
                'total' => $elapsedDays + $remainingDays,
                'elapsed' => $elapsedDays,
                'input_days' => $elapsedDays,
                'remaining' => $remainingDays,
                'closed' => count($closedDates),
                'elapsed_weekdays' => $weekdayDays,
                'elapsed_weekends' => $weekendDays,
            ],
            averages: [
                'daily_sales' => $this->average($actual, $elapsedDays),
                'daily_visits' => $this->average($elapsedVisits, $elapsedDays),
                'weekday_sales' => $this->average($weekdaySales, $weekdayDays),
                'weekend_sales' => $this->average($weekendSales, $weekendDays),
                'weekday_visits' => $this->average($weekdayVisits, $weekdayDays),
                'weekend_visits' => $this->average($weekendVisits, $weekendDays),
            ],
            periods: $this->periodTotals($rows),
            paymentMethods: $totals['payment_method_totals'],
            taxBuckets: $totals['tax_totals'],
        );
    }

    private function resolveAsOfDate(CarbonImmutable $start, CarbonImmutable $end, CarbonInterface|string|null $value): CarbonImmutable
    {
        if ($value !== null) {
            $date = $value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)->setTimezone($this->businessTime->timezone())->startOfDay()
                : CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->businessTime->timezone());
            if ($date === false) {
                throw new InvalidArgumentException('as_of_dateは対象月内（未来月は月初前日も可）で指定してください。');
            }
            if ((is_string($value) && $date->format('Y-m-d') !== $value)
                || $date->lt($start->subDay()) || $date->gt($end)) {
                throw new InvalidArgumentException('as_of_dateは対象月内（未来月は月初前日も可）で指定してください。');
            }

            return $date;
        }

        $today = $this->businessTime->businessDate();
        if ($end->lt($today)) {
            return $end;
        }
        if ($start->gt($today)) {
            return $start->subDay();
        }

        return $today;
    }

    /** @param list<DailyBusinessSummary> $daily @return array<string, mixed> */
    private function totals(array $daily, SalesBasis $basis): array
    {
        $totals = [
            'payment_date_revenue' => 0, 'treatment_date_revenue' => 0, 'direct_treatment_revenue' => 0,
            'allocated_treatment_revenue' => 0, 'selected_revenue' => 0, 'visit_count' => 0,
            'long_visit_count' => 0, 'unknown_treatment_minutes_visit_count' => 0,
            'future_reservation_count' => 0, 'future_reservation_unknown_count' => 0,
            'first_visit_count' => 0, 'first_visit_reservation_count' => 0,
            'first_visit_reservation_unknown_count' => 0, 'unknown_analysis_category_visit_count' => 0,
            'accounting_pending_visit_count' => 0,
            'analysis_category_visit_counts' => ['M' => 0, 'T' => 0, 'A' => 0, 'M&T' => 0, 'A&T' => 0],
        ];
        $payments = [];
        $taxes = [];
        $split = DailyReportQuery::emptySalesSplit();
        $paymentCategories = [];
        foreach ($daily as $summary) {
            foreach ($summary->salesSplit as $category => $amounts) {
                foreach ($amounts as $amountKey => $amount) {
                    $split[$category][$amountKey] += $amount;
                }
            }
            foreach ($summary->paymentCategoryTotals as $payment) {
                $key = (string) $payment['payment_method_id'];
                $paymentCategories[$key] ??= [...$payment, 'amount' => 0, 'treatment_amount' => 0, 'retail_amount' => 0, 'unallocated_amount' => 0];
                foreach (['amount', 'treatment_amount', 'retail_amount', 'unallocated_amount'] as $amountKey) {
                    $paymentCategories[$key][$amountKey] += $payment[$amountKey];
                }
            }
            $totals['payment_date_revenue'] += $summary->paymentDateRevenue;
            $totals['treatment_date_revenue'] += $summary->treatmentDateRevenue;
            $totals['direct_treatment_revenue'] += $summary->directTreatmentRevenue;
            $totals['allocated_treatment_revenue'] += $summary->allocatedTreatmentRevenue;
            foreach (['visitCount' => 'visit_count', 'longVisitCount' => 'long_visit_count',
                'unknownTreatmentMinutesVisitCount' => 'unknown_treatment_minutes_visit_count',
                'futureReservationCount' => 'future_reservation_count', 'futureReservationUnknownCount' => 'future_reservation_unknown_count',
                'firstVisitCount' => 'first_visit_count', 'firstVisitReservationCount' => 'first_visit_reservation_count',
                'firstVisitReservationUnknownCount' => 'first_visit_reservation_unknown_count',
                'unknownAnalysisCategoryVisitCount' => 'unknown_analysis_category_visit_count',
                'accountingPendingVisitCount' => 'accounting_pending_visit_count'] as $property => $key) {
                $totals[$key] += $summary->{$property};
            }
            foreach ($summary->analysisCategoryVisitCounts as $code => $count) {
                $totals['analysis_category_visit_counts'][$code] = ($totals['analysis_category_visit_counts'][$code] ?? 0) + $count;
            }
            foreach ($summary->paymentMethodTotals as $payment) {
                $key = (string) $payment['payment_method_id'];
                $payments[$key] ??= [...$payment, 'amount' => 0];
                $payments[$key]['amount'] += $payment['amount'];
            }
            foreach ($summary->taxTotals as $tax) {
                $key = json_encode([
                    $tax['tax_category_code'], $tax['tax_category_name'], $tax['tax_rate_bps'],
                ], JSON_THROW_ON_ERROR);
                $taxes[$key] ??= [...$tax, 'net_amount' => 0, 'tax_amount' => 0, 'gross_amount' => 0, 'line_count' => 0];
                foreach (['net_amount', 'tax_amount', 'gross_amount', 'line_count'] as $amountKey) {
                    $taxes[$key][$amountKey] += $tax[$amountKey];
                }
            }
        }
        $totals['selected_revenue'] = $basis === SalesBasis::PaymentDate
            ? $totals['payment_date_revenue'] : $totals['treatment_date_revenue'];
        $totals['payment_method_totals'] = array_values($payments);
        $taxTotals = array_values($taxes);
        usort($taxTotals, static fn (array $left, array $right): int => ($left['tax_rate_bps'] ?? -1) <=> ($right['tax_rate_bps'] ?? -1));
        $totals['tax_totals'] = $taxTotals;
        $totals['sales_split'] = $split;
        $totals['net_sales'] = $split['treatment']['net'] + $split['retail']['net'];
        $totals['sales_tax'] = $split['treatment']['tax'] + $split['retail']['tax'];
        $totals['gross_sales'] = $split['treatment']['gross'] + $split['retail']['gross'];
        $totals['payment_category_totals'] = array_values($paymentCategories);

        return $totals;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, array<string, int>> */
    private function periodTotals(array $rows): array
    {
        $result = [
            'first' => ['selected_revenue' => 0, 'visit_count' => 0],
            'second' => ['selected_revenue' => 0, 'visit_count' => 0],
        ];
        foreach ($rows as $row) {
            $half = $row['period_half'];
            $result[$half]['selected_revenue'] += $row['selected_revenue'];
            $result[$half]['visit_count'] += $row['visit_count'];
        }

        return $result;
    }

    private function average(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : $numerator / $denominator;
    }
}
