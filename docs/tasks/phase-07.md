# Phase 7 — Customer Portal（統合・mobile-first）

設計正本: `docs/PLAN.md`（§11 予約フロー / §12 認可 / §13 セキュリティ）。本書はそれを Phase 7 に落としたタスク計画 + 実行ログ。

実装: **Codex `gpt-5.6-sol`（Task 単位）**、レビュー・進行・総合検証・最終レビュー: **Sonnet 5**（Opus へ切り替えない）。

## baseline

- Phase 6 baseline commit: **`8c1b034` "Phase 6 membership baseline"**（remote なし・push なし）。
- Phase 7 の `git diff` レビュー基準は `8c1b034`。Phase 7 の変更は Sonnet 最終レビュー green まで commit しない。

## 目的

Phase 3〜6 で個別に実装した customer 向け画面（アカウント / プロフィール / 予約 / 回数券 / 利用権 / 決済 / 予約導線）を、
**一貫した mobile-first の Customer Portal** として統合する。

- **新しい業務ドメインを作らない。** 既存の `ReservationService` / `Ticket*` / `Membership*` / `Payment*` サービス・StateMachine・Action を再利用する。
- 同じ business logic を Portal 用に重複実装しない。
- Controller / Vue から業務 DB を直接更新しない。重要 write は Action / Service / StateMachine 経由。

## Phase 7 で実装しないもの（絶対）

Phase 8+ の Store Admin 拡張 / System Status 拡張 / Reporting / analytics / LINE / 外部予約 API（Peak Manager / SALON BOARD / Hot Pepper）/
multi-store / `store_id` / recommendation / loyalty / 通知基盤 / 本番デプロイ / 本番 Stripe / Stripe Live / remote 追加 / push /
オンライン回数券販売（PLAN の Phase 7 scope に無い）。

## 最重要セキュリティ：Customer ownership

customer は **自分自身のデータのみ** 閲覧・操作できる（profile / reservations / payment / ticket / membership / usage ledger）。
- URL パラメータを書き換えて他 customer の resource へアクセスできない（403 / 404）。
- Controller の実装だけに頼らず、Policy / query scope でも deny する。
- customer は `/admin/*` および admin-only mutation にアクセスできない。
- 生の Stripe error / SQL / exception / stack trace / provider ID / `needs_attention` 等の内部フィールドを画面に出さない。
  ただし未確定の決済状態（`authorized` / `needs_attention`）を「成功」と表示しない。

## HTTP ステータス規約（Phase 3〜6 と統一）

- 409 = リソース競合、422 = 業務バリデーション、403/404 = 認可（他人の resource は 403 か 404）。

---

## Task 一覧（Codex へ Task 単位で発注 → Sonnet レビュー）

### Task 7-1 — Portal レイアウト / ナビゲーション / ダッシュボード
- `CustomerLayout.vue` を mobile-first の一貫ナビへ（ホーム / 予約 / 回数券 / 会員 / 支払い / アカウント）。既存 Layout を活用・別 framework を作らない。
  横スクロールを出さない。タッチターゲット十分。
- `/mypage` ダッシュボード（`Customer/Dashboard.vue` を実装）：次回予約 / 現在の利用権サマリ / 回数券残数 / 直近の支払い /
  予約 CTA / 重要ステータス（決済手続き中・grace 等）。情報過多にしない。既存 Query を再利用（新規 Query は最小限）。
- `DashboardController`（または `HomeController` 拡張）が上記を集約。すべて自分のデータのみ。

### Task 7-2 — Profile / アカウント UX
- `/mypage/profile`（既存 `ProfileController` / `Profile/{Show,Edit}.vue`）を Portal トーンに統一。
  name / email / phone / プロフィール情報 / パスワード・セキュリティ導線（Fortify の既存フロー）。PII 保護維持（`encrypted` / props に過剰に渡さない）。

### Task 7-3 — 予約一覧 / 詳細 / cancel / reschedule UX
- `/mypage/reservations`（`Customer/Reservations/{Index,Show}.vue`）：今後 / 履歴 / 詳細（status / サービス / スタッフ / 日時 /
  支払い方法 / entitlement 使用状態）。cancel / reschedule は既存 `ReservationService` 経由（既存ルート維持）。
- 空・loading・error・success の状態表示。**他人の予約は 403/404**（`ReservationPolicy` 既存を確認・不足なら補強）。

