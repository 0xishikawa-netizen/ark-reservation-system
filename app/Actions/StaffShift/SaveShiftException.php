<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\StaffShiftException;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * 例外日を登録・更新する（休み / 時間変更）。
 *
 * - is_off=true  … その日は休み。自動生成分（origin=template）の枠だけ削除する。
 *                  手動枠・予約は一切触らない。
 * - is_off=false … 通常と異なる時間で出勤。枠そのものは既存の勤務枠 CRUD で
 *                  作成する（origin=manual）。ここでは「自動生成の対象外」マークだけ付ける。
 */
final class SaveShiftException
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Staff $staff,
        string $date,
        bool $isOff,
        ?string $note,
        ?Authenticatable $actor = null,
    ): StaffShiftException {
        return DB::transaction(function () use ($staff, $date, $isOff, $note, $actor): StaffShiftException {
            $exception = StaffShiftException::query()->updateOrCreate(
                ['staff_id' => $staff->user_id, 'exception_date' => $date],
                ['is_off' => $isOff, 'note' => $note],
            );

            if ($isOff) {
                StaffShift::query()
                    ->where('staff_id', $staff->user_id)
                    ->whereDate('work_date', $date)
                    ->where('origin', StaffShift::ORIGIN_TEMPLATE)
                    ->delete();
            }

            $this->auditLogger->log(
                'staff_shift_exception.saved',
                $staff,
                sprintf(
                    '例外日を設定（staff:%d %s %s）',
                    $staff->user_id,
                    CarbonImmutable::parse($date)->toDateString(),
                    $isOff ? '休み' : '時間変更',
                ),
                $actor,
            );

            return $exception;
        });
    }
}
