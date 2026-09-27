<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Task 11-27: 来店・会計（Task 11-19）の権限を、seeder を再実行していない既存環境にも用意する。
 *
 * - checkouts.manage / checkouts.void を作成する（既にあれば何もしない）。
 * - admin ロールには、存在する全権限のうち未付与のものだけを追加する（RolePermissionSeeder と同じ方針）。
 * - 予約を管理できるロール（reservations.manage を持つ manager 等）へ checkouts.manage を追加する。
 *   予約を来店完了にできる人が会計を確定できないと「会計なし完了」しか選べないため。
 * 付与の追加だけを行い、既存の付与は外さない（冪等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['checkouts.manage', 'checkouts.void'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $all = Permission::query()->where('guard_name', 'web')->get();
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin !== null) {
            $missing = $all->reject(fn (Permission $permission): bool => $admin->hasPermissionTo($permission));
            if ($missing->isNotEmpty()) {
                $admin->givePermissionTo($missing);
            }
        }
        $manage = $all->firstWhere('name', 'checkouts.manage');
        $reservationManagers = Role::query()->where('guard_name', 'web')->where('name', '!=', 'admin')
            ->whereHas('permissions', fn ($query) => $query->where('name', 'reservations.manage'))->get();
        foreach ($reservationManagers as $role) {
            if (! $role->hasPermissionTo($manage)) {
                $role->givePermissionTo($manage);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // 付与の追加のみのため、戻す操作は行わない（手動付与との区別ができないため）。
    }
};
