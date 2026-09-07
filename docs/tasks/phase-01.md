# Phase 1 — 共通基盤

設計正本：`docs/PLAN.md`（rev.5）。本ファイルは Phase 1 のタスク分割と進行ログ。

## Phase 1 の範囲

ARK 予約・決済システムの**共通基盤のみ**。認証 / RBAC / MFA / `/admin` 認可 / idle timeout /
Customer・Admin レイアウト / セキュリティ基盤 / `audit_logs` / State Machine 基盤 /
`ExternalReservationGateway` + `NullExternalReservationGateway` + config 切替 / `failed_jobs` 最小可視化 /
テスト / CI。

## Phase 1 で実装しないもの（厳守）

reservations 業務 / `reservation_resource_slots` / 回数券 / Membership / Stripe 実決済 / Stripe Live /
Payment Element / Peak Manager 実 API / SALON BOARD 実 API / Reporting / 予約台帳 / WordPress 変更 /
本番 DB / `ark-system-proposal` 変更 / Phase 2 以降の先行実装。

## 共通ルール

- 実装は Codex（毎回 `-m gpt-5.6-sol` 明示、CLI 自動アップデート禁止、最初に `AGENTS.md` / `docs/PLAN.md` / `docs/tasks/phase-01.md` を通読）。
- Claude Code はオーケストレーター（タスク分割 / 指示 / Docker・Sail・composer・npm / `git diff` レビュー / build・test / セキュリティ・設計整合レビュー）。アプリコード修正は原則 Codex へ差し戻す。
- `git add` / `commit` / `push` 禁止。本番接続禁止。Stripe Live キー禁止。グローバルインストール禁止。
- **DB 更新を Controller / Vue から直接行わない。** 将来の業務更新は Action / Service 経由（Phase 1 でこの構造を崩さない）。
- 保護（変更しない）：`docs/PLAN.md`。他 docs は事実整合のための追記のみ可。

---

## タスク一覧（Codex 実行は括弧内の Run 単位でまとめる）

### Task 1-1 — 認証・User 基盤（Run 1）
- `laravel/fortify` 導入・`config/fortify.php`（features: registration, resetPasswords, emailVerification, updatePasswords, updateProfileInformation, twoFactorAuthentication[confirmPassword=true]）。views は Inertia 側で用意（Fortify の Blade view は使わない）。
- **web guard 1 つ**。追加 guard は作らない。
- migration：
  - `users`（Laravel 既定 + `MustVerifyEmail`）。**`type` カラムは持たない**。
  - Fortify 2FA カラム（`two_factor_secret` / `two_factor_recovery_codes` / `two_factor_confirmed_at`）。
  - `customers`（`user_id` PK/FK 1:1、`kana` varchar(100)、`phone` varchar(20) **encrypted cast**、`phone_hmac` char(64) index、`birthday` date null **encrypted cast**、`gender` varchar(10) null、`note` varchar(1000) null、`stripe_customer_id` varchar(40) null index、`created_via` varchar(20)）。
  - `staff`（`user_id` PK/FK 1:1、`display_name` varchar(50)、`color` varchar(7)、`is_bookable` bool、`sort_order` smallint）。
- Model：`User`（`HasFactory` `Notifiable` `MustVerifyEmail` `TwoFactorAuthenticatable` `HasRoles`）、`Customer`、`Staff`。`Customer::phone` の HMAC は保存時に `phone_hmac` へ自動セット（`APP_KEY` 由来のキー付き HMAC。平文検索コピーは持たない）。
- Fortify actions：`CreateNewUser`（**顧客セルフ登録のみ**：User 作成 → `Customer` 作成 → `customer` ロール付与 → email verification 送信）。`ResetUserPassword` / `UpdateUserPassword` / `PasswordValidationRules` は既定。
- **スタッフはセルフ登録不可**（`CreateNewUser` は customer 専用。staff 作成は Task 1-3）。
- Rate limit：`login` / `register`（or `two-factor`）/ `password.reset` に `RateLimiter::for(...)`（例：login 5/min per email+ip）。
- Inertia 認証ページ（プレーンな Vuetify フォーム。レイアウトは Task 1-4 で差し替え可）：`Auth/Login.vue` `Auth/Register.vue` `Auth/ForgotPassword.vue` `Auth/ResetPassword.vue` `Auth/VerifyEmail.vue` `Auth/ConfirmPassword.vue`。
- `HandleInertiaRequests::share` の `auth.user` を実ユーザー（id / name / email / `roles` / `email_verified` / `two_factor_enabled`）に。
- テスト（Feature）：顧客 register → verification メール送信 / verify → login / logout / password reset / login rate limit で 429。

