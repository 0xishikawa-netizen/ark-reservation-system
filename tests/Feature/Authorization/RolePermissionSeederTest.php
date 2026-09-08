<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame(13, $firstCounts['permissions']);
        $this->assertSame(23, $firstCounts['role_has_permissions']);
        $this->assertTrue($this->roleHasPermission('admin', 'booths.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'shifts.manage'));
        $this->assertTrue($this->roleHasPermission('admin', 'customers.view'));
        $this->assertTrue($this->roleHasPermission('admin', 'customers.manage'));
        $this->assertTrue($this->roleHasPermission('manager', 'customers.view'));
        $this->assertFalse($this->roleHasPermission('manager', 'customers.manage'));
        $this->assertTrue($this->roleHasPermission('staff', 'customers.view'));
        $this->assertFalse($this->roleHasPermission('staff', 'customers.manage'));
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
