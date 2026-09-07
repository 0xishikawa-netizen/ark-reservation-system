<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const PERMISSIONS = [
        'admin.access',
        'failed_jobs.view',
        'audit_logs.view',
        'staff.manage',
        'settings.manage',
        'refund.execute',
        'ticket.grant',
        'membership.manage',
    ];

    public function test_each_role_has_only_its_explicit_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $expected = [
            'admin' => self::PERMISSIONS,
            'manager' => [
                'admin.access',
                'failed_jobs.view',
                'audit_logs.view',
                'settings.manage',
                'refund.execute',
                'ticket.grant',
                'membership.manage',
            ],
            'staff' => ['admin.access'],
            'customer' => [],
        ];

        foreach ($expected as $role => $allowedPermissions) {
            $user = User::factory()->create();
            $user->assignRole($role);

            foreach (self::PERMISSIONS as $permission) {
                $this->assertSame(
                    in_array($permission, $allowedPermissions, true),
                    $user->can($permission),
                    "Unexpected [{$permission}] permission result for [{$role}] role.",
                );
            }
        }
    }

    public function test_system_gates_delegate_to_explicit_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->assertTrue(Gate::forUser($manager)->allows('system.viewFailedJobs'));
        $this->assertTrue(Gate::forUser($manager)->allows('system.viewAuditLogs'));
        $this->assertFalse(Gate::forUser($staff)->allows('system.viewFailedJobs'));
        $this->assertFalse(Gate::forUser($staff)->allows('system.viewAuditLogs'));
    }
}
