<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Reporting\SalesBasis;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** 月次と顧客cohortのread modelを再利用し、年率は必ず分子・分母の合計で算出する。 */
final class AnnualReportService
{
    public function __construct(
        private readonly MonthlyReportService $monthly,
        private readonly CustomerAnalyticsService $customers,
        private readonly StaffUtilizationService $staff,
        private readonly TimeBandUtilizationService $timeBands,
        private readonly BusinessTime $time,
    ) {}

    public const PERIOD_CALENDAR = 'calendar';

    public const PERIOD_FISCAL = 'fiscal';

    /** 事業年度の開始月（4月1日〜翌年3月31日。2026年度＝2026-04-01〜2027-03-31）。 */
    public const FISCAL_START_MONTH = 4;

    /**
     * 年間集計。period=calendar は1〜12月、fiscal は year年4月〜翌年3月（Task 11-24）。
     * どちらも各月は同じ月計・顧客・稼働のread modelで、年率は分子合計/分母合計。
     *
     * @return array<string,mixed>
     */
    public function forYear(int $year, SalesBasis|string $basis = SalesBasis::PaymentDate, ?string $asOfDate = null, string $period = self::PERIOD_CALENDAR): array
    {
        if (! in_array($period, [self::PERIOD_CALENDAR, self::PERIOD_FISCAL], true)) {
            throw new InvalidArgumentException('集計期間の指定が不正です。');
        }
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('年の指定が不正です。');
        }
        $salesBasis = $basis instanceof SalesBasis ? $basis : SalesBasis::tryFrom($basis);
        if ($salesBasis === null) {
            throw new InvalidArgumentException('売上基準の指定が不正です。');
        }
        $startMonth = $period === self::PERIOD_FISCAL ? self::FISCAL_START_MONTH : 1;
        $yearStart = CarbonImmutable::create($year, $startMonth, 1, 0, 0, 0, $this->time->timezone());
        $yearEnd = $yearStart->addYear()->subDay()->startOfDay();
        $today = $this->time->businessDate();
        $asOf = $asOfDate === null ? ($today->lt($yearStart) ? $yearStart->subDay() : ($today->lt($yearEnd) ? $today : $yearEnd))
            : CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate, $this->time->timezone());
        if ($asOf === false || ($asOfDate !== null && $asOf->toDateString() !== $asOfDate)
            || $asOf->lt($yearStart->subDay()) || $asOf->gt($yearEnd)) {
            throw new InvalidArgumentException('as_of_dateは対象期間内（未来の期間は開始前日も可）で指定してください。');
        }

        $rows = [];
        $asOfTotals = array_fill_keys(['payment_date_revenue', 'treatment_date_revenue', 'selected_revenue',
            'visit_count', 'long_visit_count', 'future_reservation_count', 'first_visit_count',
            'first_visit_reservation_count'], 0);
        $total = [
            'payment_date_revenue' => 0, 'treatment_date_revenue' => 0, 'selected_revenue' => 0,
            'target_known_amount' => 0, 'target_missing_months' => 0, 'elapsed_actual_amount' => 0,
            'visit_count' => 0, 'long_visit_count' => 0, 'future_reservation_count' => 0,
            'future_reservation_unknown_count' => 0, 'first_visit_count' => 0,
            'first_visit_reservation_count' => 0, 'first_visit_reservation_unknown_count' => 0,
            'new_customers' => 0, 'returning_customers' => 0, 'churn_known_customers' => 0,
            'churn_unknown_months' => 0, 'reached_2' => 0, 'reached_6' => 0, 'reached_10' => 0,
            'occupied_known_minutes' => 0, 'occupied_unknown_rows' => 0,
            'working_known_minutes' => 0, 'working_unknown_rows' => 0, 'bookable_minutes' => 0,
        ];
        $annualBands = [];
        foreach (TimeBandUtilizationService::BANDS as $band) {
            $annualBands[$band['code']] = ['band_code' => $band['code'], 'band_label' => $band['label'],
                'known_occupied_minutes' => 0, 'occupied_unknown_count' => 0,
                'bookable_minutes' => 0, 'working_known_minutes' => 0, 'working_unknown_months' => 0];
        }

        for ($offset = 0; $offset < 12; $offset++) {
            $monthStart = $yearStart->addMonths($offset);
            $calendarYear = $monthStart->year;
            $month = $monthStart->month;
            $monthEnd = $monthStart->endOfMonth()->startOfDay();
            $monthAsOf = $asOf->lt($monthStart) ? $monthStart->subDay() : ($asOf->gt($monthEnd) ? $monthEnd : $asOf);
            $monthDate = $monthAsOf->toDateString();
            $business = $this->monthly->forMonth($calendarYear, $month, $salesBasis, $monthDate);
            $customer = $this->customers->forMonth($calendarYear, $month, $asOf->toDateString());
            $staff = $this->staff->forMonth($calendarYear, $month, asOfDate: $monthDate);
            $bands = $this->timeBands->forMonth($calendarYear, $month, asOfDate: $monthDate);
            $staffValues = $this->staffTotals($staff['monthly_rows']);
            $row = [
                'year' => $calendarYear, 'month' => $month, 'month_key' => $business->monthKey, 'as_of_date' => $monthDate,
                'is_future' => $monthAsOf->lt($monthStart),
                'actual_totals' => $business->actualTotals,
                'payment_date_revenue' => $business->totals['payment_date_revenue'],
                'treatment_date_revenue' => $business->totals['treatment_date_revenue'],
                'selected_revenue' => $business->totals['selected_revenue'],
                'target_amount' => $business->target, 'elapsed_actual_amount' => $business->progress['actual_amount'],
                'achievement_rate' => $business->progress['achievement_rate'],
                'visit_count' => $business->totals['visit_count'], 'long_visit_count' => $business->totals['long_visit_count'],
                'future_reservation_count' => $business->totals['future_reservation_count'],
                'future_reservation_unknown_count' => $business->totals['future_reservation_unknown_count'],
                'reservation_rate' => $business->ratios['future_reservation_rate']->value,
                'first_visit_count' => $business->totals['first_visit_count'],
                'first_visit_reservation_count' => $business->totals['first_visit_reservation_count'],
                'first_visit_reservation_unknown_count' => $business->totals['first_visit_reservation_unknown_count'],
                'first_visit_reservation_rate' => $business->ratios['first_visit_reservation_rate']->value,
                'new_customers' => $customer->newCustomers, 'returning_customers' => $customer->returningCustomers,
                'churn_customers' => $customer->churnCustomers,
                'reached_2' => $customer->reach['2']['numerator'], 'reached_6' => $customer->reach['6']['numerator'],
                'reached_10' => $customer->reach['10']['numerator'],
                'reach_2_rate' => $customer->reach['2']['rate'], 'reach_6_rate' => $customer->reach['6']['rate'],
                'reach_10_rate' => $customer->reach['10']['rate'],
                ...$staffValues, 'time_bands' => $bands['overall_rows'],
            ];
            $rows[] = $row;
            foreach ($asOfTotals as $key => $value) {
                $asOfTotals[$key] += $business->actualTotals[$key];
            }
            foreach (['payment_date_revenue', 'treatment_date_revenue', 'selected_revenue', 'elapsed_actual_amount',
                'visit_count', 'long_visit_count', 'future_reservation_count', 'future_reservation_unknown_count',
                'first_visit_count', 'first_visit_reservation_count', 'first_visit_reservation_unknown_count',
                'new_customers', 'returning_customers', 'reached_2', 'reached_6', 'reached_10',
                'bookable_minutes'] as $key) {
                $total[$key] += $row[$key];
            }
            if ($row['target_amount'] === null) {
                $total['target_missing_months']++;
            } else {
                $total['target_known_amount'] += $row['target_amount'];
            }
            if ($row['churn_customers'] === null) {
                $total['churn_unknown_months']++;
            } else {
                $total['churn_known_customers'] += $row['churn_customers'];
            }
            $total['occupied_known_minutes'] += $staffValues['occupied_known_minutes'];
            $total['occupied_unknown_rows'] += $staffValues['occupied_unknown_rows'];
            $total['working_known_minutes'] += $staffValues['working_known_minutes'];
            $total['working_unknown_rows'] += $staffValues['working_unknown_rows'];
            foreach ($bands['overall_rows'] as $band) {
                $annualBand = &$annualBands[$band['band_code']];
                $annualBand['known_occupied_minutes'] += $band['known_occupied_minutes'];
                $annualBand['occupied_unknown_count'] += $band['occupied_unknown_count'];
                $annualBand['bookable_minutes'] += $band['bookable_minutes'];
                $annualBand['working_known_minutes'] += $band['working_minutes'] ?? 0;
                $annualBand['working_unknown_months'] += $band['working_minutes'] === null && $band['working_unknown_count'] > 0 ? 1 : 0;
                unset($annualBand);
            }
        }

        $total['target_amount'] = $total['target_missing_months'] === 0 ? $total['target_known_amount'] : null;
        $total['churn_customers'] = $total['churn_unknown_months'] === 0 ? $total['churn_known_customers'] : null;
        $total['occupied_minutes'] = $total['occupied_unknown_rows'] === 0 ? $total['occupied_known_minutes'] : null;
        $total['working_minutes'] = $total['working_unknown_rows'] === 0 ? $total['working_known_minutes'] : null;
        $total['achievement_rate'] = $this->rate($total['elapsed_actual_amount'], $total['target_amount']);
        $total['reservation_rate'] = $this->rate($total['future_reservation_count'], $total['visit_count']);
        $total['first_visit_reservation_rate'] = $this->rate($total['first_visit_reservation_count'], $total['first_visit_count']);
        foreach ([2, 6, 10] as $threshold) {
            $total["reach_{$threshold}_rate"] = $this->rate($total["reached_{$threshold}"], $total['new_customers']);
        }
        $total['legacy_utilization_rate'] = $this->rate($total['occupied_minutes'], $total['working_minutes']);
        $total['bookable_utilization_rate'] = $this->rate($total['occupied_minutes'], $total['bookable_minutes']);
        foreach ($annualBands as &$band) {
            $band['occupied_minutes'] = $band['occupied_unknown_count'] === 0 ? $band['known_occupied_minutes'] : null;
            $band['working_minutes'] = $band['working_unknown_months'] === 0 ? $band['working_known_minutes'] : null;
            $band['legacy_utilization_rate'] = $this->rate($band['occupied_minutes'], $band['working_minutes']);
            $band['bookable_utilization_rate'] = $this->rate($band['occupied_minutes'], $band['bookable_minutes']);
        }
        unset($band);

        return ['year' => $year, 'period' => $period, 'period_start' => $yearStart->toDateString(), 'period_end' => $yearEnd->toDateString(),
            'as_of_date' => $asOf->toDateString(), 'sales_basis' => $salesBasis->value,
            'months' => $rows, 'totals' => $total, 'as_of_totals' => $asOfTotals,
            'time_bands' => array_values($annualBands)];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int|float|null> */
    private function staffTotals(array $rows): array
    {
        $occupied = 0;
        $working = 0;
        $bookable = 0;
        $occupiedUnknown = 0;
        $workingUnknown = 0;
        foreach ($rows as $row) {
            $occupied += $row['occupied_minutes'] ?? 0;
            $working += $row['working_minutes'] ?? 0;
            $bookable += $row['bookable_minutes'];
            $occupiedUnknown += $row['occupied_minutes'] === null ? 1 : 0;
            $workingUnknown += $row['working_minutes'] === null ? 1 : 0;
        }

        return ['occupied_known_minutes' => $occupied, 'occupied_unknown_rows' => $occupiedUnknown,
            'occupied_minutes' => $occupiedUnknown === 0 ? $occupied : null,
            'working_known_minutes' => $working, 'working_unknown_rows' => $workingUnknown,
            'working_minutes' => $workingUnknown === 0 ? $working : null,
            'bookable_minutes' => $bookable,
            'legacy_utilization_rate' => $this->rate($occupiedUnknown === 0 ? $occupied : null, $workingUnknown === 0 ? $working : null),
            'bookable_utilization_rate' => $this->rate($occupiedUnknown === 0 ? $occupied : null, $bookable)];
    }

    private function rate(?int $numerator, ?int $denominator): ?float
    {
        return $numerator === null || $denominator === null || $denominator === 0 ? null : $numerator / $denominator;
    }
}
