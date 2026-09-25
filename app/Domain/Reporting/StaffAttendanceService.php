<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Models\StaffAttendance;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StaffAttendanceService
{
    public function __construct(private readonly BusinessTime $businessTime, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $input */
    public function save(array $input, ?Authenticatable $actor, ?StaffAttendance $attendance = null): StaffAttendance
    {
        $zone = $this->businessTime->timezone();
        $clockIn = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['clock_in_at'], $zone);
        $clockOut = isset($input['clock_out_at']) && $input['clock_out_at'] !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['clock_out_at'], $zone) : null;
        if ($clockIn === false || $clockIn->format('Y-m-d') !== $input['business_date']
            || ($clockOut !== null && ($clockOut === false || $clockOut->lte($clockIn) || $clockOut->gt($clockIn->addHours(36))))) {
            throw ValidationException::withMessages(['clock_out_at' => __('messages.staff_utilization.attendance_invalid')]);
        }
        if (($input['status'] ?? 'draft') === 'confirmed' && $clockOut === null) {
            throw ValidationException::withMessages(['clock_out_at' => __('messages.staff_utilization.attendance_invalid')]);
        }

        $breaks = [];
        foreach ($input['breaks'] ?? [] as $index => $entry) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $entry['start_at'], $zone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $entry['end_at'], $zone);
            if ($start === false || $end === false || $clockOut === null || $start->lt($clockIn)
                || $end->gt($clockOut) || $end->lte($start)) {
                throw ValidationException::withMessages(["breaks.{$index}.end_at" => __('messages.staff_utilization.break_invalid')]);
            }
            $breaks[] = [
                'start_at' => $start->utc()->format('Y-m-d H:i:s'),
                'end_at' => $end->utc()->format('Y-m-d H:i:s'),
                'type' => $entry['type'] ?? 'break',
                'note' => $entry['note'] ?? null,
            ];
        }

        return DB::transaction(function () use ($input, $actor, $attendance, $clockIn, $clockOut, $breaks): StaffAttendance {
            $created = $attendance === null;
            if ($attendance !== null) {
                $attendance = StaffAttendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
                if ((int) $attendance->staff_id !== (int) $input['staff_id']) {
                    throw ValidationException::withMessages(['staff_id' => __('messages.staff_utilization.attendance_invalid')]);
                }
            } else {
                $attendance = new StaffAttendance;
            }
            $attendance->fill([
                'staff_id' => $input['staff_id'],
                'business_date' => $input['business_date'],
                'clock_in_at' => $clockIn->utc()->format('Y-m-d H:i:s'),
                'clock_out_at' => $clockOut?->utc()->format('Y-m-d H:i:s'),
                'status' => $input['status'] ?? 'draft',
                'note' => $input['note'] ?? null,
            ])->save();
            // 編集は明示的な休憩リスト全体の置換。重複区間は集計時にunionする。
            $attendance->breaks()->delete();
            $attendance->breaks()->createMany($breaks);
            $this->audit->log(
                $created ? 'staff_attendance.created' : 'staff_attendance.updated',
                $attendance,
                sprintf('勤怠 #%d staff:%d %s', $attendance->id, $attendance->staff_id, $input['business_date']),
                $actor,
            );

            return $attendance->refresh();
        });
    }
}