### Task 1-2 — Spatie Role / Permission / Policy（Run 1）
- `spatie/laravel-permission` 導入・migration publish・`config/permission.php`。
- ロール：`customer` / `staff` / `manager` / `admin`。
- パーミッション（starter・最小）：`admin.access`、`failed_jobs.view`、`audit_logs.view`、`staff.manage`、`settings.manage`、`refund.execute`（定義のみ・Phase 5 で使用）、`ticket.grant`（定義のみ）、`membership.manage`（定義のみ）。
- 割当：`admin` = 全 permission。`manager` = `admin.access` `failed_jobs.view` `audit_logs.view` `refund.execute` `ticket.grant` `membership.manage` `settings.manage`。`staff` = `admin.access`。`customer` = なし。
- **`Gate::before` の全権バイパスは作らない**（deny-by-default）。`admin` の権限は seeder で明示付与。
- `DatabaseSeeder` から `RolePermissionSeeder` を呼ぶ。`php artisan db:seed` で冪等（`firstOrCreate`）。
- Policy 基盤：`app/Policies/` に雛形（例 `SystemPolicy`（`viewFailedJobs` / `viewAuditLogs`））+ `AuthServiceProvider` 登録。各能力は `->can('permission')` に委譲。
- テスト：ロール別 permission マトリクス（admin/manager/staff/customer それぞれの `can()` 結果）。

### Task 1-3 — Staff/Admin MFA + `/admin` 認可 + idle timeout（Run 2）
- `/admin` route group：middleware `['auth','verified', AdminAccess::class, AdminIdleTimeout::class, EnsureStaffTwoFactor::class]`。
- `AdminAccess`：`auth()->user()?->can('admin.access')` 以外は **403**（deny-by-default）。role なし customer は 403。
- `EnsureStaffTwoFactor`：staff/manager/admin ロール保持者が `two_factor_confirmed_at` null の場合、`/admin` 配下で 2FA セットアップ画面へリダイレクト（セットアップ画面自体は除外）。
- `AdminIdleTimeout`：session に `admin_last_activity`。`now - last > config('admin.idle_timeout')`（既定 1800 秒、`settings` で上書き可・Task 1-5 連携）なら logout + `/login?expired=1`。毎リクエストで更新。
- スタッフ作成（管理者操作）：`POST /admin/staff`（`can('staff.manage')`）→ User 作成 + `Staff` 作成 + `staff`|`manager`|`admin` ロール付与 + 初期パスワード設定メール（reset リンク）。**セルフ登録経路は作らない**。Action 経由（`App\Actions\Staff\CreateStaff`）で DB 更新。
- 機微操作の再認証：`password.confirm` middleware を「機微」ルート群に付与できる形を用意（Phase 1 では staff 作成・ロール変更に適用）。
- Inertia：`Auth/TwoFactorChallenge.vue`、`Admin/Profile/TwoFactorSetup.vue`（QR + recovery codes 表示）、`Admin/Staff/Index.vue` `Admin/Staff/Create.vue`（最小）。
- テスト：role なし user → `/admin` 403 ／ staff（2FA 未設定）→ setup へ ／ 2FA 設定済 staff → `/admin` 200 ／ idle 超過 → logout ／ 非 `staff.manage` → staff 作成 403 ／ `password.confirm` 要求。

### Task 1-4 — Customer / Admin 基本レイアウト（Run 3）
- `resources/js/layouts/CustomerLayout.vue`（スマホファースト：`v-app` + 上部 `v-app-bar` + 最小ナビ + `<slot/>`）。
- `resources/js/layouts/AdminLayout.vue`（PC/タブレット：`v-app` + `v-navigation-drawer`（permission でナビ項目出し分け、props 経由）+ `v-app-bar`（ユーザー名・ログアウト）+ コンテンツ）。
- Inertia の persistent layout パターンで各ページに適用。
- `resources/js/Pages/Customer/Dashboard.vue`（プレースホルダ「マイページ」）、`resources/js/Pages/Admin/Dashboard.vue`（日付・`failed_jobs` 件数プレースホルダ）。
- ルート：`/`（未ログイン→`Welcome`、顧客ログイン済→`Customer/Dashboard`）、`/admin`（`Admin/Dashboard`）。
- `resources/js/types/`（`inertia.d.ts`：共有 props（`auth`, `flash`）の型）。
- **Vuetify バンドル評価**（`docs/PLAN.md` の指示）：現状（手動一括登録 ≈ js 797kB / css 509kB）／必要 component 個別登録／`vite-plugin-vuetify`（公式 tree-shaking）の 3 案を比較。**公式構成で簡単・安全に削減できる場合のみ** `vite-plugin-vuetify` を採用（vite 8 + Inertia で問題なく統合でき、build 成功・型エラー 0 が条件）。複雑化・非互換があれば現状維持で技術課題として記録。before/after のサイズを本ログに記載。
- テスト：顧客ログイン後 `/` が `Customer/Dashboard` を返す ／ `/admin` が `Admin/Dashboard`（認可済み）を返す。

