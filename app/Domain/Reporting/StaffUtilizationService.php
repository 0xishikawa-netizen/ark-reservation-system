<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Business\StoreCalendarService;
use App\Models\EmploymentType;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffEmploymentPeriod;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StaffUtilizationService
{
    public function __construct(private readonly BusinessTime $businessTime, private readonly StoreCalendarService $calendar) {}

    /** @return array<string,mixed> */
    public function forMonth(int $year, int $month, ?int $staffId = null, ?int $employmentTypeId = null, ?string $asOfDate = null): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('年月の指定が不正です。');
        }
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone());
        $end = $start->endOfMonth()->startOfDay();
        $today = $this->businessTime->businessDate();
        $asOf = $asOfDate === null ? ($today->lt($start) ? $start->subDay() : ($today->lt($end) ? $today : $end))
            : CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate, $this->businessTime->timezone());
        if ($asOf === false || ($asOfDate !== null && $asOf->toDateString() !== $asOfDate)
            || $asOf->lt($start->subDay()) || $asOf->gt($end)) {
            throw new InvalidArgumentException('as_of_dateは対象月内（未来月は月初前日も可）で指定してください。');
        }

        $staffOptions = Staff::query()->orderBy('sort_order')->orderBy('user_id')
            ->get(['user_id', 'display_name', 'is_bookable']);
        $staff = $staffId === null ? $staffOptions : $staffOptions->where('user_id', $staffId);
        $types = EmploymentType::query()->get(['id', 'code', 'name'])->keyBy('id');
        $periods = StaffEmploymentPeriod::query()->where('effective_from', '<=', $end->toDateString())
            ->where(static fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $start->toDateString()))
            ->get(['staff_id', 'employment_type_id', 'effective_from', 'effective_to'])->groupBy('staff_id');
        $shifts = StaffShift::query()->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get(['staff_id', 'work_date', 'start_at', 'end_at'])->groupBy(static fn (StaffShift $s): string => $s->staff_id.'|'.$s->work_date->toDateString());
        $blocks = StaffScheduleBlock::query()->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('staff_id')->get(['staff_id', 'work_date', 'start_at', 'end_at'])
            ->groupBy(static fn (StaffScheduleBlock $b): string => $b->staff_id.'|'.$b->work_date->toDateString());
        $calendar = $this->calendar->resolveRange($start, $end);

        // UTC timestampをJST日境界へ切り分ける。前日開始の夜勤も当日の出勤分へ配賦する。
        $utcStart = $start->subHours(36)->utc()->format('Y-m-d H:i:s');
        $utcEnd = $end->addDay()->utc()->format('Y-m-d H:i:s');
        $attendances = StaffAttendance::query()->with('breaks')
            ->where(static fn ($q) => $q->whereBetween('clock_in_at', [$utcStart, $utcEnd])
                ->orWhere(static fn ($missing) => $missing->whereNull('clock_in_at')
                    ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])))
            ->get();

        $occupied = DB::table('visit_treatment_staff as assignments')
            ->join('visit_treatments as treatments', 'treatments.id', '=', 'assignments.visit_treatment_id')
            ->join('visits', 'visits.id', '=', 'treatments.visit_id')
            ->where('visits.status', 'completed')->where('treatments.status', 'completed')
            ->whereBetween('visits.business_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('assignments.staff_id')
            ->groupBy('assignments.staff_id', 'visits.business_date')
            ->selectRaw('assignments.staff_id, visits.business_date, SUM(assignments.actual_minutes) as known_minutes, SUM(CASE WHEN assignments.actual_minutes IS NULL THEN 1 ELSE 0 END) as unknown_count')
            ->get()->keyBy(static fn ($r): string => $r->staff_id.'|'.$r->business_date);
        // 来店数系は担当明細へJOINしない。NULL主担当は別集計で明示する。
        $patients = DB::table('visits')->where('status', 'completed')
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('primary_staff_id')->groupBy('primary_staff_id', 'business_date')
            ->selectRaw('primary_staff_id, business_date, COUNT(*) as patient_count, SUM(CASE WHEN future_reservation_exists_at_checkout = 1 THEN 1 ELSE 0 END) as future_count, SUM(CASE WHEN future_reservation_exists_at_checkout IS NULL THEN 1 ELSE 0 END) as future_unknown_count, SUM(CASE WHEN staff_requested_at_checkout = 1 AND requested_staff_id_at_checkout = primary_staff_id THEN 1 ELSE 0 END) as nomination_count, SUM(CASE WHEN staff_requested_at_checkout IS NULL OR (staff_requested_at_checkout = 1 AND (requested_staff_id_at_checkout IS NULL OR requested_staff_id_at_checkout != primary_staff_id)) THEN 1 ELSE 0 END) as nomination_unknown_count')
            ->get()->keyBy(static fn ($r): string => $r->primary_staff_id.'|'.$r->business_date);
        $unknownPrimary = DB::table('visits')->where('status', 'completed')->whereNull('primary_staff_id')
            ->whereBetween('business_date', [$start->toDateString(), $asOf->toDateString()])
            ->count();

        $rows = [];
        $months = [];
        foreach ($staff as $member) {
            $totalsByType = [];
            for ($day = $start; $day->lte($asOf); $day = $day->addDay()) {
                $date = $day->toDateString();
                $key = $member->user_id.'|'.$date;
                $employment = $periods->get($member->user_id)?->first(static fn (StaffEmploymentPeriod $p): bool => $p->effective_from->toDateString() <= $date && ($p->effective_to === null || $p->effective_to->toDateString() > $date));
                $type = $employment === null ? null : $types->get($employment->employment_type_id);
                if ($employmentTypeId !== null && $type?->id !== $employmentTypeId) {
                    continue;
                }
                $shiftRanges = [];
                foreach ($shifts->get($key, collect()) as $shift) {
                    $shiftRanges[] = [$this->minute($shift->start_at), $this->minute($shift->end_at)];
                }
                $shiftRanges = MinuteIntervals::union($shiftRanges);
                $blockedRanges = [];
                foreach ($blocks->get($key, collect()) as $block) {
                    $blockedRanges[] = [$this->minute($block->start_at), $this->minute($block->end_at)];
                }
                $actualRanges = [];
                $actualBreaks = [];
                $hasAttendance = false;
                $incomplete = false;
                $dayStart = $day->timestamp;
                $dayEnd = $day->addDay()->timestamp;
                foreach ($attendances as $attendance) {
                    if ((int) $attendance->staff_id !== (int) $member->user_id) {
                        continue;
                    }
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
                    $actualRanges[] = [max($in, $dayStart), min($out, $dayEnd)];
                    foreach ($attendance->breaks as $break) {
                        $actualBreaks[] = [max($break->start_at->timestamp, $dayStart), min($break->end_at->timestamp, $dayEnd)];
                    }
                }
                $actualRanges = MinuteIntervals::union($actualRanges);
                $actualBreaks = MinuteIntervals::intersect($actualRanges, $actualBreaks);
                $workingSource = $hasAttendance ? ($incomplete ? 'unknown' : 'actual')
                    : ($shiftRanges === [] ? 'unknown' : 'scheduled_fallback');
                $working = match ($workingSource) {
                    'actual' => intdiv(MinuteIntervals::minusMinutes($actualRanges, $actualBreaks), 60),
                    'scheduled_fallback' => MinuteIntervals::minutes($shiftRanges),
                    default => null,
                };
                $store = $calendar[$date];
                $hours = $store['status'] === StoreCalendarDay::STATUS_CLOSED ? []
                    : [[$this->minute($store['opens_at']), $this->minute($store['closes_at'])]];
                $openShift = $member->is_bookable ? MinuteIntervals::intersect($shiftRanges, $hours) : [];
                $bookable = MinuteIntervals::minusMinutes($openShift, [...$blockedRanges, ...array_map(
                    static fn (array $range): array => [intdiv($range[0] - $dayStart, 60), intdiv($range[1] - $dayStart, 60)],
                    $actualBreaks,
                )]);
                $time = $occupied->get($key);
                $patient = $patients->get($key);
                $occupiedMinutes = $time === null || (int) $time->unknown_count === 0 ? (int) ($time?->known_minutes ?? 0) : null;
                $patientCount = (int) ($patient?->patient_count ?? 0);
                $futureCount = (int) ($patient?->future_count ?? 0);
                $futureUnknown = (int) ($patient?->future_unknown_count ?? 0);
                $nominationUnknown = (int) ($patient?->nomination_unknown_count ?? 0);
                $nominationCount = $nominationUnknown === 0 ? (int) ($patient?->nomination_count ?? 0) : null;
                $row = [
                    'staff_id' => (int) $member->user_id, 'staff_name' => $member->display_name, 'business_date' => $date,
                    'employment_type_id' => $type?->id, 'employment_type_code' => $type?->code, 'employment_type_name' => $type?->name,
                    'working_minutes' => $working, 'working_minutes_source' => $workingSource,
                    'attendance_present' => $hasAttendance, 'scheduled_shift_present' => $shiftRanges !== [],
                    'occupied_minutes' => $occupiedMinutes,
                    'occupied_unknown_count' => (int) ($time?->unknown_count ?? 0), 'bookable_minutes' => $bookable,
                    'patient_count' => $patientCount, 'future_reservation_count' => $futureUnknown === 0 ? $futureCount : null,
                    'future_reservation_unknown_count' => $futureUnknown,
                    'nomination_supported' => $nominationUnknown === 0, 'nomination_count' => $nominationCount,
                    'nomination_unknown_count' => $nominationUnknown,
                    'reservation_rate' => $patientCount === 0 || $futureUnknown > 0 ? null : $futureCount / $patientCount,
                    'nomination_rate' => $patientCount === 0 || $nominationUnknown > 0 ? null : $nominationCount / $patientCount,
                    'legacy_utilization_rate' => $working === null || $working === 0 || $occupiedMinutes === null ? null : $occupiedMinutes / $working,
                    'bookable_utilization_rate' => $bookable === 0 || $occupiedMinutes === null ? null : $occupiedMinutes / $bookable,
                ];
                $rows[] = $row;
                $typeKey = $type?->id ?? 'unknown';
                if (! isset($totalsByType[$typeKey])) {
                    $totalsByType[$typeKey] = [
                        ...$this->emptyTotal((int) $member->user_id, $member->display_name),
                        'employment_type_id' => $type?->id,
                        'employment_type_code' => $type?->code,
                        'employment_type_name' => $type?->name,
                    ];
                }
                $this->addToTotal($totalsByType[$typeKey], $row);
            }
            foreach ($totalsByType as $total) {
                $months[] = $this->finishTotal($total);
            }
        }

        return [
            'year' => $year, 'month' => $month, 'month_key' => $start->format('Y-m'),
            'as_of_date' => $asOf->toDateString(), 'staff' => $staffOptions->map(static fn (Staff $s): array => ['id' => (int) $s->user_id, 'name' => $s->display_name])->values()->all(),
            'employment_types' => $types->values()->map(static fn (EmploymentType $t): array => ['id' => (int) $t->id, 'code' => $t->code, 'name' => $t->name])->all(),
            'filters' => ['staff_id' => $staffId, 'employment_type_id' => $employmentTypeId],
            'unknown_primary_visit_count' => $unknownPrimary,
            'daily_rows' => $rows, 'monthly_rows' => $months,
        ];
    }

    private function minute(?string $time): int
    {
        if ($time === null) {
            return 0;
        }
        $parts = explode(':', $time);

        return ((int) $parts[0]) * 60 + (int) ($parts[1] ?? 0);
    }

    /** @return array<string,mixed> */
    private function emptyTotal(int $staffId, string $name): array
    {
        return ['staff_id' => $staffId, 'staff_name' => $name, 'day_count' => 0, 'occupied_minutes' => 0,
            'occupied_unknown_count' => 0, 'working_minutes' => 0, 'working_unknown_days' => 0,
            'bookable_minutes' => 0, 'patient_count' => 0, 'future_reservation_count' => 0,
            'future_reservation_unknown_count' => 0, 'nomination_count' => 0, 'nomination_unknown_count' => 0];
    }

    /** @param array<string,mixed> $total @param array<string,mixed> $row */
    private function addToTotal(array &$total, array $row): void
    {
        $total['day_count']++;
        $total['occupied_minutes'] += $row['occupied_minutes'] ?? 0;
        $total['occupied_unknown_count'] += $row['occupied_unknown_count'];
        $total['working_minutes'] += $row['working_minutes'] ?? 0;
        if ($row['working_minutes'] === null && ($row['attendance_present'] || $row['occupied_minutes'] !== 0 || $row['patient_count'] > 0)) {
            $total['working_unknown_days']++;
        }
        $total['bookable_minutes'] += $row['bookable_minutes'];
        $total['patient_count'] += $row['patient_count'];
        $total['future_reservation_count'] += $row['future_reservation_count'] ?? 0;
        $total['future_reservation_unknown_count'] += $row['future_reservation_unknown_count'];
        $total['nomination_count'] += $row['nomination_count'] ?? 0;
        $total['nomination_unknown_count'] += $row['nomination_unknown_count'];
    }

    /** @param array<string,mixed> $total @return array<string,mixed> */
    private function finishTotal(array $total): array
    {
        $occupied = $total['occupied_unknown_count'] === 0 ? $total['occupied_minutes'] : null;
        $working = $total['working_unknown_days'] === 0 ? $total['working_minutes'] : null;
        $patients = $total['patient_count'];
        $future = $total['future_reservation_unknown_count'] === 0 ? $total['future_reservation_count'] : null;
        $nomination = $total['nomination_unknown_count'] === 0 ? $total['nomination_count'] : null;

        return [
            ...$total, 'occupied_minutes' => $occupied, 'working_minutes' => $working,
            'future_reservation_count' => $future, 'nomination_supported' => $nomination !== null,
            'nomination_count' => $nomination,
            'reservation_rate' => $patients === 0 || $future === null ? null : $future / $patients,
            'nomination_rate' => $patients === 0 || $nomination === null ? null : $nomination / $patients,
            'legacy_utilization_rate' => $working === null || $working === 0 || $occupied === null ? null : $occupied / $working,
            'bookable_utilization_rate' => $total['bookable_minutes'] === 0 || $occupied === null ? null : $occupied / $total['bookable_minutes'],
        ];
    }
}
