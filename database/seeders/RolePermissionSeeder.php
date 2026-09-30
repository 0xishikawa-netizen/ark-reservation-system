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
        'reports.view',
        'reports.manage',
        'reports.export',
        'reports.reconcile',
        'historical_data.import',
        'sales.view',
        'checkouts.manage',
        'checkouts.void',
        // マスタ（メニュー・ブース・商品・スタッフ・回数券・月額プラン）の削除と復元。admin 専用。
        'masters.delete',
        // ロール別の権限セット自体を管理する権限。admin 専用（§権限管理画面）。
        'roles.manage',
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

        // admin と customer は常にこの内容で固定する（admin は全権限の superuser、
        // customer は管理画面権限を一切持たない）。管理画面からのカスタマイズ対象外。
        $roles['admin']->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        $roles['customer']->syncPermissions([]);

        // staff / manager は「ロール権限管理」画面から管理者が変更できるようにするため、
        // 既に権限を持っている（＝一度でも保存された）場合はここで上書きしない。
        // 新規作成直後（wasRecentlyCreated）だけ、従来どおりの初期値を与える。
        if ($roles['manager']->wasRecentlyCreated) {
            $roles['manager']->syncPermissions($permissions->only([
                'admin.access',
                'failed_jobs.view',
                'audit_logs.view',
                'customers.view',
                'reservations.view',
                'reservations.manage',
                'checkouts.manage',
                'refund.execute',
                'ticket.grant',
                'membership.manage',
                'settings.manage',
                'integrations.view',
            ])->values());
        }

        if ($roles['staff']->wasRecentlyCreated) {
            $roles['staff']->syncPermissions([
                $permissions['admin.access'],
                $permissions['customers.view'],
                $permissions['reservations.view'],
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
