<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Business\StoreCalendarService;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Task 11-8の半開区間演算と同じ勤務・休憩・ブロック正本を時間帯へ切り分ける。 */
final class TimeBandUtilizationService
{
    public const BANDS = [
        ['code' => '10_12', 'label' => '10:00–12:00', 'start' => 600, 'end' => 720],
        ['code' => '12_15', 'label' => '12:00–15:00', 'start' => 720, 'end' => 900],
        ['code' => '15_18', 'label' => '15:00–18:00', 'start' => 900, 'end' => 1080],
        ['code' => '18_21', 'label' => '18:00–21:00', 'start' => 1080, 'end' => 1260],
    ];

    public function __construct(private readonly BusinessTime $time, private readonly StoreCalendarService $calendar) {}

    /** @return array<string,mixed> */
    public function forMonth(int $year, int $month, ?int $staffId = null, ?string $asOfDate = null): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('年月の指定が不正です。');
        }
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->time->timezone());
        $end = $start->endOfMonth()->startOfDay();
        $today = $this->time->businessDate();
        $asOf = $asOfDate === null ? ($today->lt($start) ? $start->subDay() : ($today->lt($end) ? $today : $end))
            : CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate, $this->time->timezone());
        if ($asOf === false || ($asOfDate !== null && $asOf->toDateString() !== $asOfDate)
            || $asOf->lt($start->subDay()) || $asOf->gt($end)) {
            throw new InvalidArgumentException('as_of_dateは対象月内で指定してください。');
        }

        $members = Staff::query()->when($staffId !== null, static fn ($q) => $q->where('user_id', $staffId))
            ->orderBy('sort_order')->orderBy('user_id')->get(['user_id', 'display_name', 'is_bookable']);
        $first = $start->toDateString();
        $last = $end->toDateString();
        $shifts = StaffShift::query()->whereBetween('work_date', [$first, $last])->get()
            ->groupBy(static fn (StaffShift $s): string => $s->staff_id.'|'.$s->work_date->toDateString());
        $blocks = StaffScheduleBlock::query()->whereNotNull('staff_id')->whereBetween('work_date', [$first, $last])->get()
            ->groupBy(static fn (StaffScheduleBlock $b): string => $b->staff_id.'|'.$b->work_date->toDateString());
        $calendar = $this->calendar->resolveRange($start, $end);
        $utcStart = $start->subHours(36)->utc()->format('Y-m-d H:i:s');
        $utcEnd = $end->addDay()->utc()->format('Y-m-d H:i:s');
        $attendances = StaffAttendance::query()->with('breaks')
            ->where(static fn ($q) => $q->whereBetween('clock_in_at', [$utcStart, $utcEnd])
                ->orWhere(static fn ($missing) => $missing->whereNull('clock_in_at')->whereBetween('business_date', [$first, $last])))
            ->get()->groupBy('staff_id');

        // 時刻のない旧実績はどの帯にも推測配賦せず、対象日の全帯をunknownにする。
        $assignments = DB::table('visit_treatment_staff as a')
            ->join('visit_treatments as t', 't.id', '=', 'a.visit_treatment_id')
            ->join('visits as v', 'v.id', '=', 't.visit_id')
            ->where('v.status', 'completed')->where('t.status', 'completed')->whereNotNull('a.staff_id')
            ->when($staffId !== null, static fn ($q) => $q->where('a.staff_id', $staffId))
            ->where(static fn ($q) => $q->whereBetween('v.business_date', [$first, $last])
                ->orWhere(static fn ($overlap) => $overlap->where('a.actual_started_at', '<', $utcEnd)
                    ->where('a.actual_ended_at', '>', $utcStart)))
            ->select('a.staff_id', 'a.actual_started_at', 'a.actual_ended_at', 'a.actual_minutes', 'v.business_date', 'v.id as visit_id')->get();
        $occupied = [];
        $unknown = [];
        $outside = [];
        // 時間帯別人数（Task 11-23）: 実担当区間が帯と1分以上重なった完了来店。店舗はVisit単位、スタッフはスタッフ×Visit単位で重複を除く。
        $storeVisits = [];
        $staffVisits = [];
        $unknownVisits = [];
        foreach ($assignments as $assignment) {
            $staffKey = (int) $assignment->staff_id;
            $fallbackDate = (string) $assignment->business_date;
            $visitId = (int) $assignment->visit_id;
            if ($assignment->actual_started_at === null || $assignment->actual_ended_at === null || $assignment->actual_minutes === null) {
                $unknown[$staffKey.'|'.$fallbackDate] = ($unknown[$staffKey.'|'.$fallbackDate] ?? 0) + 1;
                $unknownVisits[$fallbackDate][$visitId] = true;

                continue;
            }
            $from = CarbonImmutable::parse((string) $assignment->actual_started_at, 'UTC')->setTimezone($this->time->timezone());
            $to = CarbonImmutable::parse((string) $assignment->actual_ended_at, 'UTC')->setTimezone($this->time->timezone());
            if ($to->lte($from) || $from->timestamp % 60 !== 0 || $to->timestamp % 60 !== 0
                || ($to->timestamp - $from->timestamp) !== ((int) $assignment->actual_minutes) * 60) {
                $unknown[$staffKey.'|'.$fallbackDate] = ($unknown[$staffKey.'|'.$fallbackDate] ?? 0) + 1;
                $unknownVisits[$fallbackDate][$visitId] = true;

                continue;
            }
            for ($day = $from->startOfDay(); $day->lt($to); $day = $day->addDay()) {
                $date = $day->toDateString();
                if ($date < $first || $date > $asOf->toDateString()) {
                    continue;
                }
                $segmentStart = max($from->timestamp, $day->timestamp);
                $segmentEnd = min($to->timestamp, $day->addDay()->timestamp);
                $range = [[intdiv($segmentStart - $day->timestamp, 60), intdiv($segmentEnd - $day->timestamp, 60)]];
                $allocated = 0;
                foreach (self::BANDS as $band) {
                    $minutes = MinuteIntervals::minutes(MinuteIntervals::intersect($range, [[$band['start'], $band['end']]]));
                    $key = $staffKey.'|'.$date.'|'.$band['code'];
                    $occupied[$key] = ($occupied[$key] ?? 0) + $minutes;
                    $allocated += $minutes;
                    if ($minutes >= 1) {
                        $storeVisits[$date.'|'.$band['code']][$visitId] = true;
                        $staffVisits[$key][$visitId] = true;
                    }
                }
                $outside[$staffKey.'|'.$date] = ($outside[$staffKey.'|'.$date] ?? 0) + MinuteIntervals::minutes($range) - $allocated;
            }
        }

        $daily = [];
        $monthly = [];
        $overall = [];
        $storeDaily = [];
        $dayTypes = [];
        foreach ($members as $member) {
            for ($day = $start; $day->lte($asOf); $day = $day->addDay()) {
                $date = $day->toDateString();
                $key = $member->user_id.'|'.$date;
                $shiftRanges = [];
                foreach ($shifts->get($key, collect()) as $shift) {
                    $shiftRanges[] = [$this->minute($shift->start_at), $this->minute($shift->end_at)];
                }
                $shiftRanges = MinuteIntervals::union($shiftRanges);
                $blockRanges = [];
                foreach ($blocks->get($key, collect()) as $block) {
                    $blockRanges[] = [$this->minute($block->start_at), $this->minute($block->end_at)];
                }
                $dayStart = $day->timestamp;
                $dayEnd = $day->addDay()->timestamp;
                $actual = [];
                $breaks = [];
                $hasAttendance = false;
                $incomplete = false;
                foreach ($attendances->get($member->user_id, collect()) as $attendance) {
                    if ($attendance->clock_in_at === null) {
                        if ($attendance->business_date->toDateString() === $date) {
                            $hasAttendance = true;
                            $incomplete = true;
                        }

                        continue;
                    }
                    $in = $attendance->clock_in_at->timestamp;
                    $out = $attendance->clock_out_at?->timestamp;
                    if ($in >= $dayEnd || ($out !== null && $out <= $dayStart)) {
                        continue;
                    }
                    if ($out === null && $attendance->business_date->toDateString() !== $date) {
                        continue;
                    }
                    $hasAttendance = true;
                    if ($out === null) {
                        $incomplete = true;

                        continue;
                    }
                    $actual[] = [intdiv(max($in, $dayStart) - $dayStart, 60), intdiv(min($out, $dayEnd) - $dayStart, 60)];
                    foreach ($attendance->breaks as $break) {
                        $breaks[] = [intdiv(max($break->start_at->timestamp, $dayStart) - $dayStart, 60), intdiv(min($break->end_at->timestamp, $dayEnd) - $dayStart, 60)];
                    }
                }
                $workingSource = $hasAttendance ? ($incomplete ? 'unknown' : 'actual')
                    : ($shiftRanges === [] ? 'unknown' : 'scheduled_fallback');
                $working = match ($workingSource) {
                    'actual' => MinuteIntervals::subtract($actual, $breaks),
                    'scheduled_fallback' => $shiftRanges,
                    default => null,
                };
                $store = $calendar[$date];
                $hours = $store['status'] === StoreCalendarDay::STATUS_CLOSED ? []
                    : [[$this->minute($store['opens_at']), $this->minute($store['closes_at'])]];
                $bookable = $member->is_bookable
                    ? MinuteIntervals::subtract(MinuteIntervals::intersect($shiftRanges, $hours), [...$blockRanges, ...$breaks]) : [];
                foreach (self::BANDS as $band) {
                    $bandRange = [[$band['start'], $band['end']]];
                    $known = $occupied[$key.'|'.$band['code']] ?? 0;
                    $unknownCount = $unknown[$key] ?? 0;
                    $workingMinutes = $working === null ? null : MinuteIntervals::minutes(MinuteIntervals::intersect($working, $bandRange));
                    $bookableMinutes = MinuteIntervals::minutes(MinuteIntervals::intersect($bookable, $bandRange));
                    $row = [
                        'business_date' => $date, 'staff_id' => (int) $member->user_id, 'staff_name' => $member->display_name,
                        'band_code' => $band['code'], 'band_label' => $band['label'],
                        'occupied_minutes' => $unknownCount > 0 ? null : $known, 'known_occupied_minutes' => $known,
                        'occupied_unknown_count' => $unknownCount,
                        'working_minutes' => $workingMinutes, 'working_minutes_source' => $workingSource,
                        'working_unknown' => $workingMinutes === null && ($hasAttendance || $known > 0 || $unknownCount > 0),
                        'bookable_minutes' => $bookableMinutes,
                        'legacy_utilization_rate' => $workingMinutes === null || $workingMinutes === 0 || $unknownCount > 0 ? null : $known / $workingMinutes,
                        'bookable_utilization_rate' => $bookableMinutes === 0 || $unknownCount > 0 ? null : $known / $bookableMinutes,
                        'visit_count' => count($staffVisits[$key.'|'.$band['code']] ?? []),
                    ];
                    $daily[] = $row;
                    $this->accumulate($monthly, $member->user_id.'|'.$band['code'], $row);
                    $this->accumulate($overall, $band['code'], $row);
                    $this->accumulate($storeDaily, $date.'|'.$band['code'], $row);
                }
            }
        }
        // 店舗全体の日別×帯（スタッフ合計。率は分子合計/分母合計）と、平日（月〜金）/土日の月集計。
        $storeRows = [];
        foreach ($storeDaily as $key => $row) {
            [$date, $bandCode] = explode('|', $key);
            $weekdayIso = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->time->timezone())->dayOfWeekIso;
            $finished = [...$this->finish($row), 'staff_id' => null, 'staff_name' => null, 'business_date' => $date,
                'weekday_iso' => $weekdayIso, 'visit_count' => count($storeVisits[$key] ?? []),
                'visit_unknown_count' => count($unknownVisits[$date] ?? [])];
            $storeRows[] = $finished;
            $group = $weekdayIso <= 5 ? 'weekday' : 'weekend';
            $typeKey = $group.'|'.$bandCode;
            $dayTypes[$typeKey] ??= ['band_code' => $row['band_code'], 'band_label' => $row['band_label'],
                'known_occupied_minutes' => 0, 'occupied_unknown_count' => 0, 'working_minutes' => 0,
                'working_unknown_count' => 0, 'bookable_minutes' => 0, 'visit_count' => 0];
            foreach (['known_occupied_minutes', 'occupied_unknown_count', 'working_minutes', 'working_unknown_count', 'bookable_minutes'] as $field) {
                $dayTypes[$typeKey][$field] += $row[$field];
            }
            $dayTypes[$typeKey]['visit_count'] += $finished['visit_count'];
        }
        $dayTypeRows = [];
        foreach (['weekday', 'weekend'] as $group) {
            foreach (self::BANDS as $band) {
                $row = $dayTypes[$group.'|'.$band['code']] ?? ['band_code' => $band['code'], 'band_label' => $band['label'],
                    'known_occupied_minutes' => 0, 'occupied_unknown_count' => 0, 'working_minutes' => 0,
                    'working_unknown_count' => 0, 'bookable_minutes' => 0, 'visit_count' => 0];
                $dayTypeRows[] = [...$this->finish($row), 'staff_id' => null, 'staff_name' => null, 'day_type' => $group];
            }
        }
        $overallVisits = [];
        foreach ($storeRows as $row) {
            $overallVisits[$row['band_code']] = ($overallVisits[$row['band_code']] ?? 0) + $row['visit_count'];
        }

        foreach (self::BANDS as $band) {
            if (! isset($overall[$band['code']])) {
                $overall[$band['code']] = ['staff_id' => null, 'staff_name' => null,
                    'band_code' => $band['code'], 'band_label' => $band['label'],
                    'known_occupied_minutes' => 0, 'occupied_unknown_count' => 0,
                    'working_minutes' => 0, 'working_unknown_count' => 0, 'bookable_minutes' => 0];
            }
        }

        return ['year' => $year, 'month' => $month, 'month_key' => $start->format('Y-m'),
            'as_of_date' => $asOf->toDateString(), 'bands' => self::BANDS,
            'staff' => $members->map(static fn (Staff $s): array => ['id' => (int) $s->user_id, 'name' => $s->display_name])->all(),
            'outside_band_minutes' => array_sum($outside),
            'daily_rows' => $daily, 'monthly_rows' => array_values(array_map($this->finish(...), $monthly)),
            'overall_rows' => array_values(array_map(fn (array $row): array => [...$this->finish($row), 'visit_count' => $overallVisits[$row['band_code']] ?? 0], $overall)),
            'store_daily_rows' => $storeRows,
            'day_type_rows' => $dayTypeRows,
            'visit_unknown_count' => array_sum(array_map('count', $unknownVisits))];
    }

    private function minute(?string $time): int
    {
        $parts = explode(':', $time ?? '00:00');

        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }

    /** @param array<string,array<string,mixed>> $totals @param array<string,mixed> $row */
    private function accumulate(array &$totals, string $key, array $row): void
    {
        if (! isset($totals[$key])) {
            $totals[$key] = ['staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'],
                'band_code' => $row['band_code'], 'band_label' => $row['band_label'],
                'known_occupied_minutes' => 0, 'occupied_unknown_count' => 0,
                'working_minutes' => 0, 'working_unknown_count' => 0, 'bookable_minutes' => 0];
        }
        $totals[$key]['known_occupied_minutes'] += $row['known_occupied_minutes'];
        $totals[$key]['occupied_unknown_count'] += $row['occupied_unknown_count'];
        $totals[$key]['working_minutes'] += $row['working_minutes'] ?? 0;
        $totals[$key]['working_unknown_count'] += $row['working_unknown'] ? 1 : 0;
        $totals[$key]['bookable_minutes'] += $row['bookable_minutes'];
        $totals[$key]['visit_count'] = ($totals[$key]['visit_count'] ?? 0) + ($row['visit_count'] ?? 0);
        if ($key === $row['band_code']) {
            $totals[$key]['staff_id'] = null;
            $totals[$key]['staff_name'] = null;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function finish(array $row): array
    {
        $occupied = $row['occupied_unknown_count'] > 0 ? null : $row['known_occupied_minutes'];
        $working = $row['working_unknown_count'] > 0 ? null : $row['working_minutes'];
        $bookable = $row['bookable_minutes'];

        return [...$row, 'occupied_minutes' => $occupied, 'working_minutes' => $working,
            'legacy_utilization_rate' => $working === null || $working === 0 || $occupied === null ? null : $occupied / $working,
            'bookable_utilization_rate' => $bookable === 0 || $occupied === null ? null : $occupied / $bookable];
    }
}
