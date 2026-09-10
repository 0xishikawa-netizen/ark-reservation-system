<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MFA（Phase 5.5 / Phase 9.6 で Passkey 撤去・TOTP 一本化）
|--------------------------------------------------------------------------
| 主手段は 6 桁 TOTP。SMS は単独では MFA 要件を満たさない（SIM スワップ耐性が無いため補助手段）。
| 値はすべて設定で変更できるようにする（ハードコードしない）。
*/

return [

    'sms' => [
        // local/testing は 'log'（コード本体はログに出さない）。実 provider は未契約（OPEN_QUESTIONS）。
        'driver' => env('MFA_SMS_DRIVER', 'log'),

        'otp' => [
            'length' => (int) env('MFA_OTP_LENGTH', 6),
            'ttl_seconds' => (int) env('MFA_OTP_TTL_SECONDS', 300),
            // このチャレンジで許す verify 失敗回数。超えたら失効させる。
            'max_attempts' => (int) env('MFA_OTP_MAX_ATTEMPTS', 5),
        ],

        'resend' => [
            'min_interval_seconds' => (int) env('MFA_OTP_RESEND_INTERVAL', 60),
            'max_per_hour' => (int) env('MFA_OTP_RESEND_MAX_PER_HOUR', 5),
        ],

        'rate_limit' => [
            'verify_per_minute' => (int) env('MFA_OTP_VERIFY_PER_MINUTE', 5),
            'send_per_ip_per_hour' => (int) env('MFA_OTP_SEND_PER_IP_PER_HOUR', 10),
        ],

        // 期限切れチャレンジの保持日数（model:prune）
        'retention_days' => (int) env('MFA_SMS_RETENTION_DAYS', 7),
    ],

];
