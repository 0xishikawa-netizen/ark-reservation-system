<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'admin.access',
        'failed_jobs.view',
        'audit_logs.view',
        'staff.manage',
        'services.manage',
        'booths.manage',
        'shifts.manage',
        'customers.view',
        'customers.manage',
        'reservations.view',
        'reservations.manage',
        'settings.manage',
        'refund.execute',
        'ticket.grant',
        'membership.manage',
        'ticket_policy.manage',
        'ticket_products.manage',
        'integrations.view',
        'integrations.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (string $name): array {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            return [$name => $permission];
        });

        $roles = collect(['customer', 'staff', 'manager', 'admin'])->mapWithKeys(function (string $name): array {
            $role = Role::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            return [$name => $role];
        });

        $roles['admin']->syncPermissions($permissions->values());
        $roles['manager']->syncPermissions($permissions->only([
            'admin.access',
            'failed_jobs.view',
            'audit_logs.view',
            'customers.view',
            'reservations.view',
            'reservations.manage',
            'refund.execute',
            'ticket.grant',
            'membership.manage',
            'settings.manage',
            'integrations.view',
        ])->values());
        $roles['staff']->syncPermissions([
            $permissions['admin.access'],
            $permissions['customers.view'],
            $permissions['reservations.view'],
        ]);
        $roles['customer']->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
