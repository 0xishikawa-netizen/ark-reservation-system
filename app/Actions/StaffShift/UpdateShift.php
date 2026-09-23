<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Models\StaffShift;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateShift
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(
        StaffShift $shift,
        array $data,
        ?Authenticatable $actor = null,
    ): StaffShift {
        return DB::transaction(function () use ($shift, $data, $actor): StaffShift {
            $hasOverlap = StaffShift::query()
                ->where('staff_id', $shift->staff_id)
                ->where('work_date', (string) $data['work_date'])
                ->whereKeyNot($shift->getKey())
                ->lockForUpdate()
                ->get(['id', 'start_at', 'end_at'])
                ->contains(fn (StaffShift $existingShift): bool => substr((string) $existingShift->start_at, 0, 5) < (string) $data['end_at']
                    && substr((string) $existingShift->end_at, 0, 5) > (string) $data['start_at']
                );

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'start_at' => __('messages.shift.overlap'),
                ]);
            }

            $shift->update([
                'work_date' => $data['work_date'],
                'start_at' => $data['start_at'],
                'end_at' => $data['end_at'],
            ]);

            $this->auditLogger->log(
                'staff_shift.updated',
                $shift,
                sprintf(
                    '勤務枠を更新（staff:%d %s %s-%s）',
                    $shift->staff_id,
                    $shift->work_date->format('Y-m-d'),
                    $shift->start_at,
                    $shift->end_at,
                ),
                $actor,
            );

            return $shift->refresh();
        });
    }
}
