<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Models\StaffShiftException;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * 例外日を解除して「通常シフトに戻す」。
 * 次回の自動生成でその日の基本シフト分が再生成される。
 * 既存の勤務枠・予約はこのアクションでは削除しない。
 */
final class ClearShiftException
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        StaffShiftException $exception,
        ?Authenticatable $actor = null,
    ): void {
        DB::transaction(function () use ($exception, $actor): void {
            $summary = sprintf(
                '例外日を解除（staff:%d %s）',
                $exception->staff_id,
                $exception->exception_date->toDateString(),
            );

            $exception->delete();

            $this->auditLogger->log(
                'staff_shift_exception.cleared',
                null,
                $summary,
                $actor,
            );
        });
    }
}
