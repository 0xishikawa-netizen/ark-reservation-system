<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| データ保持期間（技術データのみ prune 対象）
|--------------------------------------------------------------------------
| 承認済み設計プラン §6。業務データ（顧客・予約・回数券/利用権の台帳・決済/返金）は
| ここに載せない＝永続保持。ここに載るのは技術ログのみ。
| 具体的な日数は OPEN_QUESTIONS #3（会計士・店舗と確認）で確定するまでの暫定値。
*/

return [

    // model:prune が参照。単位＝日。
    'prune' => [

        // Stripe webhook 受信記録（payload は保持しない）
        'webhook_events' => [
            'success_days' => (int) env('RETENTION_WEBHOOK_EVENTS_DAYS', 90),
            // status=failed は解決フラグが立つまで prune しない
            'keep_failed' => true,
        ],

        // 外部予約同期ログ
        'sync_logs' => [
            'success_days' => (int) env('RETENTION_SYNC_LOGS_SUCCESS_DAYS', 30),
            'failed_days' => (int) env('RETENTION_SYNC_LOGS_FAILED_DAYS', 180),
        ],

        // 監査ログ：金銭・個人情報操作は長期、その他は短期
        'audit_logs' => [
            'low_value_days' => (int) env('RETENTION_AUDIT_LOGS_LOW_DAYS', 365),
            // 下記カテゴリは prune 対象外（長期保持）
            'keep_categories' => ['payment', 'refund', 'ticket_grant', 'ticket_revoke', 'membership', 'pii'],
        ],

        // 二重予約防止スロット：過去・無効予約分のみ削除（未来 + 有効予約は保持）
        'reservation_resource_slots' => [
            'past_days' => (int) env('RETENTION_RESERVATION_SLOTS_PAST_DAYS', 14),
        ],
    ],

    // Stripe webhook raw payload をDB外へ暗号化短期保管する場合の設定（既定 off）
    'webhook_payload_archive' => [
        'enabled' => (bool) env('STRIPE_ARCHIVE_WEBHOOK_PAYLOAD', false),
        'disk' => env('STRIPE_ARCHIVE_WEBHOOK_DISK', 'local'),
        'days' => (int) env('STRIPE_ARCHIVE_WEBHOOK_DAYS', 30),
    ],

    // DB 容量アラート（システム状態ページ / 日次通知）
    'db_size_alert_mb' => (int) env('DB_SIZE_ALERT_MB', 3584),
];
