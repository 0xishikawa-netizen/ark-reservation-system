<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| お試し環境（Staging）専用の設定（Task 11-36）
|--------------------------------------------------------------------------
| APP_ENV=staging のときだけ効く。production / local の挙動は変えない。
*/

return [

    // Basic 認証。両方を設定したときだけ有効（未設定なら素通し）。
    'basic_auth' => [
        'user' => env('STAGING_BASIC_AUTH_USER'),
        'password' => env('STAGING_BASIC_AUTH_PASSWORD'),
    ],

    // 開発用メニュー /dev-menu を出すか。local / staging でのみ参照する。
    'dev_menu' => (bool) env('STAGING_DEV_MENU', false),

    // staging のデモ Seeder が使う共通パスワード（12 文字以上必須）。
    'demo_password' => env('STAGING_DEMO_PASSWORD'),

    // パスワードの最小文字数。
    'demo_password_min_length' => 12,
];
