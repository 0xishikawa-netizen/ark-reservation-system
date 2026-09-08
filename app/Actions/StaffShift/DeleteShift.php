<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Models\StaffShift;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class DeleteShift
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        StaffShift $shift,
        ?Authenticatable $actor = null,
    ): void {
        DB::transaction(function () use ($shift, $actor): void {
            $summary = sprintf(
                '勤務枠を削除（staff:%d %s %s-%s）',
                $shift->staff_id,
                $shift->work_date->format('Y-m-d'),
                $shift->start_at,
                $shift->end_at,
            );

            $shift->delete();

            $this->auditLogger->log(
                'staff_shift.deleted',
                $shift,
                $summary,
                $actor,
            );
        });
    }
}
