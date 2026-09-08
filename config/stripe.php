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
    'capture_method' => env('STRIPE_CAPTURE_METHOD', 'manual'),

    /*
    | Stripe API HTTP 設定
    */
    'http' => [
        'timeout' => (int) env('STRIPE_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('STRIPE_HTTP_CONNECT_TIMEOUT', 10),
        'max_network_retries' => (int) env('STRIPE_MAX_NETWORK_RETRIES', 2),
    ],

    /*
    | Idempotency-Key テンプレート（操作ごとに安定生成）。実装は Payment モジュール。
    | retry 回数や理由を含めると同一の論理操作で key が変化・衝突し、二重課金や
    | 返金漏れを招くため、永続化した operation ID だけから導出する。
    */
    'idempotency_key_templates' => [
        'payment_intent_create' => 'pi-create:{payment_operation_id}',
        'payment_intent_capture' => 'pi-capture:{payment_operation_id}',
        'payment_intent_cancel' => 'pi-cancel:{payment_operation_id}',
        'refund' => 'refund:{refund_operation_id}',
        // Phase 6 Membership。根は memberships.membership_operation_id（DB 永続値）。
        // create / cancel_now は「1 度きり」の操作なので固定キー（retry / reload / job 再実行で
        // 新しい subscription を作らない）。cancel / resume は toggle 操作で
        // cancel→resume→cancel のように同じ状態へ戻り得るため {bucket}（UTC の時）を足す。
        // これで Stripe の 24h 冪等キャッシュに古い応答が張り付くのを防ぎつつ、
        // 同一時内の二重送信（ダブルクリック等）は従来どおり 1 回に収束する。
        'subscription_create' => 'sub-create:{membership_operation_id}',
        'subscription_cancel' => 'sub-cancel:{membership_operation_id}:{bucket}',
        'subscription_resume' => 'sub-resume:{membership_operation_id}:{bucket}',
        'subscription_cancel_now' => 'sub-cancel-now:{membership_operation_id}',
    ],

    /*
    | 処理対象の webhook イベント（設計プラン §9）
    | PaymentIntent / Refund 系は Phase 5 で処理する。
    | invoice.* / customer.subscription.* は Phase 6 対象のため Phase 5 では処理しない。
    */
    'handled_events' => [
        'payment_intent.succeeded',
        'payment_intent.amount_capturable_updated',
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
