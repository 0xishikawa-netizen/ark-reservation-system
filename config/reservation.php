<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 予約 / 外部連携 設定
|--------------------------------------------------------------------------
| 承認済み設計プラン §3 / §8 に対応。
| - authority : 予約の System of Record。local の間は自作 DB が正本で全機能が単体動作する。
| - gateway   : 外部予約サービスとの「通信のみ」を担う実装。authority とは独立に設定できる
|               （authority=local でも gateway=peak_manager の片方向ミラーが可能）。
*/

return [

    // local | peak_manager | salon_board
    'authority' => env('RESERVATION_AUTHORITY', 'local'),

    // null | peak_manager | salon_board
    'gateway' => env('EXTERNAL_RESERVATION_GATEWAY', 'null'),

    'gateways' => [
        'null' => [
            'class' => 'App\\Modules\\ExternalIntegration\\Gateways\\Reservation\\NullExternalReservationGateway', // Phase 1 で実装
        ],
        'peak_manager' => [
            'class' => 'App\\Modules\\ExternalIntegration\\Gateways\\Reservation\\PeakManagerReservationGateway', // Phase 1 で実装
            'base_url' => env('PEAK_MANAGER_BASE_URL'),
            'api_key' => env('PEAK_MANAGER_API_KEY'),
        ],
        'salon_board' => [
            'class' => 'App\\Modules\\ExternalIntegration\\Gateways\\Reservation\\SalonBoardReservationGateway', // Phase 1 で実装
            'base_url' => env('SALON_BOARD_BASE_URL'),
            'api_key' => env('SALON_BOARD_API_KEY'),
        ],
    ],

    /*
    | 予約スロット
    | - slot_minutes : reservation_resource_slots の粒度。顧客予約の開始時刻はこの倍数に限定。
    | - allow_admin_free_time : true のとき管理者は任意時刻予約可。占有スロットは start=floor / end=ceil。
    */
    'slot_minutes' => (int) env('RESERVATION_SLOT_MINUTES', 15),
    'allow_admin_free_time' => (bool) env('RESERVATION_ALLOW_ADMIN_FREE_TIME', false),

    // 仮予約（pending_payment）の枠 HOLD 時間（分）
    'hold_minutes' => (int) env('RESERVATION_HOLD_MINUTES', 10),

    // 営業時間の既定（settings テーブル未投入時のフォールバック）
    'business_hours' => [
        'open' => '10:00',
        'close' => '22:00',
    ],

    // 予約開始までの残り時間に応じた返金率。上から順に最初に一致した段階を適用する。
    'cancellation' => [
        'tiers' => [
            ['min_hours_before' => 48, 'refund_percent' => 100],
            ['min_hours_before' => 24, 'refund_percent' => 50],
            ['min_hours_before' => 0, 'refund_percent' => 0],
        ],
        'no_show_refund_percent' => 0,
    ],

    // 顧客に「予約完了」を表示してよいタイミングの決定表（設計プラン §3）
    // local            : 自作 DB commit 時
    // peak_manager/... : Gateway.pushReservation() 成功後のみ
    'customer_confirmation_requires_external_success' => env('RESERVATION_AUTHORITY', 'local') !== 'local',
];
