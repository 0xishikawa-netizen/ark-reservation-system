<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\AgeDecadeBucket;
use App\Domain\Reporting\CustomerAnalyticsService;
use App\Domain\Reporting\MonthlyCustomerSummary;
use App\Domain\Reporting\MonthlyReportService;
use App\Enums\Visit\VisitStatus;
use App\Models\Customer;
use App\Models\Visit;
use App\Models\VisitTreatment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustomerAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_is_unique_and_matches_monthly_first_visits_with_completed_facts_only(): void
    {
        $first = Customer::factory()->create();
        $this->visit($first, '2026-10-03', 1);
        $this->visit($first, '2026-10-10', 2);
        $prior = Customer::factory()->create();
        $this->visit($prior, '2026-09-20', 1);
        $this->visit($prior, '2026-10-10', 2);
        $cancelled = Customer::factory()->create();
        $this->visit($cancelled, '2026-09-20', null, VisitStatus::Voided);
        $this->visit($cancelled, '2026-10-11', 1);

        $summary = $this->report(2026, 10, '2026-10-31');
        $monthly = app(MonthlyReportService::class)->forMonth(2026, 10, asOfDate: '2026-10-31');
        $this->assertSame(2, $summary->newCustomers);
        $this->assertSame($monthly->totals['first_visit_count'], $summary->newCustomers);
        $this->assertSame(1, $summary->reach['2']['numerator']);
    }

    public function test_returning_and_churn_can_overlap_and_cross_year_boundaries(): void
    {
        $overlap = Customer::factory()->create();
        $this->visit($overlap, '2026-11-09', 1);
        $this->visit($overlap, '2027-01-07', 2);
        $stayed = Customer::factory()->create();
        $this->visit($stayed, '2026-11-10', 1);
        $this->visit($stayed, '2026-12-10', 2);
        $this->visit($stayed, '2027-01-10', 3);
        $older = Customer::factory()->create();
        $this->visit($older, '2026-10-01', 1);
        $this->visit($older, '2027-01-20', 2);
        $churnOnly = Customer::factory()->create();
        $this->visit($churnOnly, '2026-11-12', 1);
        $new = Customer::factory()->create();
        $this->visit($new, '2027-01-12', 1);
        $this->visit($new, '2027-01-20', 2);

        $summary = $this->report(2027, 1, '2027-01-31');
        $this->assertSame(1, $summary->newCustomers);
        $this->assertSame(2, $summary->returningCustomers);
        $this->assertSame(2, $summary->churnCustomers);
    }

    public function test_cohort_reach_uses_as_of_date_and_keeps_recent_cohort_in_denominator(): void
    {
        for ($customerIndex = 0; $customerIndex < 10; $customerIndex++) {
            $customer = Customer::factory()->create();
            $this->visit($customer, '2026-10-01', 1);
            $max = $customerIndex === 0 ? 10 : ($customerIndex < 3 ? 6 : ($customerIndex < 6 ? 2 : 1));
            for ($sequence = 2; $sequence <= $max; $sequence++) {
                $this->visit($customer, sprintf('2026-10-%02d', $sequence + 1), $sequence);
            }
        }
        $summary = $this->report(2026, 10, '2026-10-31');
        $this->assertSame(10, $summary->newCustomers);
        $this->assertSame([6, 3, 1], array_map(fn (int $n): int => $summary->reach[(string) $n]['numerator'], [2, 6, 10]));
        $this->assertSame([0.6, 0.3, 0.1], array_map(fn (int $n): float => $summary->reach[(string) $n]['rate'], [2, 6, 10]));

        $early = $this->report(2026, 10, '2026-10-01');
        $this->assertSame(10, $early->reach['6']['denominator']);
        $this->assertSame(0, $early->reach['6']['numerator']);
        $this->assertSame(0.0, $early->reach['6']['rate']);
        $this->assertSame(0, $early->reach['2']['numerator']);
    }

    public function test_breakdowns_preserve_unknown_and_snapshot_values_after_profile_change(): void
    {
        $known = Customer::factory()->create(['gender' => 'male']);
        $first = $this->visit($known, '2026-10-03', 1, VisitStatus::Completed, [
            'first_visit_gender_snapshot' => 'male', 'first_visit_age_years_snapshot' => 29,
            'primary_staff_name_snapshot' => '当時の担当', 'future_reservation_exists_at_checkout' => true,
        ]);
        VisitTreatment::factory()->create(['visit_id' => $first->id, 'status' => 'completed', 'analysis_category_code_snapshot' => 'M']);
        $unknown = Customer::factory()->create();
        $this->visit($unknown, '2026-10-04', 1);
        $known->update(['gender' => 'female']);

        $summary = $this->report(2026, 10, '2026-10-31');
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['gender']['buckets'], 'male'));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['gender']['buckets'], null));
        $this->assertSame(0, $this->bucketCount($summary->breakdowns['gender']['buckets'], 'female'));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['age_at_first_visit']['buckets'], '29'));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['age_at_first_visit']['buckets'], null));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['age_decade']['buckets'], '20-29'));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['age_decade']['buckets'], null));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['course']['buckets'], 'M'));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['future_reservation']['buckets'], 'true'));
        $this->assertSame(1, $this->bucketCount($summary->breakdowns['future_reservation']['buckets'], null));
        $this->assertSame('not_captured', $summary->breakdowns['prefecture']['status']);
        $this->assertSame(2, $this->bucketCount($summary->breakdowns['prefecture']['buckets'], null));
    }

    public function test_age_decade_boundaries_and_unknown_include_every_new_customer(): void
    {
        $ages = [0, 9, 10, 19, 20, 29, 30, 39, 40, 49, 50, 59, 60, 69, 70, 79, 80, 89, 90, 104, null];
        foreach ($ages as $age) {
            $this->visit(Customer::factory()->create(), '2026-10-03', 1, VisitStatus::Completed,
                ['first_visit_age_years_snapshot' => $age]);
        }
        $summary = $this->report(2026, 10, '2026-10-31');
        $buckets = $summary->breakdowns['age_decade']['buckets'];
        $this->assertSame('available', $summary->breakdowns['age_decade']['status']);
        $this->assertSame(count($ages), array_sum(array_column($buckets, 'count')));
        foreach (AgeDecadeBucket::LABELS as $code => $label) {
            $this->assertSame(2, $this->bucketCount($buckets, $code), $label);
        }
        $this->assertSame(1, $this->bucketCount($buckets, null));
    }

    public function test_course_uses_first_completed_treatment_category_set_without_guessing(): void
    {
        $combined = $this->visit(Customer::factory()->create(), '2026-10-03', 1);
        VisitTreatment::factory()->create(['visit_id' => $combined->id, 'status' => 'completed', 'analysis_category_code_snapshot' => 'M']);
        VisitTreatment::factory()->create(['visit_id' => $combined->id, 'status' => 'completed', 'analysis_category_code_snapshot' => 'T']);
        $unknown = $this->visit(Customer::factory()->create(), '2026-10-04', 1);
        VisitTreatment::factory()->create(['visit_id' => $unknown->id, 'status' => 'completed', 'analysis_category_code_snapshot' => null]);

        $buckets = $this->report(2026, 10, '2026-10-31')->breakdowns['course']['buckets'];
        $this->assertSame(1, $this->bucketCount($buckets, 'M&T'));
        $this->assertSame(1, $this->bucketCount($buckets, null));
    }

    public function test_query_count_does_not_scale_with_cohort_size_and_empty_rates_are_null(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'visits') || str_contains($query->sql, 'visit_treatments')) {
                $queries[] = $query->sql;
            }
        });
        $this->assertNull($this->report(2026, 10, '2026-10-31')->reach['2']['rate']);
        $emptyCount = count($queries);
        for ($i = 0; $i < 30; $i++) {
            $this->visit(Customer::factory()->create(), '2026-10-03', 1);
        }
        $queries = [];
        $this->assertSame(30, $this->report(2026, 10, '2026-10-31')->newCustomers);
        $this->assertSame($emptyCount + 1, count($queries));
    }

    private function report(int $year, int $month, string $asOf): MonthlyCustomerSummary
    {
        return app(CustomerAnalyticsService::class)->forMonth($year, $month, $asOf);
    }

    /** @param array<string, mixed> $attributes */
    private function visit(Customer $customer, string $date, ?int $sequence, VisitStatus $status = VisitStatus::Completed, array $attributes = []): Visit
    {
        return Visit::factory()->create(array_merge([
            'customer_id' => $customer->user_id, 'business_date' => $date,
            'status' => $status, 'visit_sequence' => $sequence,
        ], $attributes));
    }

    /** @param list<array{value:string|null,label:string|null,count:int}> $buckets */
    private function bucketCount(array $buckets, ?string $value): int
    {
        foreach ($buckets as $bucket) {
            if ($bucket['value'] === $value) {
                return $bucket['count'];
            }
        }

        return 0;
    }
}
