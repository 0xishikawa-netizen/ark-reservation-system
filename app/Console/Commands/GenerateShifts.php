<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\StaffShift\GenerateShiftsFromTemplates;
use Illuminate\Console\Command;

/**
 * 基本シフトから予約受付期間分の勤務枠を生成する（#11）。
 * 冪等。Scheduler で毎朝実行し、monthly 開放日を迎えると翌月分が自動的に増える。
 */
final class GenerateShifts extends Command
{
    protected $signature = 'shifts:generate {--staff= : 対象スタッフの user_id（省略時は全員）}';

    protected $description = '基本シフト（曜日テンプレート）から勤務枠を生成する';

    public function handle(GenerateShiftsFromTemplates $action): int
    {
        $staffOption = $this->option('staff');
        $onlyStaffId = $staffOption === null ? null : (int) $staffOption;

        $result = $action->execute(now: null, onlyStaffId: $onlyStaffId);

        $this->info(sprintf(
            '勤務枠を %d 件生成しました（%s まで / スタッフ %d 名）。',
            $result['created'],
            $result['through'],
            count($result['staff_ids']),
        ));

        return self::SUCCESS;
    }
}
