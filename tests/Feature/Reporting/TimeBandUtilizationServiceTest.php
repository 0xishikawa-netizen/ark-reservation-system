<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\MinuteIntervals;
use App\Domain\Reporting\StaffUtilizationService;
use App\Domain\Reporting\TimeBandUtilizationService;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TimeBandUtilizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_half_open_boundary_splits_actual_minutes_and_deducts_union_of_blocks(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '10:00', '15:00');
        $this->block($staff, '2026-09-10', '11:45', '12:10');
        $this->block($staff, '2026-09-10', '12:00', '12:15');
        $this->assignment($staff, '2026-09-10', '2026-09-10 02:30:00', '2026-09-10 03:30:00', 60);
        $report = $this->report();
        $morning = $this->row($report, $staff, '2026-09-10', '10_12');
        $midday = $this->row($report, $staff, '2026-09-10', '12_15');
        $this->assertSame(30, $morning['occupied_minutes']);
        $this->assertSame(30, $midday['occupied_minutes']);
        $this->assertSame(105, $morning['bookable_minutes']);
        $this->assertSame(165, $midday['bookable_minutes']);
        $this->assertSame(120, $morning['working_minutes']);
        $this->assertSame(180, $midday['working_minutes']);
        $this->assertEquals(30 / 105, $morning['bookable_utilization_rate']);
        $this->assertSame(60, array_sum(array_column($report['monthly_rows'], 'known_occupied_minutes')));
        $staffReport = app(StaffUtilizationService::class)->forMonth(2026, 9, $staff->user_id, asOfDate: '2026-09-15');
        $this->assertSame($staffReport['monthly_rows'][0]['occupied_minutes'],
            array_sum(array_column($report['monthly_rows'], 'known_occupied_minutes')));
        $this->assertSame(0, $report['outside_band_minutes']);
    }

    public function test_actual_attendance_breaks_special_hours_closed_day_and_outside_band(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '09:00', '22:00');
        $this->shift($staff, '2026-09-11', '10:00', '12:00');
        StoreCalendarDay::query()->create(['business_date' => '2026-09-10', 'status' => 'special_hours', 'opens_at' => '11:00', 'closes_at' => '13:00']);
        StoreCalendarDay::query()->create(['business_date' => '2026-09-11', 'status' => 'closed']);
        $attendance = StaffAttendance::query()->create(['staff_id' => $staff->user_id,
            'business_date' => '2026-09-10', 'clock_in_at' => '2026-09-10 00:00:00',
            'clock_out_at' => '2026-09-10 13:00:00', 'status' => 'confirmed']);
        $attendance->breaks()->createMany([
            ['start_at' => '2026-09-10 03:00:00', 'end_at' => '2026-09-10 04:00:00'],
            ['start_at' => '2026-09-10 03:30:00', 'end_at' => '2026-09-10 04:30:00'],
        ]);
        $this->assignment($staff, '2026-09-10', '2026-09-10 00:30:00', '2026-09-10 02:30:00', 120);
        $report = $this->report();
        $this->assertSame(90, $this->row($report, $staff, '2026-09-10', '10_12')['occupied_minutes']);
        $this->assertSame(60, $this->row($report, $staff, '2026-09-10', '10_12')['bookable_minutes']);
        $this->assertSame(30, $report['outside_band_minutes']);
        $this->assertSame(0, $this->row($report, $staff, '2026-09-11', '10_12')['bookable_minutes']);
        $this->assertNull($this->row($report, $staff, '2026-09-11', '10_12')['bookable_utilization_rate']);
    }

    public function test_missing_timestamp_is_not_guessed_and_zero_denominator_is_null(): void
    {
        $staff = Staff::factory()->create();
        $this->assignment($staff, '2026-09-10', null, null, 60);
        $report = $this->report();
        $row = $this->row($report, $staff, '2026-09-10', '10_12');
        $this->assertNull($row['occupied_minutes']);
        $this->assertSame(1, $row['occupied_unknown_count']);
        $this->assertSame(0, $row['bookable_minutes']);
        $this->assertNull($row['bookable_utilization_rate']);
        $this->assertNull($report['overall_rows'][0]['occupied_minutes']);
    }

    public function test_overnight_interval_is_split_by_jst_business_date(): void
    {
        $staff = Staff::factory()->create();
        $this->assignment($staff, '2026-09-10', '2026-09-10 09:30:00', '2026-09-10 15:30:00', 360);
        $report = $this->report();
        $this->assertSame(150, $this->row($report, $staff, '2026-09-10', '18_21')['occupied_minutes']);
        $this->assertSame(210, $report['outside_band_minutes']);
    }

    public function test_interval_subtraction_preserves_total_and_does_not_double_count_overlap(): void
    {
        $remaining = MinuteIntervals::subtract([[600, 900]], [[700, 740], [720, 780]]);
        $this->assertSame([[600, 700], [780, 900]], $remaining);
        $this->assertSame(220, MinuteIntervals::minutes($remaining));
    }

    public function test_multiple_bands_reconcile_to_original_interval_and_staff_filter_isolated(): void
    {
        $a = Staff::factory()->create();
        $b = Staff::factory()->create();
        $this->shift($a, '2026-09-10', '10:00', '21:00');
        $this->assignment($a, '2026-09-10', '2026-09-10 02:00:00', '2026-09-10 10:00:00', 480);
        $this->assignment($b, '2026-09-10', '2026-09-10 02:00:00', '2026-09-10 03:00:00', 60);
        $report = app(TimeBandUtilizationService::class)->forMonth(2026, 9, $a->user_id, '2026-09-15');
        $this->assertSame(480, array_sum(array_map(static fn (array $row): int => $row['known_occupied_minutes'], $report['monthly_rows'])));
        $this->assertSame(0, $report['outside_band_minutes']);
        $this->assertSame(480, array_sum(array_map(static fn (array $row): int => $row['known_occupied_minutes'], $report['overall_rows'])));
        $this->assertSame(480 / 660, array_sum(array_column($report['overall_rows'], 'known_occupied_minutes')) /
            array_sum(array_column($report['overall_rows'], 'bookable_minutes')));
    }

    public function test_band_visit_counts_store_daily_rows_and_weekday_weekend_use_sums(): void
    {
        $a = Staff::factory()->create(['display_name' => 'A']);
        $b = Staff::factory()->create(['display_name' => 'B']);
        $this->shift($a, '2026-09-10', '10:00', '15:00');
        $this->shift($b, '2026-09-10', '10:00', '15:00');
        $this->shift($a, '2026-09-12', '10:00', '12:00');
        // 1来店を 11:30〜12:00 A、12:00〜12:30 B で担当（JST）。
        $visit = Visit::factory()->create(['business_date' => '2026-09-10', 'status' => 'completed', 'primary_staff_id' => $a->user_id]);
        $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id, 'status' => 'completed', 'actual_minutes' => 60]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'staff_id' => $a->user_id,
            'actual_started_at' => '2026-09-10 02:30:00', 'actual_ended_at' => '2026-09-10 03:00:00', 'actual_minutes' => 30]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'staff_id' => $b->user_id,
            'actual_started_at' => '2026-09-10 03:00:00', 'actual_ended_at' => '2026-09-10 03:30:00', 'actual_minutes' => 30]);
        $this->assignment($a, '2026-09-12', '2026-09-12 01:00:00', '2026-09-12 02:00:00', 60);

        $report = $this->report();
        $store = collect($report['store_daily_rows'])->keyBy(fn (array $row): string => $row['business_date'].'|'.$row['band_code']);
        $this->assertSame(1, $store['2026-09-10|10_12']['visit_count']);
        $this->assertSame(1, $store['2026-09-10|12_15']['visit_count']);
        $this->assertSame(0, $store['2026-09-10|15_18']['visit_count']);
        $this->assertSame(60, $store['2026-09-10|10_12']['occupied_minutes'] + $store['2026-09-10|12_15']['occupied_minutes']);
        $this->assertSame(240, $store['2026-09-10|10_12']['bookable_minutes']);
        $this->assertEquals(30 / 240, $store['2026-09-10|10_12']['bookable_utilization_rate']);
        $this->assertSame(1, $this->row($report, $a, '2026-09-10', '10_12')['visit_count']);
        $this->assertSame(0, $this->row($report, $a, '2026-09-10', '12_15')['visit_count']);
        $this->assertSame(1, $this->row($report, $b, '2026-09-10', '12_15')['visit_count']);

        $types = collect($report['day_type_rows'])->keyBy(fn (array $row): string => $row['day_type'].'|'.$row['band_code']);
        // 平日10〜12: 稼働30分 / 予約可能240分（A・B）。土日10〜12: 60分 / 120分。日率の平均ではなく分子・分母の合計。
        $this->assertEquals(30 / 240, $types['weekday|10_12']['bookable_utilization_rate']);
        $this->assertEquals(60 / 120, $types['weekend|10_12']['bookable_utilization_rate']);
        $this->assertSame(1, $types['weekend|10_12']['visit_count']);
        $this->assertSame(2, collect($report['overall_rows'])->firstWhere('band_code', '10_12')['visit_count']);
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return app(TimeBandUtilizationService::class)->forMonth(2026, 9, asOfDate: '2026-09-15');
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function row(array $report, Staff $staff, string $date, string $band): array
    {
        foreach ($report['daily_rows'] as $row) {
            if ($row['staff_id'] === $staff->user_id && $row['business_date'] === $date && $row['band_code'] === $band) {
                return $row;
            }
        }
        $this->fail('時間帯行が見つかりません。');
    }

    private function shift(Staff $staff, string $date, string $start, string $end): void
    {
        StaffShift::query()->create(['staff_id' => $staff->user_id, 'work_date' => $date, 'start_at' => $start, 'end_at' => $end]);
    }

    private function block(Staff $staff, string $date, string $start, string $end): void
    {
        StaffScheduleBlock::query()->create(['staff_id' => $staff->user_id, 'work_date' => $date,
            'start_at' => $start, 'end_at' => $end, 'type' => 'BREAK']);
    }

    private function assignment(Staff $staff, string $date, ?string $start, ?string $end, int $minutes): void
    {
        $visit = Visit::factory()->create(['business_date' => $date, 'status' => 'completed', 'primary_staff_id' => $staff->user_id]);
        $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id, 'status' => 'completed', 'actual_minutes' => $minutes]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'staff_id' => $staff->user_id,
            'actual_started_at' => $start, 'actual_ended_at' => $end, 'actual_minutes' => $minutes]);
    }
}