### Task 1-5 — settings / audit_logs / セキュリティ基盤（Run 4）
- migration：
  - `settings`（`key` varchar(80) PK、`value` varchar(255)、`type` varchar(20)）。
  - `audit_logs`（`id`、`actor_user_id` FK null、`action` varchar(60)、`entity_type` varchar(80) null、`entity_id` varchar(64) null、`summary` varchar(500)、`ip` varchar(45) null、`created_at`）。**before/after の JSON スナップショットは持たない。**
  - `db_size_snapshots`（`captured_on` date、`total_mb` int、`note` varchar(255) null）。
- `Setting` model + `App\Support\Settings\Settings`（typed get/set、キャッシュ可）。seeder で既定：`business_hours.open`=10:00、`business_hours.close`=22:00、`reservation.slot_minutes`=15、`reservation.hold_minutes`=10、`admin.idle_timeout`=1800。
- `App\Support\Audit\AuditLogger::log(string $action, ?Model $entity, string $summary, ?Authenticatable $actor = null)` → 1 行 INSERT（summary は 500 字で truncate、ip は request から）。
- 認証イベントを audit：`Login` / `Logout` / `Failed` / `PasswordReset` / `Verified` / 2FA 有効化 → listener で `AuditLogger`。
- セキュリティ基盤：
  - `SecurityHeaders` middleware（全レスポンス）：`Referrer-Policy: strict-origin-when-cross-origin`、`X-Content-Type-Options: nosniff`、`Permissions-Policy`（最小）、`Content-Security-Policy`（`default-src 'self'`; Vite/HMR を壊さない範囲。`'unsafe-inline'` は style のみ許容など現実的に）。`/admin` 配下は `X-Frame-Options: DENY`。
  - `AppServiceProvider::boot()`：`APP_ENV` が `config('stripe.block_live_keys_in')` に含まれ、かつ `config('stripe.key')` / `config('stripe.secret')` が `pk_live_` / `sk_live_` にマッチ → `RuntimeException` で起動停止。
  - CSRF（Inertia）・`EncryptCookies`・`TrustProxies` が有効であること確認。
- テスト：login で audit 行が 1 件 ／ CSP・`X-Frame-Options`（/admin）ヘッダ検証 ／ live key 検出時に boot 例外（config を一時上書き）／ `Settings::get` 既定値。

### Task 1-6 — State Machine 基盤（Run 5）
- `app/Support/StateMachine/`：
  - `StateMachine`（抽象 or trait）：`protected array $transitions`（`from => [to,...]`）、`can(from,to): bool`、`assert(from,to): void`、`apply($model, string $column, $to): void`（遷移＋`StateTransitioned` イベント発火）。
  - `InvalidStateTransitionException`。
  - 同一状態への遷移方針（既定：no-op 許容か例外か）を明記しテスト。
- ドメイン状態（reservations / payments 等）は**まだ定義しない**。テスト用フィクスチャ（`tests/Fixtures/DummyFlowStateMachine` + string 状態）で検証。
- Unit テスト：正常遷移 OK ／ 不正遷移で例外 ／ 未知状態で例外 ／ イベント発火。

### Task 1-7 — ExternalReservationGateway + NullExternalReservationGateway + binding（Run 5）
- `app/Modules/ExternalIntegration/`:
  - `Gateways/Reservation/ExternalReservationGateway.php`（interface。`docs/PLAN.md §8` のメソッド：`capabilities()` `fetchAvailability()` `pushReservation()` `updateExternalReservation()` `cancelExternalReservation()` `pullReservations()`）。
  - DTO（`app/Modules/ExternalIntegration/Gateways/Reservation/Dto/`）：`GatewayCapabilities`（readonly bool 群、`::none()` ファクトリ）、`AvailabilityQuery`、`AvailabilityResult`、`ReservationSnapshot`、`ExternalRef`。最小の値オブジェクト。
  - `NullExternalReservationGateway`：`capabilities()` → `GatewayCapabilities::none()`（全 false）。他メソッドはすべて **`UnsupportedOperationException` を throw（no-op 成功にしない）**。**DB アクセスを一切持たない。**
  - `Exceptions/UnsupportedOperationException`。
- バインド：`ExternalIntegrationServiceProvider`（`config/app.php` providers へ登録）で
  `bind(ExternalReservationGateway::class, fn() => new (config("reservation.gateways.".config('reservation.gateway').".class"))())`。
  既定 `EXTERNAL_RESERVATION_GATEWAY=null` → `NullExternalReservationGateway`。`peak_manager` / `salon_board` は Phase 1 未実装のため、選択時は「Phase 1 未実装」の明示例外（起動時 fatal にしない＝選択時のみ）。
