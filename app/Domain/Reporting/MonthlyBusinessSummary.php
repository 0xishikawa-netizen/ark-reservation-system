<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use JsonSerializable;

/** Task 11-10（年間）とTask 11-11（Excel）からも再利用する月計read model。 */
final readonly class MonthlyBusinessSummary implements JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $dailyRows
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $actualTotals
     * @param  array<string, mixed>  $ratios
     * @param  array<string, mixed>  $actualRatios
     * @param  array<string, mixed>  $progress
     * @param  array<string, int>  $businessDays
     * @param  array<string, float|null>  $averages
     * @param  array<string, array<string, int>>  $periods
     * @param  list<array<string, mixed>>  $paymentMethods
     * @param  list<array<string, mixed>>  $taxBuckets
     */
    public function __construct(
        public int $year,
        public int $month,
        public string $monthKey,
        public string $asOfDate,
        public string $salesBasis,
        public array $dailyRows,
        public array $totals,
        public array $actualTotals,
        public array $ratios,
        public array $actualRatios,
        public ?int $target,
        public array $progress,
        public array $businessDays,
        public array $averages,
        public array $periods,
        public array $paymentMethods,
        public array $taxBuckets,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'month_key' => $this->monthKey,
            'as_of_date' => $this->asOfDate,
            'sales_basis' => $this->salesBasis,
            'daily_rows' => $this->dailyRows,
            'totals' => $this->totals,
            'actual_totals' => $this->actualTotals,
            'ratios' => $this->ratios,
            'actual_ratios' => $this->actualRatios,
            'target' => $this->target,
            'progress' => $this->progress,
            'business_days' => $this->businessDays,
            'averages' => $this->averages,
            'periods' => $this->periods,
            'payment_methods' => $this->paymentMethods,
            'tax_buckets' => $this->taxBuckets,
        ];
    }
}
