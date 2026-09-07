<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Stripe（課金のみ / 設計プラン §9）
|--------------------------------------------------------------------------
| - laravel/cashier は課金契約の管理専用。ARK の「月何回使えるか」は Membership モジュールが持つ。
| - 初期は必ず Test Mode。APP_ENV=local で Live キー（sk_live_ / pk_live_）を検出したら
|   AppServiceProvider::boot() で例外を投げて起動を止める（実装は Phase 1）。
| - 自作 DB に保存するのは ID と要約のみ。カード情報・レスポンス全体は保存しない。
*/

return [

    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    'currency' => env('CASHIER_CURRENCY', 'jpy'),

    /*
    | capture_method
    | - automatic : 即時 capture。外部登録失敗時は「自動返金」で補償。
    | - manual    : authorize → 外部予約登録 → capture（設計プラン §7 の補償 Saga 第一候補）。
    |               authorize 成功時 payment_status=authorized、capture 成功後のみ paid。
    |               利用可否は OPEN_QUESTIONS #10b で確定。
    */
    'capture_method' => env('STRIPE_CAPTURE_METHOD', 'automatic'),

    /*
    | Idempotency-Key テンプレート（操作ごとに安定生成）。実装は Payment モジュール。
    */
    'idempotency_key_templates' => [
        'payment_intent_create' => 'pi-create:{reservation_id}:{attempt}',
        'payment_intent_capture' => 'pi-capture:{payment_id}',
        'payment_intent_cancel' => 'pi-cancel:{payment_id}',
        'refund' => 'refund:{payment_id}:{reason_hash}',
    ],

    /*
    | 処理対象の webhook イベント（設計プラン §9）
    */
    'handled_events' => [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
        'invoice.paid',
        'invoice.payment_failed',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ],

    // Live キー検出時の起動ガード対象環境
    'block_live_keys_in' => ['local', 'testing'],
];
