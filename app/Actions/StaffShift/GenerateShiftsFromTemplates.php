<?php

declare(strict_types=1);

namespace App\Actions\StaffShift;

use App\Domain\Reservation\BookingWindow;
use App\Models\StaffShift;
use App\Models\StaffShiftException;
use App\Models\StaffShiftTemplate;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 基本シフト（staff_shift_templates）から、予約受付期間の勤務枠（staff_shifts / origin=template）を生成する。
 *
 * 安全性の原則:
 *  - 冪等: 同じ (staff, work_date, start, end) は二重に作らない。
 *  - 手動保護: (staff, date) に origin=manual の枠 or 例外日がある場合、その日は一切触らない。
 *  - 店舗休業日は生成しない。
 *  - 生成は「追加」のみ。既存の枠を削除・変更しない（未来予約を巻き込まないため）。
 */
final class GenerateShiftsFromTemplates
{
    public function __construct(
        private readonly BookingWindow $bookingWindow,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{created: int, staff_ids: list<int>, through: string}
     */
    public function execute(
        ?CarbonImmutable $now = null,
        ?int $onlyStaffId = null,
        ?Authenticatable $actor = null,
    ): array {
        $now ??= CarbonImmutable::now();
        $today = $now->startOfDay();

        $last = $this->bookingWindow->lastBookableDate($now)
            ?? $today->addDays(BookingWindow::DEFAULT_HORIZON_DAYS);

        if ($last->lessThan($today)) {
            return ['created' => 0, 'staff_ids' => [], 'through' => $last->toDateString()];
        }

        // 例外日の休業と毎週の定休日には勤務枠を作らない。
        $closed = array_flip($this->bookingWindow->closedDatesBetween($today, $last));

        /** @var Collection<int, Collection<int, StaffShiftTemplate>> $byStaff */
        $byStaff = StaffShiftTemplate::query()
            ->where('is_active', true)
            ->when($onlyStaffId !== null, fn ($q) => $q->where('staff_id', $onlyStaffId))
            ->orderBy('staff_id')
            ->get()
            ->groupBy('staff_id');

        $created = 0;
        $staffAffected = [];

        DB::transaction(function () use (
            $byStaff, $today, $last, $closed, &$created, &$staffAffected
        ): void {
            foreach ($byStaff as $staffId => $templates) {
                $staffId = (int) $staffId;

                $exceptionDates = StaffShiftException::query()
                    ->where('staff_id', $staffId)
                    ->whereBetween('exception_date', [$today->toDateString(), $last->toDateString()])
                    ->pluck('exception_date')
                    ->map(static fn ($d): string => CarbonImmutable::parse((string) $d)->toDateString())
                    ->flip();

                $existing = StaffShift::query()
                    ->where('staff_id', $staffId)
                    ->whereBetween('work_date', [$today->toDateString(), $last->toDateString()])
                    ->get(['work_date', 'start_at', 'end_at', 'origin']);

                $manualDates = [];
                $existingKeys = [];

                foreach ($existing as $shift) {
                    $date = CarbonImmutable::parse((string) $shift->work_date)->toDateString();

                    if ($shift->origin === StaffShift::ORIGIN_MANUAL) {
                        $manualDates[$date] = true;
                    }

                    $existingKeys[$this->key(
                        $date,
                        (string) $shift->start_at,
                        (string) $shift->end_at,
                    )] = true;
                }

                $rows = [];

                for ($date = $today; $date->lessThanOrEqualTo($last); $date = $date->addDay()) {
                    $ds = $date->toDateString();

                    if (isset($closed[$ds]) || isset($exceptionDates[$ds]) || isset($manualDates[$ds])) {
                        continue;
                    }

                    foreach ($templates as $template) {
                        if ((int) $template->weekday !== $date->dayOfWeek) {
                            continue;
                        }

                        $key = $this->key($ds, (string) $template->start_at, (string) $template->end_at);

                        if (isset($existingKeys[$key])) {
                            continue;
                        }

                        $existingKeys[$key] = true;
                        $rows[] = [
                            'staff_id' => $staffId,
                            'work_date' => $ds,
                            'start_at' => $this->hms($template->start_at),
                            'end_at' => $this->hms($template->end_at),
                            'origin' => StaffShift::ORIGIN_TEMPLATE,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if ($rows !== []) {
                    StaffShift::query()->insert($rows);
                    $created += count($rows);
                    $staffAffected[$staffId] = true;
                }
            }
        });

        $staffIds = array_map('intval', array_keys($staffAffected));

        if ($created > 0) {
            $this->auditLogger->log(
                'staff_shift.generated',
                null,
                sprintf(
                    '基本シフトから勤務枠を%d件生成（%s まで / スタッフ%d名）',
                    $created,
                    $last->toDateString(),
                    count($staffIds),
                ),
                $actor,
            );
        }

        return [
            'created' => $created,
            'staff_ids' => $staffIds,
            'through' => $last->toDateString(),
        ];
    }

    private function key(string $date, string $start, string $end): string
    {
        return $date.'|'.substr($start, 0, 5).'|'.substr($end, 0, 5);
    }

    private function hms(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
