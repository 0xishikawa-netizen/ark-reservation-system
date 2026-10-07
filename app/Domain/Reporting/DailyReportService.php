<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Queries\DailyReportQuery;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class DailyReportService
{
    private const WEEKDAYS = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'];

    public function __construct(
        private readonly DailyReportQuery $query,
        private readonly BusinessTime $businessTime,
    ) {}

    public function forDate(CarbonInterface|string $date): DailyBusinessSummary
    {
        $businessDate = $this->normalizeDate($date);
        $facts = $this->query->fetch($businessDate);

        return $this->summaryFromFacts($businessDate, $facts);
    }

    /** @return list<DailyBusinessSummary> */
    public function forRange(CarbonInterface|string $from, CarbonInterface|string $through): array
    {
        $start = $this->normalizeDate($from);
        $end = $this->normalizeDate($through);
        if ($end->lt($start)) {
            throw new InvalidArgumentException('集計終了日は開始日以降で指定してください。');
        }

        $factsByDate = $this->query->fetchRange($start, $end->addDay());
        $summaries = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $summaries[] = $this->summaryFromFacts(
                $date,
                $factsByDate[$date->toDateString()] ?? $this->emptyFacts(),
            );
        }

        return $summaries;
    }

    /** @param array<string, mixed> $facts */
    private function summaryFromFacts(CarbonImmutable $businessDate, array $facts): DailyBusinessSummary
    {
        $visits = $facts['visits'];
        $durations = $facts['durations'];
        $categoryCounts = ['M' => 0, 'T' => 0, 'A' => 0, 'M&T' => 0, 'A&T' => 0];
        foreach ($facts['categories'] as $category) {
            $categoryCounts[$category['code']] = ($categoryCounts[$category['code']] ?? 0) + $category['visit_count'];
        }
        $paymentDateRevenue = array_sum(array_column($facts['payment_methods'], 'amount'));
        $treatmentDateRevenue = $facts['direct_treatment_revenue'] + $facts['allocated_treatment_revenue'];

        return new DailyBusinessSummary(
            businessDate: $businessDate->toDateString(),
            weekdayIso: $businessDate->dayOfWeekIso,
            weekday: self::WEEKDAYS[$businessDate->dayOfWeekIso],
            paymentDateRevenue: $paymentDateRevenue,
            visitGross: $visits['visit_gross'],
            treatmentDateRevenue: $treatmentDateRevenue,
            directTreatmentRevenue: $facts['direct_treatment_revenue'],
            allocatedTreatmentRevenue: $facts['allocated_treatment_revenue'],
            paymentMethodTotals: $facts['payment_methods'],
            taxTotals: $facts['taxes'],
            visitCount: $visits['visit_count'],
            longVisitCount: $durations['long_visit_count'],
            unknownTreatmentMinutesVisitCount: $durations['unknown_treatment_minutes_visit_count'],
            futureReservationCount: $visits['future_reservation_count'],
            futureReservationUnknownCount: $visits['future_reservation_unknown_count'],
            futureReservationRate: new DailyRatio($visits['future_reservation_count'], $visits['visit_count']),
            firstVisitCount: $visits['first_visit_count'],
            firstVisitReservationCount: $visits['first_visit_reservation_count'],
            firstVisitReservationUnknownCount: $visits['first_visit_reservation_unknown_count'],
            firstVisitReservationRate: new DailyRatio($visits['first_visit_reservation_count'], $visits['first_visit_count']),
            analysisCategoryVisitCounts: $categoryCounts,
            unknownAnalysisCategoryVisitCount: $facts['unknown_category_visits'],
            accountingPendingVisitCount: $visits['accounting_pending_visit_count'],
            salesSplit: $facts['sales_split'],
            paymentCategoryTotals: $facts['payment_categories'],
        );
    }

    /** @return array<string, mixed> */
    private function emptyFacts(): array
    {
        return [
            'visits' => [
                'visit_count' => 0,
                'future_reservation_count' => 0,
                'future_reservation_unknown_count' => 0,
                'first_visit_count' => 0,
                'first_visit_reservation_count' => 0,
                'first_visit_reservation_unknown_count' => 0,
                'accounting_pending_visit_count' => 0,
                'visit_gross' => 0,
            ],
            'durations' => [
                'long_visit_count' => 0,
                'unknown_treatment_minutes_visit_count' => 0,
            ],
            'categories' => [],
            'unknown_category_visits' => 0,
            'payment_methods' => [],
            'taxes' => [],
            'sales_split' => DailyReportQuery::emptySalesSplit(),
            'payment_categories' => [],
            'direct_treatment_revenue' => 0,
            'allocated_treatment_revenue' => 0,
        ];
    }

    private function normalizeDate(CarbonInterface|string $date): CarbonImmutable
    {
        $timezone = $this->businessTime->timezone();
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone($timezone)->startOfDay();
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('business dateはY-m-d形式で指定してください。');
        }

        return $parsed;
    }
}
