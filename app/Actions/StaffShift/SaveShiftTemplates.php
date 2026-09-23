<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Models\Staff;
use App\Models\StaffShiftTemplate;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 1 スタッフの基本シフトをまとめて置き換える（曜日ごとの通常勤務時間）。
 * 過去に自動生成済みの勤務枠は書き換えない（このアクションはテンプレのみ更新）。
 */
final class SaveShiftTemplates
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<array{weekday:int,start_at:string,end_at:string}>  $entries
     */
    public function execute(
        Staff $staff,
        array $entries,
        ?Authenticatable $actor = null,
    ): void {
        $this->assertNoOverlapWithinWeekday($entries);

        DB::transaction(function () use ($staff, $entries, $actor): void {
            StaffShiftTemplate::query()
                ->where('staff_id', $staff->user_id)
                ->delete();

            $now = now();
            $rows = array_map(static fn (array $entry): array => [
                'staff_id' => $staff->user_id,
                'weekday' => $entry['weekday'],
                'start_at' => $entry['start_at'].':00',
                'end_at' => $entry['end_at'].':00',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $entries);

            if ($rows !== []) {
                StaffShiftTemplate::query()->insert($rows);
            }

            $this->auditLogger->log(
                'staff_shift_template.saved',
                $staff,
                sprintf('基本シフトを保存（staff:%d 時間帯%d件）', $staff->user_id, count($rows)),
                $actor,
            );
        });
    }

    /**
     * @param  list<array{weekday:int,start_at:string,end_at:string}>  $entries
     */
    private function assertNoOverlapWithinWeekday(array $entries): void
    {
        $byWeekday = [];

        foreach ($entries as $index => $entry) {
            $byWeekday[$entry['weekday']][] = ['i' => $index, 's' => $entry['start_at'], 'e' => $entry['end_at']];
        }

        foreach ($byWeekday as $slots) {
            $count = count($slots);

            for ($a = 0; $a < $count; $a++) {
                for ($b = $a + 1; $b < $count; $b++) {
                    if ($slots[$a]['s'] < $slots[$b]['e'] && $slots[$a]['e'] > $slots[$b]['s']) {
                        throw ValidationException::withMessages([
                            "entries.{$slots[$b]['i']}.start_at" => __('messages.shift.template_overlap'),
                        ]);
                    }
                }
            }
        }
    }
}