- 契約テスト：`tests/Contract/ReservationGatewayContractTest`（`NullExternalReservationGateway` と `RecordingFakeGateway` の両方が satisfy）。Null は「capabilities 全 false」「全 write/fetch メソッドが `UnsupportedOperationException`」を assert。`app(ExternalReservationGateway::class)` が既定で Null。呼び出し時に DB クエリが発行されない（クエリカウンタ）。

### Task 1-8 — failed_jobs 最小表示（Run 6）
- `failed_jobs` テーブル（Laravel 既定 migration）を有効化。`QUEUE_CONNECTION=database`、failed driver `database-uuids`。
- `App\Support\Jobs\FailedJobsReader`（**read-only** クエリサービス：`count()`、`paginate()`。Controller から直接 `DB::table` しない）。
- `Admin/System/FailedJobs.vue`（読み取り専用一覧：uuid / connection / queue / 例外1行目 / failed_at + 件数バッジ）。
- ルート `/admin/system/failed-jobs`（`can('failed_jobs.view')`）。`Admin/Dashboard` に件数表示。
- **retry / delete は Phase 1 では作らない**（運用機能は後続）。
- テスト：`failed_jobs.view` なし → 403 ／ manager → 200 かつ投入した fake 行が見える ／ Reader の count。

### Task 1-9 — Phase 1 総合テスト / CI / security review（Run 7）
- `migrate:fresh --seed` がクリーンに通る。全 test 緑。`npm run build` 緑（型エラー 0）。
- CI（`.github/workflows/ci.yml`）：新規 migration / seeder / test が CI で走ることを確認。必要なら最小修正（例：`php artisan test` の前に `db:seed` が要るなら追加、または test 側で seeder を呼ぶ）。
- 不足 Feature テストを補完：register→verify→login / `/admin` deny / MFA 強制 / idle timeout / StateMachine 正常・不正 / Null Gateway fail-fast / failed_jobs 認可 / CSP ヘッダ / rate limit 429 / secrets・本番接続なし。
- `localhost` 確認：顧客側（`/`）と `/admin` の HTTP 応答（未認証・認証・権限別）。
- 本ログに最終結果を記載。

---

## 実行ログ

（Run ごとに：日時 / Codex 変更ファイル / Claude Code 検証 / 是正 / 結果）

### 2026-09-08 — Run 1（Task 1-1 / 1-2、Codex 実装）

- Codex 変更：Fortify actions / Inertia 認証ページ / User・Customer・Staff 基盤 / 認証 Feature test、Spatie role・permission seeder / SystemPolicy / 認可 Feature test を追加。`composer.json` と `bootstrap/providers.php` を更新。
- Claude Code 検証：依存取得・vendor publish・migration・seed・build・test は未実行。下記順序で実施する。
- Fortify publish 後の担当：`config/fortify.php` は **Claude Code が publish 後に編集**する（Codex は未生成ファイルを作成しない）。設定値は次のとおり。

```php
'views' => true,
'home' => '/',

'limiters' => [
    'login' => 'login',
    'two-factor' => 'two-factor',
],

'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::emailVerification(),
    Features::updatePasswords(),
    Features::updateProfileInformation(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]),
],
```

- `prefix` は `''`、`domain` は `null` の既定値を維持する。`passkeys` は features に含めない。
- 設計差異（Claude Code 要確認）：Laravel の `encrypted` cast は暗号文を格納するため `phone varchar(20)` / `birthday date` には収まらず、MySQL で保存不能となる。実装 migration は安全に暗号化保存できるよう両列を `text` とした。平文の論理制約（phone 最大 20 文字、birthday は日付）は今後それらを受け取る入力 Action の validation で担保する。
- Claude Code 実行順：
  1. `./vendor/bin/sail composer update`
  2. `./vendor/bin/sail artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"`
  3. `./vendor/bin/sail artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"`
  4. 上記の `config/fortify.php` 設定を Claude Code が反映
  5. `./vendor/bin/sail artisan migrate`
  6. `./vendor/bin/sail artisan db:seed --class=RolePermissionSeeder`
  7. `./vendor/bin/sail npm install`
  8. `./vendor/bin/sail npm run build`
  9. `./vendor/bin/sail artisan test`
- 結果：Claude Code の検証待ち。

---

