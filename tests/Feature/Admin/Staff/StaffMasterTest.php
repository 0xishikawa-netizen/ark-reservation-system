<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Staff;

use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_users_without_staff_manage_permission_cannot_edit_staff(): void
    {
        $target = $this->staff('編集対象', 'staff');

        foreach (['manager', 'staff', 'customer'] as $role) {
            $user = User::factory()->create([
                'two_factor_confirmed_at' => now(),
            ]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->get("/admin/staff/{$target->user_id}/edit")
                ->assertForbidden();
        }
    }

    public function test_staff_update_requires_recent_password_confirmation(): void
    {
        $admin = $this->admin();
        $target = $this->staff('更新前', 'staff');

        $this->actingAs($admin)
            ->put("/admin/staff/{$target->user_id}", $this->updatePayload('更新後', 'staff'))
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('staff', [
            'user_id' => $target->user_id,
            'display_name' => '更新前',
        ]);
    }

    public function test_index_returns_role_and_orders_staff_by_sort_order(): void
    {
        $admin = $this->admin();
        $second = $this->staff('2番目', 'manager');
        $first = $this->staff('1番目', 'staff');
        $second->update(['sort_order' => 20]);
        $first->update(['sort_order' => 10]);

        $this->actingAs($admin)
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Staff/Index')
                ->where('staff.0.display_name', '1番目')
                ->where('staff.0.role', 'staff')
                ->where('staff.1.display_name', '2番目')
                ->where('staff.1.role', 'manager'));
    }

    public function test_admin_can_update_staff_with_one_audit_log(): void
    {
        $admin = $this->admin();
        $target = $this->staff('更新前', 'staff');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put("/admin/staff/{$target->user_id}", $this->updatePayload('更新後', 'staff'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.staff.index'));

        $this->assertDatabaseHas('staff', [
            'user_id' => $target->user_id,
            'display_name' => '更新後',
            'color' => '#336699',
            'is_bookable' => true,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'staff.updated',
            'entity_type' => Staff::class,
            'entity_id' => (string) $target->user_id,
        ]);
    }

    public function test_admin_can_change_staff_role_to_manager_with_audit_log(): void
    {
        $admin = $this->admin();
        $target = $this->staff('ロール変更対象', 'staff');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put("/admin/staff/{$target->user_id}", $this->updatePayload('ロール変更対象', 'manager'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->user->refresh()->hasRole('manager'));
        $this->assertFalse($target->user->hasRole('staff'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.role_changed',
            'entity_id' => (string) $target->user_id,
            'summary' => 'スタッフ「ロール変更対象」のロールを staff → manager に変更',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.updated',
            'entity_id' => (string) $target->user_id,
        ]);
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->admin(withStaff: true);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->putJson("/admin/staff/{$admin->id}", $this->updatePayload('唯一の管理者', 'manager'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $admin->refresh()->unsetRelation('roles');

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_be_demoted_when_another_admin_remains(): void
    {
        $admin = $this->admin();
        $targetAdmin = $this->admin(withStaff: true);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put("/admin/staff/{$targetAdmin->id}", $this->updatePayload('降格対象', 'manager'))
            ->assertSessionHasNoErrors();

        $targetAdmin->refresh()->unsetRelation('roles');

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($targetAdmin->hasRole('manager'));
        $this->assertFalse($targetAdmin->hasRole('admin'));
    }

    public function test_deactivate_only_disables_booking_and_preserves_user_and_role(): void
    {
        $admin = $this->admin();
        $target = $this->staff('無効化対象', 'staff');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch("/admin/staff/{$target->user_id}/deactivate")
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.staff.index'));

        $this->assertDatabaseHas('staff', [
            'user_id' => $target->user_id,
            'is_bookable' => false,
        ]);
        $this->assertDatabaseHas('users', ['id' => $target->user_id]);
        $this->assertTrue($target->user->refresh()->hasRole('staff'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.deactivated',
            'entity_id' => (string) $target->user_id,
        ]);
    }

    public function test_admin_can_disable_staff_login_with_audit_log(): void
    {
        $admin = $this->admin();
        $target = $this->staff('ログイン無効化対象', 'staff');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put("/admin/staff/{$target->user_id}", [
                ...$this->updatePayload('ログイン無効化対象', 'staff'),
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($target->user->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.login_access_changed',
            'entity_id' => (string) $target->user_id,
        ]);
    }

    public function test_admin_cannot_disable_their_own_login(): void
    {
        $admin = $this->admin(withStaff: true);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->putJson("/admin/staff/{$admin->id}", [
                ...$this->updatePayload('管理者', 'admin'),
                'is_active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_last_active_admin_login_cannot_be_disabled(): void
    {
        $lastAdmin = $this->admin(withStaff: true);

        // staff.manage は通常 admin 専用だが、ロール権限管理画面で manager 等にも
        // 付与され得るため、admin ではない操作者でも起こり得るケースとして検証する。
        $actor = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $actor->givePermissionTo(['admin.access', 'staff.manage']);

        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->putJson("/admin/staff/{$lastAdmin->id}", [
                ...$this->updatePayload('管理者', 'admin'),
                'is_active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($lastAdmin->refresh()->is_active);
    }

    public function test_admin_login_can_be_disabled_when_another_active_admin_remains(): void
    {
        $actor = $this->admin();
        $otherAdmin = $this->admin(withStaff: true);

        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put("/admin/staff/{$otherAdmin->id}", [
                ...$this->updatePayload('管理者', 'admin'),
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($otherAdmin->refresh()->is_active);
    }

    /** @return array<string, mixed> */
    private function updatePayload(string $displayName, string $role): array
    {
        return [
            'display_name' => $displayName,
            'color' => '#336699',
            'is_bookable' => true,
            'sort_order' => 10,
            'role' => $role,
        ];
    }

    private function admin(bool $withStaff = false): User
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        if ($withStaff) {
            Staff::query()->create([
                'user_id' => $admin->id,
                'display_name' => '管理者',
            ]);
        }

        return $admin;
    }

    private function staff(string $displayName, string $role): Staff
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return Staff::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
        ]);
    }
}
