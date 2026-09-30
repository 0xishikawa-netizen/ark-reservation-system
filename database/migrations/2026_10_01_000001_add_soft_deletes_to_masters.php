<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * マスタの削除（論理削除）と復元。
 *
 * - 予約・会計・施術などで使われていないマスタだけを削除できる（使用中は削除不可・無効化で運用）。
 * - 削除は deleted_at を入れて一覧・選択肢から外し、管理者が「削除済み」から復元できる。
 * - 削除・復元は admin 専用の masters.delete 権限で行う。既存環境にも付与する（追加のみ・冪等）。
 */
return new class extends Migration
{
    private const TABLES = ['services', 'booths', 'products', 'staff', 'ticket_products', 'membership_plans'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->softDeletes();
                });
            }
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'masters.delete', 'guard_name' => 'web']);
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin !== null && ! $admin->hasPermissionTo($permission)) {
            $admin->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropSoftDeletes();
                });
            }
        }
    }
};
