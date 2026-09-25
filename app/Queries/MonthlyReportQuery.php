<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StoreCalendarDay;
use Carbon\CarbonImmutable;

final class MonthlyReportQuery
{
    /** @return list<string> */
    public function closedDates(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return StoreCalendarDay::query()
            ->where('status', StoreCalendarDay::STATUS_CLOSED)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('business_date')
            ->pluck('business_date')
            ->map(static fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->all();
    }
}