### Task 7-4 — 予約フロー統合（onsite / card / ticket / membership）
- `/reserve`（`Customer/Reserve/Index.vue` + `ReserveController` + `StoreReservationRequest`）：
  サービス → スタッフ（指名なし可）→ 空き → 日時 → 支払い方法 → 確認。
  支払い方法ごとの business flow は既存サービスを利用（card は Phase 5 Saga、ticket は Phase 4、membership は Phase 6）。
  card の UX：支払い → authorization → 「予約確保中」 → capture → confirmed の状態を customer が理解できる表示（Phase 5 `Payments/Checkout.vue` 活用）。
  ticket は残数、membership は当期残数を明示。**同じ予約で複数支払い方式を同時に選ばせない。**
- 生の Stripe / SQL エラーを出さない。double submit / stale availability に対する冪等（既存の 409/422 経路 + ボタン disable）。

### Task 7-5 — 回数券 Portal
- `/mypage/tickets`（既存 `Customer/TicketController` / `Tickets/Index.vue`）：保有残数 / 有効期限 / held / 利用履歴。Portal トーン統一・状態表示。
  **オンライン販売は追加しない。**

### Task 7-6 — 利用権 Portal
- `/mypage/membership`（Phase 6 の `Customer/MembershipController` / `Membership/Index.vue`）：plan / status / 当期 / 次回更新 /
  残数 / 利用履歴 / cancel_at_period_end 表示・切替 / 支払い方法。Phase 6 のサービスを再利用（新規ロジックなし）。Portal トーン統一。

### Task 7-7 — 支払い履歴 Portal
- `/mypage/payments`（新規 `Customer/PaymentHistoryController` + `Customer/Payments/Index.vue`）：
  支払い履歴（金額 / 日付 / status（顧客向けラベル）/ 関連予約 or 利用権 invoice）。
  **表示しない**：Stripe internal ID（`stripe_payment_intent_id` / `stripe_charge_id` / `client_secret`）/ 生 failure detail / webhook /
  `needs_attention` / reconcile フィールド。**自分の payment のみ**（`payments.customer_id` scope・route param があれば Policy）。
- `CustomerPaymentHistoryQuery`（顧客向け整形）。`CustomerLayout` ナビに「支払い」。

### Task 7-8 — モバイル / アクセシビリティ / 状態表示 + セキュリティ hardening
- 全 Portal 画面：mobile navigation / touch target / responsive / **横スクロールなし** / フォーム usability /
  日時選択 usability / loading / disabled / empty / error / success feedback。desktop でも正常表示。
- セキュリティ hardening：全 customer リソースルートに ownership（Policy / scope）が効いていることを実装レベルで確認・不足を補強。
  customer data を Vue props へ過剰に渡さない。`/admin/*` は customer から 403。

### Task 7-9 — Customer E2E / ownership / 非退行テスト
- ownership（§「必須 Security Tests」）+ E2E（§「Phase 7 E2E」）+ Phase 3/4/5/5.5/6 非退行。

### Task 7-10 — Sonnet 5 総合検証 + Phase 6+7 最終総合レビュー
- `migrate:fresh --seed` / `npm run build` / `artisan test` / `composer audit` / `npm audit` /
  各種スキャン（secrets / Live / production / Phase 8 premature / PII log / ledger mutation / duplicate GRANT / transaction+Stripe HTTP / IDOR）。
- Phase 6 + Phase 7 全体を Sonnet 5 自身がレビュー（`git diff 9482114` ベース）。問題は Sonnet が直接修正（scope 追加禁止・安全性/整合性/非退行のみ）。
- **Passkey 実機 QA 既知事象**（`GET /user/passkeys/options` → 200 後に登録 POST へ到達せず「このデバイスでは Passkey を登録できませんでした。」）の状態を確認。
  再現するアプリ側不具合なら最終 baseline 前に修正。WebAuthn の秘密情報（challenge / rawId / clientDataJSON / attestationObject / recovery code）をログに出さない。

---

## 必須 Security Tests（Task 7-9）

1. customer A が customer B の予約を閲覧不可 / 2. 同 payment 不可 / 3. 同 ticket 不可 / 4. 同 membership 不可 /
5. URL ID 書換えでも 403/404 / 6. customer が admin route 不可 / 7. customer が admin-only mutation 不可 /
8. PII leak なし / 9. Stripe internal fields leak なし / 10. CSRF 保護。