### 2026-09-08 — Run 1（Task 1-1 認証・User 基盤 ＋ Task 1-2 RBAC）: ✅ 完了
- Codex 実装 → Claude Code 検証。修正 1 往復（rate limit throttle 適用・verify redirect assert・birthday cast/test）。
- **依存**：`laravel/fortify` v1.39.0、`spatie/laravel-permission` 6.25.0。
- **migration**：`add_two_factor_columns_to_users_table`（text×2 + timestamp）／`create_customers_table`（`user_id` PK+FK cascade、`kana`、`phone` text（encrypted）、`phone_hmac` char(64) index、`birthday` text（encrypted）、`gender`、`note` varchar(1000)、`stripe_customer_id` varchar(40) index、`created_via`）／`create_staff_table`（`user_id` PK+FK、`display_name`、`color`、`is_bookable`、`sort_order`）／spatie `create_permission_tables`（publish）。
- **設計差異（承認済み・rev.5 意図から決定可）**：`docs/DB_SCHEMA.md` の `phone varchar(20)` / `birthday date` は Laravel の `encrypted` cast が暗号文長のため収まらず、両列 `text` に変更。PII 暗号化 + `phone_hmac` 等価検索という意図は不変。`encrypted:date` は Laravel 13 非対応のため `birthday` は `encrypted` cast + 復号後 `Carbon::parse` の get アクセサ。→ DB_SCHEMA.md へ反映予定。
- **Model**：`User`（`MustVerifyEmail` + `TwoFactorAuthenticatable` + `HasRoles`、`two_factor_enabled` アクセサ、customer()/staff() hasOne）／`Customer`（`phone`/`birthday` encrypted、`saving` で `phone_hmac` 同期＝数字正規化 HMAC(app.key)、平文検索コピーなし）／`Staff`。
- **Fortify**：`CreateNewUser`（顧客セルフ登録のみ・`DB::transaction` で User+Customer+`customer` ロール）、`FortifyServiceProvider`（Inertia view 登録、`login`/`two-factor` limiter）、`config/fortify.php`（features: registration/resetPasswords/emailVerification/updatePasswords/updateProfileInformation/twoFactorAuthentication[confirm,confirmPassword]、passkeys 無効、`home`=`/`）。
- **rate limit**：`login`/`two-factor` は Fortify `limiters` 設定。`register.store`/`password.email` は `ThrottleFortifyRequests`（web append）→ `ThrottleRequests` へ委譲（`register` / `password-reset` limiter）。
- **RBAC**：ロール `customer/staff/manager/admin`、パーミッション 8（`admin.access` `failed_jobs.view` `audit_logs.view` `staff.manage` `settings.manage` `refund.execute` `ticket.grant` `membership.manage`）、`RolePermissionSeeder` 冪等、`Gate::before` 全権バイパスなし（deny-by-default）、`SystemPolicy` + `AuthServiceProvider`。
- **Inertia**：`Auth/{Login,Register,ForgotPassword,ResetPassword,VerifyEmail,ConfirmPassword,TwoFactorChallenge}.vue`（Vuetify 最小フォーム、`<script setup lang="ts">`）。`HandleInertiaRequests` の `auth.user` を実データ + `flash`。
- **検証結果**：`composer update` / publish / `migrate:fresh` / `db:seed` / `npm run build`（型エラー0）すべて成功。`artisan test` **15 passed / 117 assertions**。秘密情報・本番接続 混入なし。git コミット 0 件。
- **バンドル**：js 797kB / css 509kB（Vuetify・Run 3 で評価）。

---

### 2026-09-08 — Run 2（Task 1-3 スタッフ/管理者 MFA + `/admin` 認可 + idle timeout）: ✅ 完了
- Codex 実装 → Claude Code 検証。修正 1 往復（rev.5 §2 整合：`manager` から `staff.manage` 除去、staff 作成は admin 専用、`role` は `staff|manager` 限定・`role=admin` は 422）。
- **新規 middleware**：`AdminAccess`（`can('admin.access')` 以外 403 = deny-by-default）／`AdminIdleTimeout`（session `admin.last_activity`、`config('admin.idle_timeout')` 既定 1800 秒超過で `web` guard logout + invalidate + `redirect()->guest(route('login',['expired'=>1]))`、初回アクセスは非タイムアウト）／`EnsureStaffTwoFactor`（staff/manager/admin かつ `two_factor_confirmed_at` null で `/admin` 配下→`admin.two-factor-setup`。除外：setup 画面・Fortify 2FA 系・`password.confirm(.store)`・`logout`）。
- **ルート**：`routes/web.php` に `admin.` グループ（`web,auth,verified,AdminAccess,AdminIdleTimeout,EnsureStaffTwoFactor`）。`staff.*` は追加で `can:staff.manage` + `password.confirm`。
- **スタッフ作成**：`App\Actions\Staff\CreateStaff`（`DB::transaction` で User（ランダム一時PW・verified）+ `Staff` + `assignRole(staff|manager)` + パスワード設定リンクメール）。`StaffController` は Action 呼び出しのみ（DB 直更新なし）。`StoreStaffRequest` で `role` を `staff|manager` に限定。セルフ登録経路なし。
- **config**：`config/admin.php`（`idle_timeout` = `env('ADMIN_IDLE_TIMEOUT', 1800)`。settings 上書きは Task 1-5 で連携）。
- **Inertia**：`Admin/Dashboard.vue`（`failedJobsCount` プレースホルダ）／`Admin/Profile/TwoFactorSetup.vue`（Fortify 2FA エンドポイント・QR は Blob URL で `v-html` 不使用）／`Admin/Staff/{Index,Create}.vue`。
- **検証**：`npm run build` 型エラー0（js ≈799kB）。`artisan test` **26 passed / 176 assertions**。秘密情報・本番接続なし。コミット 0 件。
- **CSP メモ（Task 1-5）**：2FA QR を Blob URL 表示のため CSP に `img-src blob:` が必要。
- **manager 権限（確定）**：`admin.access, failed_jobs.view, audit_logs.view, settings.manage, refund.execute, ticket.grant, membership.manage`（`staff.manage` は admin のみ）。

