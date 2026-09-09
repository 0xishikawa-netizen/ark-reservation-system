<?php

declare(strict_types=1);
use App\Domain\Integration\Provider\MockReservationProvider;
use App\Domain\Integration\Provider\PeakManagerReservationProvider;
use App\Domain\Integration\Provider\SalonBoardReservationProvider;

/*
|--------------------------------------------------------------------------
| 外部予約連携（Phase 9 Integration Foundation）
|--------------------------------------------------------------------------
| - active_provider : 実動する Provider。unknown は fail-closed（silent fallback しない）。
| - Phase 9 実動は mock のみ。peak_manager / salon_board は safe skeleton（未設定で安全停止）。
| - Provider の実 API 仕様（URL / endpoint / auth / rate limit）は Phase 10 で確定。ここでは推測しない。
*/

return [

    // mock | peak_manager | salon_board | null（無効）
    // 既定は null（未設定デプロイでは連携を動かさない）。ローカル / デモは .env で mock を指定する。
    'active_provider' => env('RESERVATION_INTEGRATION_PROVIDER', 'null'),

    'providers' => [
        'mock' => [
            'class' => MockReservationProvider::class,
        ],
        'peak_manager' => [
            'class' => PeakManagerReservationProvider::class,
            // Phase 10 で実仕様確定後に設定。ここに実値を書かない。
            'base_url' => env('PEAK_MANAGER_BASE_URL'),
            'api_key' => env('PEAK_MANAGER_API_KEY'),
        ],
        'salon_board' => [
            'class' => SalonBoardReservationProvider::class,
            'base_url' => env('SALON_BOARD_BASE_URL'),
            'api_key' => env('SALON_BOARD_API_KEY'),
        ],
    ],

    'inbound' => [
        'enabled' => (bool) env('RESERVATION_INTEGRATION_INBOUND', true),
        // 直近この分だけを毎回 fetch する（重複は mapping / fingerprint で吸収）。
        'window_minutes' => (int) env('RESERVATION_INTEGRATION_INBOUND_WINDOW_MIN', 4320), // 3 日
        'poll_cron' => env('RESERVATION_INTEGRATION_POLL_CRON', '*/15 * * * *'),
        'chunk' => 100,
    ],

    'outbox' => [
        'enabled' => (bool) env('RESERVATION_INTEGRATION_OUTBOUND', true),
        'max_attempts' => (int) env('RESERVATION_INTEGRATION_OUTBOX_MAX_ATTEMPTS', 6),
        // attempts 回目の待機秒（指数・上限あり）。
        'backoff_base_seconds' => (int) env('RESERVATION_INTEGRATION_OUTBOX_BACKOFF', 30),
        'backoff_cap_seconds' => 3600,
        'batch' => 25,
    ],

    'reconcile' => [
        'cron' => env('RESERVATION_INTEGRATION_RECONCILE_CRON', '0 5 * * *'),
        'window_days' => (int) env('RESERVATION_INTEGRATION_RECONCILE_WINDOW_DAYS', 14),
    ],
];
