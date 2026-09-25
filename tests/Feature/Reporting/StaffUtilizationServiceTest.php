<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\StaffUtilizationService;
use App\Models\Customer;
use App\Models\EmploymentType;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffEmploymentPeriod;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StaffUtilizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_visit_counts_and_actual_assignment_minutes_do_not_multiply(): void
    {
        $a = Staff::factory()->create();
        $b = Staff::factory()->create();
        $this->visit($a, ['2026-09-10' => [$a->user_id => 30, $b->user_id => 30]], true, true);
        $this->visit($a, ['2026-09-11' => [$a->user_id => 45, $b->user_id => 15]], false, false);
        $report = $this->report(2026, 9);
        $aMonthly = $this->monthRow($report, $a);
        $bMonthly = $this->monthRow($report, $b);
        $this->assertSame(2, $aMonthly['patient_count']);
        $this->assertSame(0, $bMonthly['patient_count']);
        $this->assertSame(75, $aMonthly['occupied_minutes']);
        $this->assertSame(45, $bMonthly['occupied_minutes']);
        $this->assertSame(1, $aMonthly['future_reservation_count']);
        $this->assertSame(0, $bMonthly['future_reservation_count']);
        $this->assertSame(1, $aMonthly['nomination_count']);
        $this->assertSame(0.5, $aMonthly['reservation_rate']);
        $this->assertSame(0.5, $aMonthly['nomination_rate']);
    }

    public function test_nomination_unknown_and_primary_unknown_are_not_made_zero(): void
    {
        $a = Staff::factory()->create();
        $this->visit($a, ['2026-09-10' => [$a->user_id => 60]], true, null);
        $this->visit(null, ['2026-09-10' => [$a->user_id => 20]], null, null);
        $report = $this->report(2026, 9);
        $row = $this->dayRow($report, $a, '2026-09-10');
        $this->assertSame(1, $row['patient_count']);
        $this->assertSame(80, $row['occupied_minutes']);
        $this->assertNull($row['nomination_count']);
        $this->assertFalse($row['nomination_supported']);
        $this->assertSame(1, $report['unknown_primary_visit_count']);
    }

    public function test_missing_actual_assignment_minutes_are_unknown_not_zero(): void
    {
        $staff = Staff::factory()->create();
        $visit = Visit::factory()->create(['business_date' => '2026-09-10', 'status' => 'completed',
            'primary_staff_id' => $staff->user_id]);
        $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id, 'status' => 'completed']);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id,
            'staff_id' => $staff->user_id, 'actual_minutes' => null]);
        $row = $this->dayRow($this->report(2026, 9), $staff, '2026-09-10');
        $this->assertNull($row['occupied_minutes']);
        $this->assertSame(1, $row['occupied_unknown_count']);
        $this->assertNull($row['legacy_utilization_rate']);
    }

    public function test_shift_fallback_actual_break_union_and_block_union(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '10:00', '18:00');
        $this->shift($staff, '2026-09-11', '10:00', '18:00');
        $this->block($staff, '2026-09-11', '12:00', '13:00');
        $this->block($staff, '2026-09-11', '12:30', '13:30');
        $attendance = StaffAttendance::query()->create([
            'staff_id' => $staff->user_id, 'business_date' => '2026-09-11',
            'clock_in_at' => '2026-09-11 00:00:00', 'clock_out_at' => '2026-09-11 09:00:00', 'status' => 'confirmed',
        ]);
        $attendance->breaks()->createMany([
            ['start_at' => '2026-09-11 03:00:00', 'end_at' => '2026-09-11 04:00:00'],
            ['start_at' => '2026-09-11 03:30:00', 'end_at' => '2026-09-11 04:30:00'],
        ]);
        $fallback = $this->dayRow($this->report(2026, 9), $staff, '2026-09-10');
        $actual = $this->dayRow($this->report(2026, 9), $staff, '2026-09-11');
        $this->assertSame(480, $fallback['working_minutes']);
        $this->assertSame('scheduled_fallback', $fallback['working_minutes_source']);
        $this->assertSame(450, $actual['working_minutes']);
        $this->assertSame('actual', $actual['working_minutes_source']);
        $this->assertSame(390, $actual['bookable_minutes']);
    }

    public function test_closed_and_special_calendar_change_only_denominator(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '09:00', '18:00');
        $this->shift($staff, '2026-09-11', '09:00', '18:00');
        StoreCalendarDay::query()->create(['business_date' => '2026-09-10', 'status' => 'closed']);
        StoreCalendarDay::query()->create(['business_date' => '2026-09-11', 'status' => 'special_hours', 'opens_at' => '10:00', 'closes_at' => '15:00']);
        $this->visit($staff, ['2026-09-10' => [$staff->user_id => 60]], false, false);
        $report = $this->report(2026, 9);
        $closed = $this->dayRow($report, $staff, '2026-09-10');
        $special = $this->dayRow($report, $staff, '2026-09-11');
        $this->assertSame(0, $closed['bookable_minutes']);
        $this->assertSame(60, $closed['occupied_minutes']);
        $this->assertNull($closed['bookable_utilization_rate']);
        $this->assertSame(300, $special['bookable_minutes']);
    }

    public function test_480_work_minutes_and_360_bookable_minutes_yield_independent_rates(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '10:00', '18:00');
        $this->block($staff, '2026-09-10', '14:00', '15:00');
        $attendance = StaffAttendance::query()->create([
            'staff_id' => $staff->user_id, 'business_date' => '2026-09-10',
            'clock_in_at' => '2026-09-10 00:00:00', 'clock_out_at' => '2026-09-10 09:00:00',
            'status' => 'confirmed',
        ]);
        $attendance->breaks()->create(['start_at' => '2026-09-10 03:00:00', 'end_at' => '2026-09-10 04:00:00']);
        $this->visit($staff, ['2026-09-10' => [$staff->user_id => 180]], false, false);
        $row = $this->dayRow($this->report(2026, 9), $staff, '2026-09-10');
        $this->assertSame(480, $row['working_minutes']);
        $this->assertSame(360, $row['bookable_minutes']);
        $this->assertSame(180 / 480, $row['legacy_utilization_rate']);
        $this->assertSame(0.5, $row['bookable_utilization_rate']);
    }

    public function test_missing_clock_out_is_unknown_and_does_not_fall_back_to_shift(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '10:00', '18:00');
        StaffAttendance::query()->create(['staff_id' => $staff->user_id, 'business_date' => '2026-09-10',
            'clock_in_at' => '2026-09-10 01:00:00', 'clock_out_at' => null]);
        $row = $this->dayRow($this->report(2026, 9), $staff, '2026-09-10');
        $this->assertNull($row['working_minutes']);
        $this->assertSame('unknown', $row['working_minutes_source']);
        $this->assertSame(480, $row['bookable_minutes']);
        $this->assertNull($this->monthRow($this->report(2026, 9), $staff)['working_minutes']);
    }

    public function test_employment_history_filter_and_weighted_monthly_rate(): void
    {
        $staff = Staff::factory()->create();
        $part = EmploymentType::query()->create(['code' => 'part_time', 'name' => 'アルバイト']);
        $employee = EmploymentType::query()->create(['code' => 'employee', 'name' => '社員']);
        StaffEmploymentPeriod::query()->create(['staff_id' => $staff->user_id, 'employment_type_id' => $part->id,
            'effective_from' => '2026-09-01', 'effective_to' => '2026-10-01']);
        StaffEmploymentPeriod::query()->create(['staff_id' => $staff->user_id, 'employment_type_id' => $employee->id,
            'effective_from' => '2026-10-01']);
        $this->shift($staff, '2026-09-10', '10:00', '12:00');
        $this->shift($staff, '2026-09-11', '10:00', '18:00');
        $this->visit($staff, ['2026-09-10' => [$staff->user_id => 60]], true, true);
        $this->visit($staff, ['2026-09-11' => [$staff->user_id => 60]], false, false);
        $september = $this->report(2026, 9);
        $monthly = $this->monthRow($september, $staff);
        $this->assertSame(600, $monthly['working_minutes']);
        $this->assertSame(120, $monthly['occupied_minutes']);
        $this->assertSame(0.2, $monthly['legacy_utilization_rate']);
        $this->assertSame('part_time', $this->dayRow($september, $staff, '2026-09-10')['employment_type_code']);
        $this->assertCount(0, $this->report(2026, 9, $employee->id)['daily_rows']);
        $this->assertSame('employee', $this->dayRow($this->report(2026, 10), $staff, '2026-10-01')['employment_type_code']);
    }

    public function test_monthly_reservation_and_nomination_rates_use_total_visits_not_mean_of_daily_rates(): void
    {
        $staff = Staff::factory()->create();
        $this->visit($staff, ['2026-09-10' => [$staff->user_id => 30]], true, true);
        for ($i = 0; $i < 9; $i++) {
            $this->visit($staff, ['2026-09-11' => [$staff->user_id => 30]], $i < 5, $i === 0);
        }
        $monthly = $this->monthRow($this->report(2026, 9), $staff);
        $this->assertSame(10, $monthly['patient_count']);
        $this->assertSame(6, $monthly['future_reservation_count']);
        $this->assertSame(2, $monthly['nomination_count']);
        $this->assertSame(0.6, $monthly['reservation_rate']);
        $this->assertSame(0.2, $monthly['nomination_rate']);
    }

    public function test_overnight_attendance_splits_at_jst_midnight_and_no_shift_remains_unknown(): void
    {
        $staff = Staff::factory()->create();
        StaffAttendance::query()->create(['staff_id' => $staff->user_id, 'business_date' => '2026-09-10',
            'clock_in_at' => '2026-09-10 14:00:00', 'clock_out_at' => '2026-09-10 16:00:00', 'status' => 'confirmed']);
        $report = $this->report(2026, 9);
        $this->assertSame(60, $this->dayRow($report, $staff, '2026-09-10')['working_minutes']);
        $this->assertSame(60, $this->dayRow($report, $staff, '2026-09-11')['working_minutes']);
        $this->assertSame('unknown', $this->dayRow($report, $staff, '2026-09-12')['working_minutes_source']);
    }

    public function test_zero_denominators_do_not_hide_over_100_percent_or_invent_rates(): void
    {
        $staff = Staff::factory()->create();
        $this->shift($staff, '2026-09-10', '10:00', '11:00');
        $this->visit($staff, ['2026-09-10' => [$staff->user_id => 120]], false, false);
        $row = $this->dayRow($this->report(2026, 9), $staff, '2026-09-10');
        $this->assertEquals(2.0, $row['legacy_utilization_rate']);
        $this->assertEquals(2.0, $row['bookable_utilization_rate']);
        $empty = $this->dayRow($this->report(2026, 9), $staff, '2026-09-11');
        $this->assertNull($empty['reservation_rate']);
        $this->assertNull($empty['legacy_utilization_rate']);
    }

    public function test_monthly_rows_split_at_employment_change_within_month(): void
    {
        $staff = Staff::factory()->create();
        $part = EmploymentType::query()->create(['code' => 'part_time', 'name' => 'アルバイト']);
        $employee = EmploymentType::query()->create(['code' => 'employee', 'name' => '社員']);
        StaffEmploymentPeriod::query()->create(['staff_id' => $staff->user_id, 'employment_type_id' => $part->id,
            'effective_from' => '2026-09-01', 'effective_to' => '2026-09-11']);
        StaffEmploymentPeriod::query()->create(['staff_id' => $staff->user_id, 'employment_type_id' => $employee->id,
            'effective_from' => '2026-09-11']);
        $this->visit($staff, ['2026-09-10' => [$staff->user_id => 30]], false, false);
        $this->visit($staff, ['2026-09-11' => [$staff->user_id => 45]], false, false);
        $rows = array_values(array_filter($this->report(2026, 9)['monthly_rows'],
            static fn (array $row): bool => $row['staff_id'] === $staff->user_id));
        $this->assertCount(2, $rows);
        $this->assertSame(['part_time', 'employee'], array_column($rows, 'employment_type_code'));
        $this->assertSame([30, 45], array_column($rows, 'occupied_minutes'));
    }

    public function test_fact_query_count_is_fixed_as_staff_and_visits_grow(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            if (str_contains($event->sql, 'visits') || str_contains($event->sql, 'staff_shifts')) {
                $queries[] = $event->sql;
            }
        });
        $this->report(2026, 9);
        $base = count($queries);
        for ($i = 0; $i < 8; $i++) {
            $staff = Staff::factory()->create();
            $this->visit($staff, ['2026-09-10' => [$staff->user_id => 30]], false, false);
        }
        $queries = [];
        $this->report(2026, 9);
        $this->assertSame($base, count($queries));
    }

    public function test_calendar_month_lengths_and_future_default_do_not_create_actual_rows(): void
    {
        $staff = Staff::factory()->create();
        foreach ([[2026, 2, 28], [2028, 2, 29], [2026, 4, 30], [2026, 10, 31]] as [$year, $month, $days]) {
            $report = app(StaffUtilizationService::class)->forMonth(
                $year, $month, asOfDate: sprintf('%04d-%02d-%02d', $year, $month, $days),
            );
            $this->assertCount($days, array_values(array_filter($report['daily_rows'],
                static fn (array $row): bool => $row['staff_id'] === $staff->user_id)));
        }
        $future = app(StaffUtilizationService::class)->forMonth(2099, 1);
        $this->assertSame([], $future['daily_rows']);
        $this->assertSame([], $future['monthly_rows']);
    }

    /** @param array<string,array<int,int>> $assignments */
    private function visit(?Staff $primary, array $assignments, ?bool $future, ?bool $nominated): void
    {
        foreach ($assignments as $date => $staffMinutes) {
            $visit = Visit::factory()->create(['customer_id' => Customer::factory(), 'business_date' => $date,
                'status' => 'completed', 'primary_staff_id' => $primary?->user_id,
                'future_reservation_exists_at_checkout' => $future,
                'staff_requested_at_checkout' => $nominated,
                'requested_staff_id_at_checkout' => $nominated ? $primary?->user_id : null]);
            $treatment = VisitTreatment::factory()->create(['visit_id' => $visit->id, 'status' => 'completed']);
            foreach ($staffMinutes as $staffId => $minutes) {
                VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id,
                    'staff_id' => $staffId, 'actual_minutes' => $minutes]);
            }
        }
    }

    private function shift(Staff $staff, string $date, string $from, string $to): void
    {
        StaffShift::query()->create(['staff_id' => $staff->user_id, 'work_date' => $date, 'start_at' => $from, 'end_at' => $to]);
    }

    private function block(Staff $staff, string $date, string $from, string $to): void
    {
        StaffScheduleBlock::query()->create(['staff_id' => $staff->user_id, 'work_date' => $date,
            'start_at' => $from, 'end_at' => $to, 'type' => 'BREAK']);
    }

    /** @return array<string,mixed> */
    private function report(int $year, int $month, ?int $typeId = null): array
    {
        return app(StaffUtilizationService::class)->forMonth($year, $month, employmentTypeId: $typeId,
            asOfDate: sprintf('%04d-%02d-%02d', $year, $month, 15));
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function dayRow(array $report, Staff $staff, string $date): array
    {
        foreach ($report['daily_rows'] as $row) {
            if ($row['staff_id'] === $staff->user_id && $row['business_date'] === $date) {
                return $row;
            }
        }
        $this->fail('Daily row not found');
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function monthRow(array $report, Staff $staff): array
    {
        foreach ($report['monthly_rows'] as $row) {
            if ($row['staff_id'] === $staff->user_id) {
                return $row;
            }
        }
        $this->fail('Monthly row not found');
    }
}
