<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Schedule\ScheduleBlockType;
use App\Models\Reservation;
use App\Models\StaffAttendance;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 勤怠一覧（タイムシート）。ブッキングボードの勤務枠（予定）・休憩の予定・予約から日ごとの行を自動で作り、
 * 実績（出退勤の記録）があれば並べて、遅出・早出・早退・残業と稼働率を計算する。
 *
 * 実績が無い日は予定どおりとして扱い（稼働率の分母は予定の勤務時間−予定の休憩）、記録すると実績で計算する。
 */
final class StaffTimesheetService
{
    /** 予定との差がこの分数未満なら「遅出・早出・早退・残業」とは見なさない（誤差）。 */
    private const TOLERANCE_MIN = 5;

    public function __construct(private readonly BusinessTime $businessTime) {}

    /** @return list<array<string, mixed>> */
    public function forStaff(int $staffId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $zone = $this->businessTime->timezone();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        /** @var Collection<string, Collection<int, StaffShift>> $shifts */
        $shifts = StaffShift::query()->where('staff_id', $staffId)->whereBetween('work_date', [$fromDate, $toDate])
            ->orderBy('start_at')->get()->groupBy(static fn (StaffShift $s): string => $s->work_date->toDateString());
        /** @var Collection<string, Collection<int, StaffScheduleBlock>> $breaks */
        $breaks = StaffScheduleBlock::query()->where('staff_id', $staffId)->where('type', ScheduleBlockType::Break->value)
            ->whereBetween('work_date', [$fromDate, $toDate])->orderBy('start_at')->get()
            ->groupBy(static fn (StaffScheduleBlock $b): string => $b->work_date->toDateString());
        /** @var Collection<string, StaffAttendance> $attendances */
        $attendances = StaffAttendance::query()->with('breaks')->where('staff_id', $staffId)
            ->whereBetween('business_date', [$fromDate, $toDate])->get()
            ->keyBy(static fn (StaffAttendance $a): string => $a->business_date->toDateString());

        $bookedByDate = Reservation::query()->where('staff_id', $staffId)
            ->whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::Completed->value])
            ->where('starts_at', '>=', $from->startOfDay()->utc())->where('starts_at', '<', $to->addDay()->startOfDay()->utc())
            ->get(['starts_at', 'ends_at', 'buffer_min'])
            ->groupBy(static fn (Reservation $r): string => CarbonImmutable::instance($r->starts_at)->setTimezone($zone)->toDateString())
            ->map(static fn (Collection $rows): array => [
                'count' => $rows->count(),
                'minutes' => (int) $rows->sum(static fn (Reservation $r): int => $r->bookedMinutes()),
            ]);

        $dates = $shifts->keys()->merge($attendances->keys())->unique()->sort()->values();
        $rows = [];
        foreach ($dates as $date) {
            $dayShifts = $shifts->get($date, collect());
            $plannedStart = $dayShifts->isEmpty() ? null : substr((string) $dayShifts->min('start_at'), 0, 5);
            $plannedEnd = $dayShifts->isEmpty() ? null : substr((string) $dayShifts->max('end_at'), 0, 5);
            $plannedWork = (int) $dayShifts->sum(fn (StaffShift $s): int => $this->minutesBetween((string) $s->start_at, (string) $s->end_at));
            $dayBreaks = $breaks->get($date, collect());
            $plannedBreakMin = (int) $dayBreaks->sum(fn (StaffScheduleBlock $b): int => $this->minutesBetween((string) $b->start_at, (string) $b->end_at));
            $plannedBreaks = $dayBreaks->map(static fn (StaffScheduleBlock $b): array => [
                'start' => substr((string) $b->start_at, 0, 5), 'end' => substr((string) $b->end_at, 0, 5),
            ])->values()->all();

            $attendance = $attendances->get($date);
            $actual = $attendance === null ? null : $this->actual($attendance, $zone);
            $booked = $bookedByDate->get($date, ['count' => 0, 'minutes' => 0]);

            // 稼働率の分母：実績があれば実績の実働、無ければ予定の勤務−予定の休憩。
            $available = $actual !== null && $actual['work_min'] !== null ? $actual['work_min'] : max($plannedWork - $plannedBreakMin, 0);

            $rows[] = [
                'date' => $date,
                'planned' => $plannedStart === null ? null : [
                    'start' => $plannedStart, 'end' => $plannedEnd, 'work_min' => $plannedWork,
                    'break_min' => $plannedBreakMin, 'breaks' => $plannedBreaks,
                ],
                'attendance' => $attendance === null ? null : [
                    'id' => (int) $attendance->id,
                    'status' => $attendance->status,
                    'note' => $attendance->note,
                    'clock_in' => $actual['clock_in'],
                    'clock_out' => $actual['clock_out'],
                    'work_min' => $actual['work_min'],
                    'break_min' => $actual['break_min'],
                    'breaks' => $actual['breaks'],
                ],
                'flags' => $actual === null || $plannedStart === null ? [] : $this->flags($plannedStart, (string) $plannedEnd, $actual),
                'overtime_min' => $this->overtime($plannedWork, $plannedBreakMin, $actual),
                'booked_count' => $booked['count'],
                'booked_min' => $booked['minutes'],
                'available_min' => $available,
                'utilization' => $available > 0 ? (int) round($booked['minutes'] / $available * 100) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{clock_in: ?string, clock_out: ?string, work_min: ?int, break_min: int, breaks: list<array{start: string, end: string, type: string}>}
     */
    private function actual(StaffAttendance $attendance, string $zone): array
    {
        $in = $attendance->clock_in_at === null ? null : CarbonImmutable::instance($attendance->clock_in_at)->setTimezone($zone);
        $out = $attendance->clock_out_at === null ? null : CarbonImmutable::instance($attendance->clock_out_at)->setTimezone($zone);
        $breaks = [];
        $breakMin = 0;
        foreach ($attendance->breaks as $b) {
            $start = CarbonImmutable::instance($b->start_at)->setTimezone($zone);
            $end = CarbonImmutable::instance($b->end_at)->setTimezone($zone);
            $breaks[] = ['start' => $start->format('H:i'), 'end' => $end->format('H:i'), 'type' => (string) $b->type];
            $breakMin += max((int) $start->diffInMinutes($end), 0);
        }

        return [
            'clock_in' => $in?->format('H:i'),
            // 日をまたぐ退勤は「翌 HH:MM」で返さず、日付付きで返す（画面側で翌日扱いにする）。
            'clock_out' => $out?->format($in !== null && $out->toDateString() !== $in->toDateString() ? 'Y-m-d\TH:i' : 'H:i'),
            'work_min' => $in !== null && $out !== null ? max((int) $in->diffInMinutes($out) - $breakMin, 0) : null,
            'break_min' => $breakMin,
            'breaks' => $breaks,
        ];
    }

    /**
     * 予定と実績の差から遅出・早出・早退・残業を判定する。
     *
     * @param  array{clock_in: ?string, clock_out: ?string, work_min: ?int, break_min: int, breaks: list<array<string, string>>}  $actual
     * @return list<array{type: string, minutes: int}>
     */
    private function flags(string $plannedStart, string $plannedEnd, array $actual): array
    {
        $flags = [];
        if ($actual['clock_in'] !== null) {
            $diff = $this->hhmm($actual['clock_in']) - $this->hhmm($plannedStart);
            if ($diff >= self::TOLERANCE_MIN) {
                $flags[] = ['type' => 'late_start', 'minutes' => $diff];
            } elseif ($diff <= -self::TOLERANCE_MIN) {
                $flags[] = ['type' => 'early_start', 'minutes' => -$diff];
            }
        }
        if ($actual['clock_out'] !== null) {
            $out = str_contains($actual['clock_out'], 'T') ? $this->hhmm(substr($actual['clock_out'], 11, 5)) + 1440 : $this->hhmm($actual['clock_out']);
            $diff = $out - $this->hhmm($plannedEnd);
            if ($diff >= self::TOLERANCE_MIN) {
                $flags[] = ['type' => 'overtime', 'minutes' => $diff];
            } elseif ($diff <= -self::TOLERANCE_MIN) {
                $flags[] = ['type' => 'early_leave', 'minutes' => -$diff];
            }
        }

        return $flags;
    }

    /**
     * 残業＝実績の実働が予定の実働（勤務−予定休憩）を超えた分。
     *
     * @param  array{work_min: ?int}|null  $actual
     */
    private function overtime(int $plannedWork, int $plannedBreakMin, ?array $actual): int
    {
        if ($actual === null || $actual['work_min'] === null) {
            return 0;
        }

        return max($actual['work_min'] - max($plannedWork - $plannedBreakMin, 0), 0);
    }

    private function minutesBetween(string $start, string $end): int
    {
        return max($this->hhmm(substr($end, 0, 5)) - $this->hhmm(substr($start, 0, 5)), 0);
    }

    private function hhmm(string $value): int
    {
        [$h, $m] = array_map('intval', explode(':', $value));

        return $h * 60 + $m;
    }
}