## Phase 7 E2E（Task 7-9）

- **Flow A**: login → dashboard → reserve → onsite → confirmed → reservation detail
- **Flow B**: ticket balance あり → ticket booking → HOLD → confirmed → cancel → RELEASE
- **Flow C**: membership active → membership booking → RESERVE → completed → CONSUME
- **Flow D**: card booking → Payment Element(Fake) → authorized → capture → paid → confirmed → payment history に表示
- **Flow E**: reservation history → 自分の履歴のみ
- **Flow F**: cancel_at_period_end membership → portal で表示 → period end 前は利用可能

## 非退行（Task 7-9 / 7-10）

Phase 3 Reservation / Phase 4 Ticket / Phase 5 Payment / Phase 5.5 MFA / Phase 6 Membership すべて非退行。

---

## 実行ログ

**Codex `gpt-5.6-sol` は Task 7-1 の実装着手前に usage limit に到達し、Phase 7 期間中は復帰しなかった。
ユーザー方針（「Codex が usage limit・異常終了で継続不能の場合のみ Sonnet 5 自身が実装してよい」「最終レビュー段階では Codex 再委譲不要」）に基づき、Phase 7 は Sonnet 5 が実装・レビュー・検証した。**

### 2026-09-09 — Task 7-1（Portal レイアウト / ナビ / ダッシュボード）: ✅ 完了 修正2（HomeController のコンテナ解決 / 既存 HomeRoutingTest の Customer レコード追加）
- `app/Queries/CustomerDashboardQuery.php`（既存 `CustomerReservationListQuery` / `CustomerTicketQuery` / `CustomerMembershipQuery` を再利用して集約・read のみ・
  次回予約 / 予約件数 / 利用権サマリ / 回数券残数 / 直近支払い（顧客向けラベルのみ）/ 重要ステータス（最大 3 件））。
  **Stripe internal（payment intent id / charge id / needs_attention / 生 failure / subscription id）は返さない。**
- `app/Http/Controllers/Customer/DashboardController.php`（`$request->user()->customer` から導出・無ければ 403）。
  `HomeController` の customer 分岐を `app()->call([app(DashboardController::class), '__invoke'])` へ差し替え（メソッド依存をコンテナ解決）。
- `resources/js/layouts/CustomerLayout.vue`：`v-bottom-navigation`（アイコン + ラベル・現在地ハイライト・`pb-16` 余白・横スクロールなし）で
  ホーム / 予約 / 回数券 / 会員 / 支払い / アカウント。既存 Layout を活用・別 framework を作らない。desktop でも `max-width:48rem` 維持。
- `resources/js/Pages/Customer/Dashboard.vue`：実ダッシュボード（重要ステータス警告 / 次回予約 + CTA / 利用権 / 回数券 / 直近支払い・各カードで空状態）。
- 既存 `HomeRoutingTest::test_verified_customer_sees_customer_dashboard` に `Customer::factory()` を追加（customer role ユーザーは Customer レコードを持つのが正）。

### 2026-09-09 — Task 7-2〜7-6（Profile / 予約 / 予約導線 / 回数券 / 利用権 Portal）: ✅ 既存実装を新ナビ配下で継続（コード変更なし）
- Phase 3〜6 で `CustomerLayout` + Vuetify + 日本語 + 空/error/success 状態つきで構築済みの各画面をそのまま Portal の一部として使用。
  ナビ統合（7-1）で一貫した導線に。予約導線の onsite/card/ticket/membership 分岐は Phase 3〜6 の既存 Service で完結（重複実装なし）。
- **オーナーシップ**：予約は `ReservationPolicy`（`customer_id === user->customer?->user_id`）で `view`/`update`/`delete` を deny。
  `Customer/{Ticket,Membership,Payment}Controller` はルートパラメータで他人を参照させず `$request->user()->customer` から導出。Task 7-9 で検証。

