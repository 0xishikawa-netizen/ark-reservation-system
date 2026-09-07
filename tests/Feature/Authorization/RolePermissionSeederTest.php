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
        $this->assertSame(8, $firstCounts['permissions']);
        $this->assertSame(16, $firstCounts['role_has_permissions']);
        $this->assertFalse($this->roleHasPermission('manager', 'staff.manage'));
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
