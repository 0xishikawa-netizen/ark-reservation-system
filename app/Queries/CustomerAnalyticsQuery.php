<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Visit事実だけを参照する顧客単位の集合演算。顧客数に依存しないSQL本数を保つ。 */
final class CustomerAnalyticsQuery
{
    /** @return Collection<int, object> */
    public function newCohort(string $start, string $endExclusive, string $asOfExclusive): Collection
    {
        $cohort = $this->firstVisits($start, $endExclusive, $asOfExclusive);
        $maxSequences = DB::table('visits as reached')
            ->where('reached.status', VisitStatus::Completed->value)
            ->where('reached.business_date', '<', $asOfExclusive)
            ->whereIn('reached.customer_id', $this->firstVisits($start, $endExclusive, $asOfExclusive)->select('first.customer_id'))
            ->groupBy('reached.customer_id')
            ->selectRaw('reached.customer_id, MAX(reached.visit_sequence) AS max_sequence');

        return $cohort->leftJoinSub($maxSequences, 'reach', 'reach.customer_id', '=', 'first.customer_id')
            ->orderBy('first.id')
            ->get([
                'first.id', 'first.customer_id', 'first.business_date',
                'first.first_visit_gender_snapshot', 'first.first_visit_age_years_snapshot',
                'first.primary_staff_id', 'first.primary_staff_name_snapshot',
                'first.future_reservation_exists_at_checkout', 'reach.max_sequence',
            ]);
    }

    /** @param list<int> $visitIds @return Collection<int, object> */
    public function firstVisitCategories(array $visitIds): Collection
    {
        if ($visitIds === []) {
            return collect();
        }

        return DB::table('visit_treatments')
            ->whereIn('visit_id', $visitIds)
            ->where('status', VisitTreatmentStatus::Completed->value)
            ->get(['visit_id', 'analysis_category_code_snapshot']);
    }

    public function returningCount(string $start, string $endExclusive, string $previousStart, string $oldEnd): int
    {
        return DB::table('visits as current')
            ->where('current.status', VisitStatus::Completed->value)
            ->where('current.business_date', '>=', $start)->where('current.business_date', '<', $endExclusive)
            ->whereNotExists(function (Builder $query) use ($previousStart, $start): void {
                $this->relatedCompleted($query, 'previous', 'current.customer_id')
                    ->where('previous.business_date', '>=', $previousStart)
                    ->where('previous.business_date', '<', $start);
            })
            ->whereExists(function (Builder $query) use ($oldEnd): void {
                $this->relatedCompleted($query, 'older', 'current.customer_id')
                    ->where('older.business_date', '<', $oldEnd);
            })
            ->whereNotExists(function (Builder $query) use ($start, $endExclusive): void {
                $this->relatedCompleted($query, 'first_in_month', 'current.customer_id')
                    ->where('first_in_month.visit_sequence', 1)
                    ->where('first_in_month.business_date', '>=', $start)
                    ->where('first_in_month.business_date', '<', $endExclusive);
            })
            ->distinct()->count('current.customer_id');
    }

    public function churnCount(string $twoMonthsAgoStart, string $previousStart, string $currentStart): int
    {
        return DB::table('visits as two_months_ago')
            ->where('two_months_ago.status', VisitStatus::Completed->value)
            ->where('two_months_ago.business_date', '>=', $twoMonthsAgoStart)
            ->where('two_months_ago.business_date', '<', $previousStart)
            ->whereNotExists(function (Builder $query) use ($previousStart, $currentStart): void {
                $this->relatedCompleted($query, 'previous', 'two_months_ago.customer_id')
                    ->where('previous.business_date', '>=', $previousStart)
                    ->where('previous.business_date', '<', $currentStart);
            })
            ->distinct()->count('two_months_ago.customer_id');
    }

    private function firstVisits(string $start, string $endExclusive, string $asOfExclusive): Builder
    {
        return DB::table('visits as first')
            ->where('first.status', VisitStatus::Completed->value)
            ->where('first.visit_sequence', 1)
            ->where('first.business_date', '>=', $start)
            ->where('first.business_date', '<', $endExclusive)
            ->where('first.business_date', '<', $asOfExclusive);
    }

    private function relatedCompleted(Builder $query, string $alias, string $customerColumn): Builder
    {
        return $query->selectRaw('1')->from("visits as {$alias}")
            ->whereColumn("{$alias}.customer_id", $customerColumn)
            ->where("{$alias}.status", VisitStatus::Completed->value);
    }
}