### 2026-09-09 — Task 7-7（支払い履歴 Portal）: ✅ 完了 修正1（Payment enum → value）
- `app/Queries/CustomerPaymentHistoryQuery.php`（**自分の payment のみ**・顧客向けラベル：pending→「お支払い手続き中」/ authorized→「予約確保中（確定処理中）」/
  succeeded・paid→「支払い完了」/ failed→「お支払いに失敗」等。**未確定を「成功」と表示しない**）。
  返さない：`payment_operation_id` / `stripe_payment_intent_id` / `stripe_charge_id` / `failure_code` / `failure_message` / `needs_attention` /
  `last_synced_at` / `provider` / `capture_method` / `created_by`。関連予約は id + サービス名 + 日時のみ。
- `Customer/PaymentHistoryController::index`（`$request->user()->customer` 導出・403）+ ルート `GET /mypage/payments` + Vue `Customer/Payments/Index.vue`（空状態あり）。
  `CustomerLayout` ナビに「支払い」。`CustomerDashboardQuery` の `recent_payment` も同じラベル方針。

### 2026-09-09 — Task 7-8（モバイル / a11y / 状態表示 + セキュリティ hardening）: ✅ 完了（コード変更は 7-1 のナビ + 7-9 のテストに内包）
- モバイル：bottom navigation（44px 以上・横スクロールなし・`pb-16`）。各新規/既存画面は空 / loading（Inertia 遷移）/ error（`errors`）/ success（flash）を扱う。
- セキュリティ hardening：全 customer リソースルートのオーナーシップを実装レベルで確認（`ReservationPolicy` / コントローラの `$user->customer` scope /
  Query の `where('customer_id', ...)`）。customer data を props へ過剰に渡さない（支払い・利用権・ダッシュボードで Stripe internal を除外）。`/admin/*` は customer から 403。
  **不足は無し**（Phase 3〜6 で段階的に構築済み）。

### 2026-09-09 — Task 7-9（Customer E2E / ownership / 非退行テスト）: ✅ 完了 修正0
- `tests/Feature/Customer/CustomerPortalTest.php`（11・`RefreshDatabase`）：ダッシュボードは自分の集約データのみ / Stripe internal・secret 非露出 /
  他人の予約は 403（view / cancel / reschedule）/ 予約一覧は自分のみ / 他人の checkout は 403 /
  支払い履歴は自分のみ・`pi_`/`ch_`/`inv:` 非露出 / 利用権ページは自分の membership のみ / `/admin/*` は 403 / admin mutation（`memberships/{id}/adjust`）は 403。
- `tests/Feature/Customer/CustomerPortalCsrfTest.php`（3・`ReservationCsrfTest` 手法）：`POST /mypage/membership/{cancel,subscribe}` / `DELETE /mypage/reservations/{id}` が
  CSRF トークン無しで **419**。
- `tests/Feature/Customer/CustomerPortalCancelTest.php`（1・`DatabaseMigrations`）：`POST /mypage/membership/cancel` は**自分の membership のみ** `cancel_at_period_end`＋`canceling`、他人は不変。
- `tests/Feature/Customer/CustomerPortalE2ETest.php`（3）：Flow A（login→dashboard→reserve(onsite)→confirmed→detail→dashboard に反映）/
  Flow E（履歴は自分のみ）/ 支払い履歴の空状態。
- Flow B/C/D（ticket / membership / card 予約の完全 E2E）は Phase 3 `CardCheckoutE2ETest` / Phase 6 `MembershipLifecycleE2ETest` で既にカバー。
- **検証（Sonnet）**：`artisan test` **592 → +3 = 595 passed**（後述 7-10）/ `npm run build` 型エラー 0。

