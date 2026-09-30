<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Business\StoreCalendarService;
use App\Models\StoreCalendarDay;
use Carbon\CarbonImmutable;

final class MonthlyReportQuery
{
    public function __construct(private readonly StoreCalendarService $calendar) {}

    /**
     * 休業日（例外日の休業と、毎週の定休日）。店舗カレンダーと同じ判定を使う。
     *
     * @return list<string>
     */
    public function closedDates(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return array_values(array_keys(array_filter(
            $this->calendar->resolveRange($start, $end),
            static fn (array $day): bool => $day['status'] === StoreCalendarDay::STATUS_CLOSED,
        )));
    }
}
