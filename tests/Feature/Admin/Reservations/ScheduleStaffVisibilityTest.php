<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Queries\ScheduleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ブッキングボードのスタッフ行は「その日に出勤予定のスタッフ」だけを出す。
 * 判定の正本は予約可能判定と同じ（勤務枠 staff_shifts・店舗カレンダー）。
 */
final class ScheduleStaffVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_view_shows_only_staff_with_a_shift_on_that_day(): void
    {
        $working = $this->staff('出勤A', 1);
        $off = $this->staff('休みB', 2);
        $alsoWorking = $this->staff('出勤C', 3);
        $this->shift($working, '2026-10-01');
        $this->shift($alsoWorking, '2026-10-01');
        $this->shift($off, '2026-10-02');

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(['出勤A', '出勤C'], array_column($result['staff'], 'display_name'));
        $this->assertSame([true, true], array_column($result['staff'], 'is_working'));
        $this->assertSame(['休みB'], array_column($result['off_staff'], 'display_name'));
        // 勤務枠も表示中のスタッフ分だけ返す。
        $this->assertSame([$working->user_id, $alsoWorking->user_id], array_column($result['shifts'], 'staff_id'));
    }

    public function test_staff_with_a_reservation_on_a_day_off_stays_visible_as_off_duty(): void
    {
        $working = $this->staff('出勤A', 1);
        $off = $this->staff('休みB', 2);
        $this->shift($working, '2026-10-01');
        $reservation = $this->reservation($off, '2026-10-01 13:00:00');

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(['出勤A', '休みB'], array_column($result['staff'], 'display_name'));
        $this->assertSame([true, false], array_column($result['staff'], 'is_working'));
        $this->assertSame([], $result['off_staff']);
        $this->assertSame([$reservation->id], array_column($result['reservations'], 'id'));
        // 既存データは変更しない。
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'staff_id' => $off->user_id]);
    }

    public function test_staff_with_a_schedule_block_on_a_day_off_stays_visible(): void
    {
        $off = $this->staff('休みB', 1);
        StaffScheduleBlock::query()->create([
            'staff_id' => $off->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
            'type' => 'MEETING',
        ]);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(['休みB'], array_column($result['staff'], 'display_name'));
        $this->assertFalse($result['staff'][0]['is_working']);
    }

    public function test_store_closed_day_hides_staff_even_if_shifts_exist(): void
    {
        $staff = $this->staff('出勤A', 1);
        $this->shift($staff, '2026-10-01');
        StoreCalendarDay::query()->create([
            'business_date' => '2026-10-01',
            'status' => StoreCalendarDay::STATUS_CLOSED,
        ]);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame([], $result['staff']);
        $this->assertSame(['出勤A'], array_column($result['off_staff'], 'display_name'));
    }

    public function test_week_view_shows_staff_working_on_any_day_of_the_week(): void
    {
        $monday = $this->staff('月曜出勤', 1);
        $sunday = $this->staff('日曜出勤', 2);
        $never = $this->staff('週休み', 3);
        // 2026-10-05(月)〜2026-10-11(日)
        $this->shift($monday, '2026-10-05');
        $this->shift($sunday, '2026-10-11');
        $this->shift($never, '2026-10-12');

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-07'), view: 'week');

        $this->assertSame(['月曜出勤', '日曜出勤'], array_column($result['staff'], 'display_name'));
        $this->assertSame(['週休み'], array_column($result['off_staff'], 'display_name'));
    }

    public function test_explicit_staff_filter_keeps_the_selected_staff_even_on_a_day_off(): void
    {
        $off = $this->staff('休みB', 1);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'), (int) $off->user_id);

        $this->assertSame(['休みB'], array_column($result['staff'], 'display_name'));
        $this->assertFalse($result['staff'][0]['is_working']);
    }

    public function test_non_bookable_staff_with_reservations_are_not_hidden(): void
    {
        $retired = $this->staff('受付停止', 1, false);
        $reservation = $this->reservation($retired, '2026-10-01 10:00:00');

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(['受付停止'], array_column($result['staff'], 'display_name'));
        $this->assertSame([$reservation->id], array_column($result['reservations'], 'id'));
    }

    private function staff(string $name, int $sortOrder, bool $bookable = true): Staff
    {
        return Staff::factory()->create([
            'display_name' => $name,
            'is_bookable' => $bookable,
            'sort_order' => $sortOrder,
        ]);
    }

    private function shift(Staff $staff, string $date): void
    {
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => $date,
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);
    }

    private function reservation(Staff $staff, string $startsAt): Reservation
    {
        $start = CarbonImmutable::parse($startsAt);

        return Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => Service::factory()->create()->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => $start->toDateTimeString(),
            'ends_at' => $start->addHour()->toDateTimeString(),
            'status' => ReservationStatus::Confirmed,
        ]);
    }
}