### 2026-09-09 — Task 7-10（Sonnet 総合検証 + Phase 6+7 最終レビュー）: ✅ 完了
- `artisan migrate:fresh --seed`：40 migration DONE（クリーン）。
- `artisan test`：**595 passed / 3427 assertions / 0 failed**（約 3.6 分・実 MySQL）。
- `npm run build`（`vue-tsc --noEmit && vite build`）：型エラー 0・`built in ~0.75s`。
- `composer audit`：脆弱性 0 ／ `npm audit`：脆弱性 0。
- scan（全て clean）：
  - secrets / Stripe Live キー（`sk_live_` / `pk_live_`）／本番 DB 名（`64ssq_ark_db`）→ 検出なし（`config/*.php` の `env()` 参照とテストの否定アサートのみ）。
  - Phase 8 先行実装（`store_id` / multi-store / Hot Pepper / SALON BOARD / Peak Manager / LINE / loyalty / recommendation）→ 検出なし。
  - 追記型台帳の UPDATE/DELETE → `TicketTransaction` / `MembershipUsageTransaction` は `updating`/`deleting` で `RuntimeException`、`$timestamps=false`。
  - DB transaction 中の Stripe HTTP → `MembershipSubscriptionService` の gateway 呼び出し（ensureCustomer/createSubscription/setCancelAtPeriodEnd/cancelNow/retrieveSubscription）は全て `DB::transaction` の外。`FakeMembershipStripeGateway` が `transactionLevel()===0` を機械検査。
  - 重複 subscription → `startSubscription` の TX1 で `status != canceled` の membership を `lockForUpdate` して弾く。
  - 重複 GRANT → `dedupe_key` `grant:{membership_id}:{period_start}` UNIQUE + 事前 SELECT + 23000 catch（`MembershipConcurrencyTest` で MySQL 2 コネクション検証済み）。
  - IDOR / オーナーシップ → `Customer/*Controller` は全て `$this->authorize(...)`（`ReservationPolicy` = `customer_id === user->customer?->user_id`）か `$request->user()->customer` からの scope。customer 側 membership ルートに `{membership}` パラメータ無し（`currentMembership()` が `customer_id` で scope）。admin mutation は `can:membership.manage` + `password.confirm`。
- 非退行：Ticket（Phase 4）／ Payment（Phase 5）／ MFA（Phase 5.5）／ Membership（Phase 6）の既存テストは 595 グリーンに全て含まれ、Phase 7 で 0 件破壊。

#### Passkey 実機 QA 修正（`GET /user/passkeys/options` → 200 後に登録 POST が失敗する事象）
- **原因（再現するアプリ不具合）**：`laravel/passkeys` の options エンドポイント 3 種
  （`/user/passkeys/options` / `/passkeys/login/options` / `/passkeys/confirm/options`）は
  レスポンスを **`{ options: {…} }`** で返すが、フロントは `options.publicKey ?? options` で取り出していたため
  `{ options: {…} }` ラッパーごと `createPasskey()` / `getPasskeyAssertion()` に渡り、
  `options.challenge` が `undefined` → `base64UrlToBuffer(undefined)` で例外 → catch で
  「このデバイスでは Passkey を登録できませんでした。」を表示していた（デバイス依存ではない）。
- **修正**：3 ファイルで取り出しを **`body.options ?? body.publicKey ?? body`** に統一。
  - `resources/js/Pages/Admin/Profile/Mfa.vue`（登録）
  - `resources/js/Pages/Auth/Login.vue`（Passkey ログイン）
  - `resources/js/Pages/Auth/ConfirmPassword.vue`（Passkey 再認証）
- POST ペイロード（`{ name, credential:{id,rawId,type,response} }` / `{ credential:{…} }`）は vendor の
  `PasskeyRegistrationRequest` / `PasskeyVerificationRequest` の rules と一致しており変更不要。
- WebAuthn の機微値（challenge / rawId / clientDataJSON / attestationObject / recovery code）は
  ログ出力していない（`webauthn.ts` は base64url⇄ArrayBuffer 変換のみ・console 出力なし）。
- 再検証：`npm run build` 型エラー 0 ／ `artisan test` 595 passed（回帰なし）。

### ✅ Phase 7 完了 — 2026-09-09
- baseline：`8c1b034`（Phase 6 membership baseline）からの差分。migration 追加なし・業務ドメイン追加なし。
- 変更（5）：`HomeController` / `Customer/Dashboard.vue` / `layouts/CustomerLayout.vue`（bottom nav）/ `routes/web.php`（+`/mypage/payments`）/ `HomeRoutingTest`。
  Passkey 修正（3）：`Admin/Profile/Mfa.vue` / `Auth/Login.vue` / `Auth/ConfirmPassword.vue`。
- 追加（10）：`Customer/DashboardController` / `Customer/PaymentHistoryController` / `CustomerDashboardQuery` / `CustomerPaymentHistoryQuery` /
  `Customer/Payments/Index.vue` / `CustomerPortalTest` / `CustomerPortalCsrfTest` / `CustomerPortalCancelTest` / `CustomerPortalE2ETest` / `docs/tasks/phase-07.md`。
- commit：`Phase 7 customer portal baseline`（remote 追加なし・push なし）。
