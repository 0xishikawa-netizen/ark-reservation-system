<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Queries\CustomerAnalyticsQuery;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use LogicException;

final class CustomerAnalyticsService
{
    public function __construct(
        private readonly CustomerAnalyticsQuery $query,
        private readonly BusinessTime $businessTime,
    ) {}

    public function forMonth(int $year, int $month, CarbonInterface|string|null $asOfDate = null): MonthlyCustomerSummary
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('年月の指定が不正です。');
        }
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone());
        $next = $start->addMonth();
        $previous = $start->subMonth();
        $twoMonthsAgo = $start->subMonths(2);
        $asOf = $this->asOfDate($asOfDate);
        $asOfExclusive = $asOf->addDay()->toDateString();
        $cohort = $this->query->newCohort($start->toDateString(), $next->toDateString(), $asOfExclusive);
        $visitIds = $cohort->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $categories = $this->query->firstVisitCategories($visitIds)->groupBy('visit_id');
        $count = $cohort->count();
        $reached = [2 => 0, 6 => 0, 10 => 0];
        $dimensions = ['course', 'visit_purpose', 'gender', 'age_at_first_visit', 'age_decade',
            'motivation', 'referrer', 'prefecture', 'municipality', 'first_staff',
            'future_reservation', 'reached_2', 'reached_6', 'reached_10'];
        $buckets = array_fill_keys($dimensions, []);

        foreach ($cohort as $first) {
            $maxSequence = (int) ($first->max_sequence ?? 0);
            foreach (array_keys($reached) as $threshold) {
                if ($maxSequence >= $threshold) {
                    $reached[$threshold]++;
                }
                $this->addBucket($buckets, "reached_{$threshold}", $maxSequence >= $threshold ? 'true' : 'false');
            }
            $this->addBucket($buckets, 'gender', $first->first_visit_gender_snapshot);
            $age = $first->first_visit_age_years_snapshot;
            $this->addBucket($buckets, 'age_at_first_visit', $age === null ? null : (string) $age);
            $decade = AgeDecadeBucket::codeFor($age === null ? null : (int) $age);
            $this->addBucket($buckets, 'age_decade', $decade, AgeDecadeBucket::labelFor($decade));
            $staffKey = $first->primary_staff_id !== null ? 'id:'.$first->primary_staff_id
                : ($first->primary_staff_name_snapshot === null ? null : 'name:'.$first->primary_staff_name_snapshot);
            $this->addBucket($buckets, 'first_staff', $staffKey, $first->primary_staff_name_snapshot);
            $reservation = $first->future_reservation_exists_at_checkout;
            $this->addBucket($buckets, 'future_reservation', $reservation === null ? null : ((int) $reservation === 1 ? 'true' : 'false'));
            $this->addBucket($buckets, 'course', $this->courseFor($categories->get($first->id)));
            foreach (['visit_purpose', 'motivation', 'referrer', 'prefecture', 'municipality'] as $unsupported) {
                $this->addBucket($buckets, $unsupported, null);
            }
        }
        if (! ($reached[10] <= $reached[6] && $reached[6] <= $reached[2] && $reached[2] <= $count)) {
            throw new LogicException('到達人数の順序が不正です。');
        }

        $breakdowns = [];
        foreach ($dimensions as $dimension) {
            $unsupported = in_array($dimension, ['visit_purpose', 'motivation', 'referrer', 'prefecture', 'municipality'], true);
            $breakdowns[$dimension] = [
                'status' => $unsupported ? 'not_captured' : 'available',
                'basis' => match ($dimension) {
                    'course' => 'first_visit_completed_treatment_analysis_category',
                    'gender', 'age_at_first_visit', 'age_decade' => 'first_visit_snapshot',
                    'first_staff', 'future_reservation' => 'first_visit_snapshot',
                    'reached_2', 'reached_6', 'reached_10' => 'completed_visits_as_of_date',
                    default => null,
                },
                'buckets' => array_values($buckets[$dimension]),
            ];
        }

        $reach = [];
        foreach ($reached as $threshold => $numerator) {
            $reach[(string) $threshold] = [
                'numerator' => $numerator,
                'denominator' => $count,
                'rate' => $count === 0 ? null : (float) ($numerator / $count),
            ];
        }
        $observedEnd = min($next->toDateString(), $asOfExclusive);

        return new MonthlyCustomerSummary(
            year: $year,
            month: $month,
            cohortMonth: $start->format('Y-m'),
            asOfDate: $asOf->toDateString(),
            newCustomers: $count,
            returningCustomers: $observedEnd <= $start->toDateString() ? 0 : $this->query->returningCount(
                $start->toDateString(), $observedEnd, $previous->toDateString(), $previous->toDateString(),
            ),
            churnCustomers: $asOf->lt($start->subDay()) ? null : $this->query->churnCount(
                $twoMonthsAgo->toDateString(), $previous->toDateString(), $start->toDateString(),
            ),
            reach: $reach,
            breakdowns: $breakdowns,
        );
    }

    private function asOfDate(CarbonInterface|string|null $value): CarbonImmutable
    {
        if ($value === null) {
            return $this->businessTime->businessDate();
        }
        $date = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->setTimezone($this->businessTime->timezone())->startOfDay()
            : CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->businessTime->timezone());
        if ($date === false || (is_string($value) && $date->toDateString() !== $value)) {
            throw new InvalidArgumentException('as_of_dateの指定が不正です。');
        }

        return $date;
    }

    /** @param array<string, array<string, array{value:string|null,label:string|null,count:int}>> $buckets */
    private function addBucket(array &$buckets, string $dimension, ?string $value, ?string $label = null): void
    {
        $key = $value === null ? '\0' : 'v:'.$value;
        $buckets[$dimension][$key] ??= ['value' => $value, 'label' => $label ?? $value, 'count' => 0];
        $buckets[$dimension][$key]['count']++;
    }

    private function courseFor(mixed $treatments): ?string
    {
        if ($treatments === null || $treatments->isEmpty()) {
            return null;
        }
        $codes = $treatments->pluck('analysis_category_code_snapshot')->unique()->sort()->values()->all();
        if (in_array(null, $codes, true)) {
            return null;
        }
        $key = implode('+', $codes);

        return match ($key) {
            'M', 'T', 'A', 'M&T', 'A&T' => $key,
            'M+T' => 'M&T',
            'A+T' => 'A&T',
            default => null,
        };
    }
}
