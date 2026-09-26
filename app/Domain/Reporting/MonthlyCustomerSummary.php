<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use JsonSerializable;

final readonly class MonthlyCustomerSummary implements JsonSerializable
{
    /** @param array<string, array{numerator:int,denominator:int,rate:float|null}> $reach
     * @param  array<string, array{status:string,basis:string|null,buckets:list<array{value:string|null,label:string|null,count:int}>}>  $breakdowns
     */
    public function __construct(
        public int $year,
        public int $month,
        public string $cohortMonth,
        public string $asOfDate,
        public int $newCustomers,
        public int $returningCustomers,
        public ?int $churnCustomers,
        public array $reach,
        public array $breakdowns,
        /** 来店動機別・初回担当別の新規数と2回目到達（Task 11-21）。 */
        public array $crossTabs = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'cohort_month' => $this->cohortMonth,
            'as_of_date' => $this->asOfDate,
            'new_customers' => $this->newCustomers,
            'returning_customers' => $this->returningCustomers,
            'churn_customers' => $this->churnCustomers,
            'reach' => $this->reach,
            'breakdowns' => $this->breakdowns,
            'cross_tabs' => $this->crossTabs,
        ];
    }
}
