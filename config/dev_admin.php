<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 開発用固定管理者アカウント（Phase 9 / item 10）
|--------------------------------------------------------------------------
| DB を作り直しても Seeder から必ず復元される「消えない開発管理者」。
| - local / development / testing でのみ有効。production では
|   DevelopmentAdminSeeder 自身が no-op になる（多重ガード）。
| - 認証情報はこのファイル（＝開発補助専用）にのみ既定値を持つ。
|   production の DatabaseSeeder 経路からは到達しない。
*/

return [

    // このリストに含まれる環境でのみ開発管理者を作成する。
    'environments' => ['local', 'development', 'testing'],

    'name' => env('DEV_ADMIN_NAME', 'ARK 開発管理者'),
    'email' => env('DEV_ADMIN_EMAIL', 'dev-admin@ark.test'),
    'password' => env('DEV_ADMIN_PASSWORD', 'password'),
];
