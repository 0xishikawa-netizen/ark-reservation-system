<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\StaffAttendanceService;
use App\Domain\Reporting\StaffTimesheetService;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Schedule\ScheduleBlockType;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 勤怠一覧：ブッキングボードの勤務枠・休憩・予約から自動で行を作り、実績との差と稼働率を出す。
 */
final class StaffTimesheetServiceTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = Staff::factory()->create();
        StaffShift::query()->create(['staff_id' => $this->staff->user_id, 'work_date' => '2026-10-01', 'start_at' => '10:00:00', 'end_at' => '19:00:00']);
        StaffShift::query()->create(['staff_id' => $this->staff->user_id, 'work_date' => '2026-10-02', 'start_at' => '10:00:00', 'end_at' => '18:00:00']);
        StaffScheduleBlock::factory()->create([
            'staff_id' => $this->staff->user_id, 'booth_id' => null, 'work_date' => '2026-10-01',
            'start_at' => '12:00:00', 'end_at' => '13:00:00', 'type' => ScheduleBlockType::Break,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return app(StaffTimesheetService::class)->forStaff(
            $this->staff->user_id,
            CarbonImmutable::parse('2026-10-01', 'Asia/Tokyo'),
            CarbonImmutable::parse('2026-10-31', 'Asia/Tokyo'),
        );
    }

    public function test_rows_are_built_from_board_shifts_and_planned_breaks(): void
    {
        $rows = $this->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-01', $rows[0]['date']);
        $this->assertSame(['start' => '10:00', 'end' => '19:00', 'work_min' => 540, 'break_min' => 60, 'breaks' => [['start' => '12:00', 'end' => '13:00']]], $rows[0]['planned']);
        $this->assertNull($rows[0]['attendance']);
        $this->assertSame([], $rows[0]['flags']);
        // 実績が無い日は予定の実働（540−60）が稼働率の分母。
        $this->assertSame(480, $rows[0]['available_min']);
    }

    public function test_late_start_overtime_and_utilization_are_calculated_from_attendance_and_reservations(): void
    {
        $service = Service::factory()->create(['duration_min' => 60]);
        $customer = Customer::factory()->create();
        // 120分の予約（インターバル0）。
        Reservation::factory()->create([
            'customer_id' => $customer->user_id, 'service_id' => $service->id, 'staff_id' => $this->staff->user_id, 'booth_id' => null,
            'starts_at' => CarbonImmutable::parse('2026-10-01 14:00', 'Asia/Tokyo'), 'ends_at' => CarbonImmutable::parse('2026-10-01 16:00', 'Asia/Tokyo'),
            'buffer_min' => 0, 'status' => ReservationStatus::Completed,
        ]);
        app(StaffAttendanceService::class)->save([
            'staff_id' => $this->staff->user_id, 'business_date' => '2026-10-01',
            'clock_in_at' => '2026-10-01T10:15', 'clock_out_at' => '2026-10-01T19:30', 'status' => 'confirmed',
            'breaks' => [['start_at' => '2026-10-01T12:00', 'end_at' => '2026-10-01T13:00', 'type' => 'break']],
        ], null);

        $row = $this->rows()[0];

        $this->assertSame([['type' => 'late_start', 'minutes' => 15], ['type' => 'overtime', 'minutes' => 30]], $row['flags']);
        // 実働 = 10:15〜19:30（555分）−休憩60 = 495分。予定の実働480分を15分超過。
        $this->assertSame(495, $row['attendance']['work_min']);
        $this->assertSame(15, $row['overtime_min']);
        $this->assertSame(120, $row['booked_min']);
        $this->assertSame(1, $row['booked_count']);
        $this->assertSame((int) round(120 / 495 * 100), $row['utilization']);
    }

    public function test_early_start_and_early_leave_and_tolerance(): void
    {
        app(StaffAttendanceService::class)->save([
            'staff_id' => $this->staff->user_id, 'business_date' => '2026-10-02',
            'clock_in_at' => '2026-10-02T09:30', 'clock_out_at' => '2026-10-02T17:00', 'status' => 'confirmed', 'breaks' => [],
        ], null);
        app(StaffAttendanceService::class)->save([
            'staff_id' => $this->staff->user_id, 'business_date' => '2026-10-01',
            'clock_in_at' => '2026-10-01T10:03', 'clock_out_at' => '2026-10-01T19:02', 'status' => 'confirmed', 'breaks' => [],
        ], null);

        $rows = $this->rows();

        $this->assertSame([], $rows[0]['flags'], '5分未満の差は誤差として扱う');
        $this->assertSame([['type' => 'early_start', 'minutes' => 30], ['type' => 'early_leave', 'minutes' => 60]], $rows[1]['flags']);
    }
}