---

### 2026-09-08 — Run 3（Task 1-4 Customer / Admin 基本レイアウト + Vuetify バンドル評価、Codex 実装）

- **レイアウト**：`CustomerLayout.vue`（スマホファーストの app bar・最小ナビ・ユーザー名・ログアウト）と `AdminLayout.vue`（常設 drawer・app bar・ログアウト）を追加。Customer Dashboard と既存 Admin 4 ページへ `defineOptions({ layout: ... })` による Inertia persistent layout を適用。`Auth/*.vue` と `Welcome.vue` はレイアウト未適用のまま維持。
- **permission ナビ**：`HandleInertiaRequests::share` の `auth.can` に `staffManage` / `failedJobsView` / `auditLogsView` の bool を追加。Admin drawer は dashboard を常時表示し、ほか 3 項目を共有 props の permission で出し分け。未実装の failed jobs / audit logs 遷移は disabled とした。guard / middleware は追加していない。
- **ホームルート**：`HomeController` を追加し、未ログインは `Welcome`、customer ロールは `Customer/Dashboard`、staff / manager / admin は `/admin` へリダイレクト。既存 admin route group は変更なし。
- **型**：`resources/js/types/inertia.d.ts` で Inertia の共有 props（`name` / `auth.user` / `auth.can` / `flash`）を型付け。
- **Vuetify 3 案比較**：
  - (A) 手動一括登録：変更前 build は app JS **798.77 kB**（gzip 253.75 kB）／app CSS **509.44 kB**（gzip 64.66 kB）。レイアウト追加後の同条件 build は app JS **800.46 kB**／app CSS **509.44 kB**。500 kB 超 warning あり。
  - (B) 必要 component の手動個別登録：一括登録より削減可能だが、ページ追加ごとに登録一覧を人手で同期する必要があり、(C) と同じ目的に対して保守負担が増えるため不採用。
  - (C) `vite-plugin-vuetify` 2.1.3 + `autoImport: true`：vite 8.2.2 / Inertia 2 で build 成功・型エラー 0。出力は entry JS **23.68 kB**、最大共有 JS（theme）**296.78 kB**、全 JS 合計 **533.35 kB**／基底 CSS **246.67 kB**、全 CSS 合計 **353.39 kB**。ルート別 chunk に分割され、500 kB 超 warning なし。公式 auto import で構成も単純なため **(C) を採用**。
- **検証**：`sail npm install --save-dev vite-plugin-vuetify` 成功（脆弱性 0）／`sail npm run build` 成功（型エラー 0）／`sail artisan test` **28 passed / 194 assertions**／変更対象 PHP の `sail pint --test` 成功。Claude Code の最終再検証待ち。
- **受け入れ自己判定**：Task 1-4 の Run 3 条件をすべて充足。Run 1・2 の認証 / RBAC / MFA / idle timeout / admin middleware は非破壊。DB 更新・本番接続・秘密情報追加・git add / commit / push はなし。

#### Run 3 — Claude Code 検証（2026-09-08）
- `sail npm install`（脆弱性0）／`sail npm run build`：**`vite-plugin-vuetify` 採用で code-split 化**。`app-*.css` 509kB→**246.67kB**、component 別チャンク（VCard/VTextField/VAlert…）、>500kB 警告 解消、型エラー 0。
- `sail artisan test`：**28 passed / 194 assertions**。
- `curl`：`/`→200、`/admin`→302（未認証リダイレクト）、`/login`→200。
- 秘密情報・本番接続なし。コミット 0 件。Run 1・2 非破壊。
- Vuetify 最終：(A) js≈800/css509 → (C) `vite-plugin-vuetify` js合計≈533(entry24)/css合計≈353。**(C) 採用**（vite 8.2.2 + Inertia 2 で安定、公式構成、簡単）。

---

