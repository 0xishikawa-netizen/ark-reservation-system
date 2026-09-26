<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $firstCounts = $this->permissionTableCounts();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($firstCounts, $this->permissionTableCounts());
        $this->assertSame(4, $firstCounts['roles']);
        $this->assertSame(26, $firstCounts['permissions']);
        $this->assertSame(40, $firstCounts['role_has_permissions']);
        $this->assertTrue($this->roleHasPermission('admin', 'roles.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'roles.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'roles.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'settings.manage'));
        $this->assertTrue($this->roleHasPermission('manager', 'settings.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'settings.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'integrations.manage'));
        $this->assertTrue($this->roleHasPermission('manager', 'integrations.view'));
        $this->assertFalse($this->roleHasPermission('manager', 'integrations.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'integrations.view'));
        $this->assertTrue($this->roleHasPermission('admin', 'reports.view'));
        $this->assertTrue($this->roleHasPermission('admin', 'reports.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'reports.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'reports.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'reports.export'));
        $this->assertTrue($this->roleHasPermission('admin', 'reports.reconcile'));
        $this->assertTrue($this->roleHasPermission('admin', 'historical_data.import'));
        $this->assertFalse($this->roleHasPermission('manager', 'historical_data.import'));
        $this->assertFalse($this->roleHasPermission('manager', 'reports.export'));
        $this->assertTrue($this->roleHasPermission('admin', 'sales.view'));
        $this->assertFalse($this->roleHasPermission('manager', 'reports.view'));
        $this->assertFalse($this->roleHasPermission('manager', 'sales.view'));
        $this->assertTrue($this->roleHasPermission('admin', 'booths.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'shifts.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'customers.view'));
        $this->assertTrue($this->roleHasPermission('admin', 'customers.manage'));
        $this->assertTrue($this->roleHasPermission('manager', 'customers.view'));
        $this->assertFalse($this->roleHasPermission('manager', 'customers.manage'));
        $this->assertTrue($this->roleHasPermission('staff', 'customers.view'));
        $this->assertFalse($this->roleHasPermission('staff', 'customers.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'reservations.view'));
        $this->assertTrue($this->roleHasPermission('admin', 'reservations.manage'));
        $this->assertTrue($this->roleHasPermission('manager', 'reservations.view'));
        $this->assertTrue($this->roleHasPermission('manager', 'reservations.manage'));
        $this->assertTrue($this->roleHasPermission('staff', 'reservations.view'));
        $this->assertFalse($this->roleHasPermission('staff', 'reservations.manage'));
        $this->assertFalse($this->roleHasPermission('customer', 'reservations.view'));
        $this->assertFalse($this->roleHasPermission('customer', 'reservations.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'staff.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'services.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'booths.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'shifts.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'booths.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'shifts.manage'));
        $this->assertFalse($this->roleHasPermission('customer', 'booths.manage'));
        $this->assertFalse($this->roleHasPermission('customer', 'shifts.manage'));
        $this->assertFalse($this->roleHasPermission('customer', 'customers.view'));
        $this->assertFalse($this->roleHasPermission('customer', 'customers.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'ticket_policy.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'ticket_policy.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'ticket_policy.manage'));
        $this->assertFalse($this->roleHasPermission('customer', 'ticket_policy.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'ticket_products.manage'));
        $this->assertFalse($this->roleHasPermission('manager', 'ticket_products.manage'));
        $this->assertFalse($this->roleHasPermission('staff', 'ticket_products.manage'));
        $this->assertFalse($this->roleHasPermission('customer', 'ticket_products.manage'));
    }

    public function test_new_web_permission_is_synced_only_to_admin_superuser(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Permission::query()->create(['name' => 'future.admin.test', 'guard_name' => 'web']);

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue($this->roleHasPermission('admin', 'future.admin.test'));
        $this->assertFalse($this->roleHasPermission('manager', 'future.admin.test'));
        $this->assertFalse($this->roleHasPermission('staff', 'future.admin.test'));
        $this->assertFalse($this->roleHasPermission('customer', 'future.admin.test'));
    }

    /** @return array{roles: int, permissions: int, role_has_permissions: int} */
    private function permissionTableCounts(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'role_has_permissions' => DB::table('role_has_permissions')->count(),
        ];
    }

    private function roleHasPermission(string $role, string $permission): bool
    {
        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)
            ->where('roles.guard_name', 'web')
            ->where('permissions.name', $permission)
            ->where('permissions.guard_name', 'web')
            ->exists();
    }
}
