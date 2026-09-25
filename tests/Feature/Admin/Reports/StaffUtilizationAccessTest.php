<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StaffUtilizationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_report_uses_reports_view_and_attendance_edit_uses_shifts_manage(): void
    {
        $staffUser = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staffUser->assignRole('staff');
        $staff = Staff::factory()->create();
        $this->actingAs($staffUser)->get('/admin/reports/staff-utilization')->assertForbidden();
        $staffUser->givePermissionTo('reports.view');
        $this->actingAs($staffUser)->get('/admin/reports/staff-utilization?year=2026&month=9&as_of_date=2026-09-15')
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Reports/StaffUtilization')->where('report.month_key', '2026-09'));
        $this->actingAs($staffUser)->getJson('/admin/reports/staff-utilization/data?year=2026&month=9&staff_id='.$staff->user_id.'&as_of_date=2026-09-15')
            ->assertOk()->assertJsonPath('data.daily_rows.0.staff_id', $staff->user_id);
        $payload = $this->payload($staff);
        $this->actingAs($staffUser)->postJson('/admin/staff-shifts/attendances', $payload)->assertForbidden();
        $staffUser->givePermissionTo('shifts.manage');
        $this->actingAs($staffUser)->post('/admin/staff-shifts/attendances', $payload)->assertRedirect();
        $attendance = StaffAttendance::query()->firstOrFail();
        $this->assertSame('2026-09-10 00:00:00', $attendance->clock_in_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $attendance->breaks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_attendance.created', 'entity_id' => (string) $attendance->id]);
        $this->actingAs($staffUser)->put('/admin/staff-shifts/attendances/'.$attendance->id, [
            ...$payload, 'note' => '修正', 'breaks' => [],
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_attendance.updated', 'entity_id' => (string) $attendance->id]);
        $this->assertSame(0, $attendance->breaks()->count());
    }

    public function test_attendance_validation_and_report_filters(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $staff = Staff::factory()->create();
        $this->actingAs($admin)->postJson('/admin/staff-shifts/attendances', [
            ...$this->payload($staff), 'clock_out_at' => '2026-09-10T08:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('clock_out_at');
        $this->actingAs($admin)->getJson('/admin/reports/staff-utilization/data?year=1999&month=13')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/reports/staff-utilization/data?year=2026&month=9&as_of_date=2026-10-01')->assertUnprocessable();
    }

    /** @return array<string,mixed> */
    private function payload(Staff $staff): array
    {
        return [
            'staff_id' => $staff->user_id, 'business_date' => '2026-09-10',
            'clock_in_at' => '2026-09-10T09:00', 'clock_out_at' => '2026-09-10T18:00',
            'status' => 'confirmed', 'note' => null,
            'breaks' => [['start_at' => '2026-09-10T12:00', 'end_at' => '2026-09-10T13:00', 'type' => 'break']],
        ];
    }
}