### 2026-09-08 — Run 4（Task 1-5 settings / audit_logs / セキュリティ基盤）: ✅ 完了
- Codex 実装 → Claude Code 検証。修正 1 往復（`AuditLogger` の summary 切り詰めを `Str::limit`（表示幅・CJK幅2）→ `Str::substr`（文字数）に）。
- **migration**：`settings`（`key` varchar(80) PK / `value` varchar(255) / `type` varchar(20)）／`audit_logs`（`actor_user_id` nullOnDelete FK / `action` varchar(60) / `entity_type` varchar(80) / `entity_id` varchar(64) / `summary` varchar(500) / `ip` varchar(45) / `created_at` のみ・**スナップショット列なし**・index `(action)`/`(actor_user_id)`/`(entity_type,entity_id)`）／`db_size_snapshots`（`captured_on` date unique / `total_mb` / `note`）。
- **Settings**：`app/Support/Settings/Settings.php`（typed get/set・リクエスト単位メモ化・`app('settings')` alias）、`SettingsSeeder`（冪等・`business_hours.open/close`、`reservation.slot_minutes`=15、`reservation.hold_minutes`=10、`admin.idle_timeout`=1800）。`AdminIdleTimeout` は `Settings::get('admin.idle_timeout', config(...))` に変更（未 seed でも config フォールバック）。
- **AuditLogger**：`app/Support/Audit/AuditLogger.php`（1 行 INSERT、actor 未指定は `auth()->user()`、`summary` は `Str::substr(...,0,500)`、`ip`=`request()->ip()`、`entity` から morphClass/key）。`AuditAuthEvents` listener（`Event::subscribe`、`on*` メソッド）で `Login`/`Logout`/`Failed`/`PasswordReset`/`Verified`/`TwoFactorAuthenticationConfirmed` → 監査。PII 過多にしない（パスワード非記録）。
- **SecurityHeaders**（web append）：`Referrer-Policy` / `X-Content-Type-Options: nosniff` / `Permissions-Policy`（camera/mic/geo/payment 無効）/ `Content-Security-Policy`（**非local厳格**：`script-src 'self'` 等 ／ **local**：Vite 用に `'unsafe-inline' 'unsafe-eval' http://localhost:5173 ws:` 許容、`img-src 'self' data: blob:`（2FA QR））。`/admin` 配下は追加で `X-Frame-Options: DENY`。
- **Stripe Live キー起動ガード**：`AppServiceProvider::boot()` → `assertNoStripeLiveKeys()`（public static・テストから直接呼べる）。`APP_ENV in config('stripe.block_live_keys_in')` かつ key が `sk_live_`/`pk_live_` で `RuntimeException`。
- CSRF / Cookie 暗号化 / TrustProxies は Laravel 13 既定のまま（変更なし）。
- **検証**：`migrate` / `seed` 成功。`artisan test` **39 passed / 224 assertions**。ヘッダ curl 確認済み。秘密情報・本番接続なし。コミット 0 件。

---

### 2026-09-08 — Run 5（Task 1-6 State Machine 基盤 ＋ Task 1-7 ExternalReservationGateway）: ✅ 完了（無修正）
- **Task 1-6**：`app/Support/StateMachine/StateMachine.php`（抽象・`transitions()` map、`can`/`assert`/`apply`（列更新→save→`StateTransitioned` イベント）、**`from===to`・未知 from・未知 to はすべて例外**、メッセージに from/to/machine）／`InvalidStateTransitionException`（`\DomainException`）／`Events\StateTransitioned`（readonly）。**ドメイン state（reservations 等）は未定義**。フィクスチャ `tests/Fixtures/StateMachine/{DummyFlow,DummyFlowStateMachine}.php`（テーブルは test の setUp/tearDown で作成）。Unit テスト `tests/Unit/StateMachine/StateMachineTest.php`。
- **Task 1-7**：`app/Modules/ExternalIntegration/`
  - `Gateways/Reservation/ExternalReservationGateway.php`（interface：`capabilities` / `fetchAvailability` / `pushReservation` / `updateExternalReservation` / `cancelExternalReservation` / `pullReservations`）
  - `Dto/`（readonly）：`GatewayCapabilities`（`::none()` 全 false）／`AvailabilityQuery`／`AvailabilityResult`／`ReservationSnapshot`／`ExternalRef`。日時は `CarbonImmutable`。
  - `NullExternalReservationGateway`：`capabilities()` 全 false、**write/fetch 5 メソッドは `UnsupportedOperationException` で fail-fast（no-op 成功にしない）**、**DB/Eloquent/Schema を import すらしない**。
  - `Exceptions/{UnsupportedOperationException,GatewayNotImplementedException}`。
  - `ExternalIntegrationServiceProvider`（`bootstrap/providers.php` 末尾に追加）：`bind` クロージャで `config('reservation.gateway')` を**解決時に**評価。既定 `null` → `NullExternalReservationGateway`。`peak_manager`/`salon_board` or クラス不在 → `GatewayNotImplementedException`（**boot 時 fatal にしない**）。
  - 契約テスト：`ReservationGatewayContractTestCase`（抽象）+ `NullExternalReservationGatewayTest`（`expectsUnsupported`=true、capabilities 全 false）+ `RecordingFakeReservationGatewayTest`（`expectsUnsupported`=false）+ `tests/Support/RecordingFakeReservationGateway`（DB 非依存フェイク）。`GatewayBindingTest`：既定で Null インスタンス／`peak_manager` 指定で `GatewayNotImplementedException`／Null 呼び出し時 DB クエリ 0。
  - `phpunit.xml` に `tests/Contract` suite 追記。
