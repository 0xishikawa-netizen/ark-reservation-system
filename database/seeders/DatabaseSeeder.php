<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(SettingsSeeder::class);
        $this->call(Phase11MasterSeeder::class);

        // 開発用の固定管理者（Phase 9 / item 10）。production では呼ばず、
        // DevelopmentAdminSeeder 自身も同じ環境判定で no-op になる（多重ガード）。
        if ($this->container->environment(['local', 'development', 'testing'])) {
            $this->call(DevelopmentAdminSeeder::class);
        }

        // デモデータは local 環境で php artisan db:seed --class=DemoMasterSeeder を実行する。
    }
}
