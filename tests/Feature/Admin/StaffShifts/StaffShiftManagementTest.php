<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\StaffShifts;

use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_users_without_shifts_manage_permission_cannot_use_shift_api(): void
    {
        $staff = $this->staff('勤務対象');

        foreach (['manager', 'staff', 'customer'] as $role) {
            $user = User::factory()->create([
                'two_factor_confirmed_at' => now(),
            ]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->postJson('/admin/staff-shifts', $this->shiftPayload($staff))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_admin_can_view_current_week_shift_list(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/staff-shifts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StaffShifts/Index')
                ->has('shifts', 0)
                ->where('auth.can.shiftsManage', true));
    }

    public function test_shift_list_includes_origin_so_manual_shifts_are_listed(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('担当手動');
        $date = now('Asia/Tokyo')->addDay()->toDateString();
        StaffShift::query()->create([
            'staff_id' => $staff->user_id, 'work_date' => $date, 'start_at' => '12:00:00', 'end_at' => '18:00:00',
            'origin' => StaffShift::ORIGIN_MANUAL,
        ]);

        $this->actingAs($admin)
            ->get('/admin/staff-shifts?staff_id='.$staff->user_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('shifts.0.origin', StaffShift::ORIGIN_MANUAL));
    }

    public function test_admin_can_create_update_and_delete_shift(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('勤務対象');

        $this->actingAs($admin)
            ->post('/admin/staff-shifts', $this->shiftPayload($staff))
            ->assertSessionHasNoErrors();

        $shift = StaffShift::query()->firstOrFail();

        $this->assertDatabaseHas('staff_shifts', [
            'id' => $shift->id,
            'staff_id' => $staff->user_id,
            'work_date' => '2026-09-08',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff_shift.created',
            'entity_id' => (string) $shift->id,
        ]);

        $this->actingAs($admin)
            ->put("/admin/staff-shifts/{$shift->id}", [
                'work_date' => '2026-09-09',
                'start_at' => '10:00',
                'end_at' => '16:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('staff_shifts', [
            'id' => $shift->id,
            'work_date' => '2026-09-09',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff_shift.updated',
            'entity_id' => (string) $shift->id,
        ]);

        $this->actingAs($admin)
            ->delete("/admin/staff-shifts/{$shift->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('staff_shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff_shift.deleted',
            'entity_id' => (string) $shift->id,
        ]);
    }

    public function test_start_time_must_be_before_end_time(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('勤務対象');

        $this->actingAs($admin)
            ->postJson('/admin/staff-shifts', [
                ...$this->shiftPayload($staff),
                'start_at' => '12:00',
                'end_at' => '12:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_at');

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_overlapping_shift_is_rejected(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('勤務対象');
        StaffShift::query()->create($this->shiftPayload($staff));

        $this->actingAs($admin)
            ->postJson('/admin/staff-shifts', [
                ...$this->shiftPayload($staff),
                'start_at' => '11:00',
                'end_at' => '13:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_at');

        $this->assertDatabaseCount('staff_shifts', 1);
    }

    public function test_update_cannot_overlap_another_shift(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('勤務対象');
        $firstShift = StaffShift::query()->create($this->shiftPayload($staff));
        StaffShift::query()->create([
            ...$this->shiftPayload($staff),
            'start_at' => '13:00',
            'end_at' => '17:00',
        ]);

        $this->actingAs($admin)
            ->putJson("/admin/staff-shifts/{$firstShift->id}", [
                'work_date' => '2026-09-08',
                'start_at' => '11:00',
                'end_at' => '14:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_at');

        $this->assertSame('09:00', substr((string) $firstShift->refresh()->start_at, 0, 5));
    }

    public function test_non_overlapping_split_shifts_can_be_created(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('勤務対象');
        StaffShift::query()->create($this->shiftPayload($staff));

        $this->actingAs($admin)
            ->postJson('/admin/staff-shifts', [
                ...$this->shiftPayload($staff),
                'start_at' => '12:00',
                'end_at' => '15:00',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('staff_shifts', 2);
    }

    /** @return array<string, mixed> */
    private function shiftPayload(Staff $staff): array
    {
        return [
            'staff_id' => $staff->user_id,
            'work_date' => '2026-09-08',
            'start_at' => '09:00',
            'end_at' => '12:00',
        ];
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function staff(string $displayName): Staff
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return Staff::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
        ]);
    }
}
