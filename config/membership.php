<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Membership（Phase 6 — 利用権 / 継続課金）
|--------------------------------------------------------------------------
| - 業務状態の SoR は memberships.status（Stripe subscription status を業務判断に直接使わない）。
| - 値はすべて env で上書きできるようにする（ハードコードしない）。
| - settings テーブルの `membership.no_show_policy` が優先。無ければここの既定へフォールバック。
*/

return [

    // no_show 時に当期の RESERVE をどう解消するか。RESERVE 時に snapshot され、後からの変更は既存予約に遡及しない。
    // consume : RELEASE +1 → CONSUME -1（1 回消化）
    // restore : RELEASE +1 のみ（回数を返す）
    'no_show_policy' => env('MEMBERSHIP_NO_SHOW_POLICY', 'consume'),

    // invoice 支払い失敗時の猶予（grace）。初回失敗で即 paused にしない。
    'grace' => [
        // grace を維持する上限日数。Stripe の retry がこれを超えても grace のまま持ち越さない。
        'max_days' => (int) env('MEMBERSHIP_GRACE_MAX_DAYS', 14),
        // grace_until の決め方:
        // stripe  : Stripe の next_payment_attempt / current_period_end を優先し、無ければ max_days。
        // fixed   : 常に now + max_days。
        'source' => env('MEMBERSHIP_GRACE_SOURCE', 'stripe'),
    ],

    // 予約可能な membership の状態（MembershipStatus::isBookable と一致させる。参照用）。
    'bookable_statuses' => ['active', 'grace', 'canceling'],
];
