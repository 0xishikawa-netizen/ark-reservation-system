<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Settings;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 「ロール権限管理」画面（staff / manager が見られる画面・できる操作を設定する）のテスト。
 */
final class RolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_non_admin_cannot_view_or_update_role_permissions(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->get('/admin/settings/roles')
                ->assertForbidden();

            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->patchJson('/admin/settings/roles', ['staff' => [], 'manager' => []])
                ->assertForbidden();
        }
    }

    public function test_admin_can_view_current_role_permissions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get('/admin/settings/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Roles')
                ->where('role_permissions.staff', ['admin.access', 'customers.view', 'reservations.view'])
                ->has('groups'));
    }

    public function test_admin_can_grant_a_permission_to_staff(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/roles', [
                'staff' => ['customers.view', 'reservations.view', 'reservations.manage'],
                'manager' => [
                    'failed_jobs.view', 'audit_logs.view', 'customers.view',
                    'reservations.view', 'reservations.manage', 'refund.execute',
                    'ticket.grant', 'membership.manage', 'integrations.view',
                ],
            ])
            ->assertSessionHasNoErrors();

        $staffRole = Role::where('name', 'staff')->firstOrFail();
        $this->assertTrue($staffRole->hasPermissionTo('reservations.manage'));
        // admin.access はチェックボックスに出さないが、常に付与されたままになる。
        $this->assertTrue($staffRole->hasPermissionTo('admin.access'));
    }

    public function test_saving_does_not_strip_manager_of_preexisting_locked_settings_permission(): void
    {
        $admin = $this->admin();

        // manager は初期設定で 'settings.manage' を持つ（この画面には出ないが保持され続ける）。
        $this->assertTrue(Role::where('name', 'manager')->firstOrFail()->hasPermissionTo('settings.manage'));

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/roles', [
                'staff' => [],
                'manager' => ['customers.view'],
            ])
            ->assertSessionHasNoErrors();

        $managerRole = Role::where('name', 'manager')->firstOrFail();
        $this->assertTrue($managerRole->hasPermissionTo('settings.manage'));
        $this->assertTrue($managerRole->hasPermissionTo('customers.view'));

        // staff はもともと 'settings.manage' を持たないため、この画面経由で付与されない。
        $staffRole = Role::where('name', 'staff')->firstOrFail();
        $this->assertFalse($staffRole->hasPermissionTo('settings.manage'));
    }

    public function test_admin_role_is_always_full_and_not_editable_via_this_endpoint(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patch('/admin/settings/roles', [
                'staff' => [],
                'manager' => [],
            ])
            ->assertSessionHasNoErrors();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->assertTrue($adminRole->hasPermissionTo('roles.manage'));
        $this->assertTrue($adminRole->hasPermissionTo('staff.manage'));
    }

    public function test_unknown_permission_names_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->patchJson('/admin/settings/roles', [
                'staff' => ['not-a-real-permission'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff.0');
    }

    public function test_admin_only_settings_permissions_cannot_be_delegated_to_staff_or_manager(): void
    {
        $admin = $this->admin();

        foreach (['settings.manage', 'integrations.manage'] as $permission) {
            $this->actingAs($admin)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->patchJson('/admin/settings/roles', [
                    'manager' => [$permission],
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('manager.0');
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