- **検証**：`artisan test` **62 passed / 274 assertions**。秘密情報・本番接続なし。コミット 0 件。Run 1〜4 非破壊。

---

### 2026-09-08 — Run 6（Task 1-8 failed_jobs 最小表示）: ✅ 完了（無修正）
- `App\Support\Jobs\FailedJobsReader`（読み取り専用：`count()` / `latest($perPage=25)`＝`failed_at` 降順・例外 1 行目 300 字切り詰め。書き込みメソッドなし）。
- `Admin\FailedJobsController::__invoke(FailedJobsReader $reader)` → `Inertia::render('Admin/System/FailedJobs', ['jobs'=>..., 'count'=>...])`。**Controller から `DB::table` 直呼びなし**。
- ルート `admin.system.failed-jobs`（`can:failed_jobs.view`。既存 admin グループ middleware 継承）。`AdminDashboardController` の `failedJobsCount` を実値に。
- `Admin/System/FailedJobs.vue`（`AdminLayout`・読み取り専用テーブル・ページネーション。**retry/delete なし**）。`AdminLayout` の failed_jobs ナビを `auth.can.failedJobsView` で有効化。
- `config/queue.php` failed driver は既定 `database-uuids`・`.env.example` は `QUEUE_CONNECTION=database`（変更なし）。
- **検証**：`npm run build` 型エラー0。`artisan test` **67 passed**。`/admin/system/failed-jobs` 未認証 302。

### 2026-09-08 — Run 7（Task 1-9 の残り：CSRF 明示テスト）: ✅ 完了（無修正）
- `tests/Feature/Security/CsrfProtectionTest.php`：トークンなし `POST /logout` → 419 ／ 正しいセッショントークン → 302 `/` ／ `GET /login` → 200。
  （Laravel は `testing` 環境で CSRF 検証を省くため、当テストクラスのみ環境名を `csrf-testing` に変更して実保護を検証）
- **検証**：`artisan test` **70 passed / 329 assertions**。

---

## ✅ Phase 1 完了 — 2026-09-08

### 最終検証結果
- `migrate:fresh --seed`：10 migration + 2 seeder クリーン
- `npm run build`：`vue-tsc --noEmit` 型エラー **0** ／ vite-plugin-vuetify で entry JS 24kB・CSS 247kB・>500kB 警告なし
- `artisan test`：**70 passed / 329 assertions**（Feature/Unit/Contract）
- `composer audit` / `npm audit`：脆弱性 **0**
- 全ツリー秘密情報スキャン（実キー形式・AWS・PEM）：**混入なし**
- CI ガード ローカル擬似実行：live key / whsec / WP DB 接続 いずれも誤検知なし
- 本番/外部実接続（`ark-conditioning.com` / `64ssq_ark_db` / Peak Manager / SALON BOARD / Hot Pepper）文字列：**なし**
- localhost：`/` `/login` `/register` `/forgot-password` `/up` → 200 ／ `/admin*` → 302（未認証）
- git：**コミット 0 件**。`git add`/`commit`/`push` 未実行

### 残課題（Phase 2 以降・非ブロッカー）
1. **サーバー実測**（お名前.com、`tools/server-probe.*`）と共有 vs VPS 判断は未確定（Phase 0 残・`OPEN_QUESTIONS` #1-3）。
2. CI は git remote 未接続のため**実走なし**（ガード・手順はローカル擬似実行で確認）。
3. `birthday` は暗号化のため DB 側の日付フィルタ不可（`docs/DB_SCHEMA.md` に記録）。年齢等での抽出が必要になったら Phase 2+ で再検討。
4. `CsrfProtectionTest` は環境名切替という小さな工夫で実保護を検証（テスト専用・他へ影響なし）。
5. `AdminIdleTimeout` の settings 上書きは実装済みだが、settings 変更 UI（管理画面）は未実装（Phase 8 想定）。
6. スタッフ作成の `admin` ロール付与はフォーム非対応（seeder/console のみ）。将来必要なら別途「上位ロール付与」機能を検討。

### Phase 2 開始条件
Phase 1 の共通基盤は完成。Phase 2（顧客・スタッフ・サービス・ブースのマスタ CRUD）は
`RESERVATION_AUTHORITY=local` / `EXTERNAL_RESERVATION_GATEWAY=null` / Stripe 未接続で着手可能。
**ユーザーの明示許可を待つ。**
