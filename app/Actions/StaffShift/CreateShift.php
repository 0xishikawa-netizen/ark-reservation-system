<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Models\StaffShift;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateShift
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(
        array $data,
        ?Authenticatable $actor = null,
    ): StaffShift {
        return DB::transaction(function () use ($data, $actor): StaffShift {
            $this->ensureDoesNotOverlap(
                (int) $data['staff_id'],
                (string) $data['work_date'],
                (string) $data['start_at'],
                (string) $data['end_at'],
            );

            $shift = StaffShift::query()->create($data);

            $this->auditLogger->log(
                'staff_shift.created',
                $shift,
                sprintf(
                    '勤務枠を作成（staff:%d %s %s-%s）',
                    $shift->staff_id,
                    $shift->work_date->format('Y-m-d'),
                    $shift->start_at,
                    $shift->end_at,
                ),
                $actor,
            );

            return $shift;
        });
    }

    private function ensureDoesNotOverlap(
        int $staffId,
        string $workDate,
        string $startAt,
        string $endAt,
    ): void {
        $hasOverlap = StaffShift::query()
            ->where('staff_id', $staffId)
            ->where('work_date', $workDate)
            ->lockForUpdate()
            ->get(['id', 'start_at', 'end_at'])
            ->contains(fn (StaffShift $shift): bool =>
                substr((string) $shift->start_at, 0, 5) < $endAt
                && substr((string) $shift->end_at, 0, 5) > $startAt
            );

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'start_at' => '同じスタッフの勤務枠と時間帯が重複しています。',
            ]);
        }
    }
}
