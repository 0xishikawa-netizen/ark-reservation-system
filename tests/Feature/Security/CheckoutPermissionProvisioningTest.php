<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 11-27: seeder を再実行していない既存環境でも、migrate だけで来店・会計の権限が揃うこと。
 * 付与を追加するだけで、既存の付与は外さない。
 */
class CheckoutPermissionProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_checkout_permissions_to_admin_and_reservation_managers_only(): void
    {
        // 11-19 以前の状態を再現：会計権限が無く、admin・manager は既存の権限だけ持つ。
        Permission::query()->whereIn('name', ['checkouts.manage', 'checkouts.void'])->delete();
        $reservationsManage = Permission::query()->firstOrCreate(['name' => 'reservations.manage', 'guard_name' => 'web']);
        $reportsView = Permission::query()->firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $admin = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions([$reportsView]);
        $manager = Role::query()->firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->syncPermissions([$reservationsManage]);
        $staff = Role::query()->firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->syncPermissions([$reportsView]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_09_27_000007_provision_checkout_permissions.php');
        $migration->up();
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->refresh();
        $manager->refresh();
        $staff->refresh();
        $this->assertTrue($admin->hasPermissionTo('checkouts.manage'));
        $this->assertTrue($admin->hasPermissionTo('checkouts.void'));
        $this->assertTrue($admin->hasPermissionTo('reports.view'));
        $this->assertTrue($manager->hasPermissionTo('checkouts.manage'));
        $this->assertFalse($manager->hasPermissionTo('checkouts.void'));
        $this->assertTrue($manager->hasPermissionTo('reservations.manage'));
        // 予約を管理できないロールには付与しない。
        $this->assertFalse($staff->hasPermissionTo('checkouts.manage'));
        $this->assertSame(1, Permission::query()->where('name', 'checkouts.manage')->count());
    }
}
