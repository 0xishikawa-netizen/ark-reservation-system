# Phase 9.6 — ARK ブランド再構築 / 認証 UX 整理 / Google ログイン

> 実装ログ。ベースライン: `8cd394f`（Harden ARK production quality / Phase 9.5）。
> Phase 10 / Phase 11 には進まない。

## 目的

1. ARK 公式サイト（ark-conditioning.com）の実ブランドへデザインを合わせ直す。
2. Passkey / WebAuthn を撤去する。
3. 「ログイン → 必要なら 2 段階認証」の一般的な認証フローへ整理する。
4. Google ログイン（Laravel Socialite）を追加する。
5. 認証・認可・MFA・IDOR・セッションを再監査する。

## 1. ブランド再構築

- 公式サイトの子テーマ CSS（`twentytwenty-child/style/css/common.css`）とロゴ PNG を機械抽出。
  - **brandPrimary = navy `#1A2653`**（最頻・一次 CTA・リンク・見出し下線）。CONFIRMED。
  - ロゴグラデーション: azure `#0087C5` → navy `#12193C`。CONFIRMED。
  - 旧 teal 系（`#1F8A80` 等）は公式と不一致 → 撤去。
- `resources/js/plugins/vuetify.ts` を navy ベースへ再構築。semantic（success/warning/error/info）は
  navy と色相分離した独立系統として定義（ステータスを navy で塗り潰さない）。
- `resources/css/app.css` のハードコード色（`#22282b` / `#f7f6f2` / `#1f8a80`）を更新。
- `resources/js/design/tokens.ts` の `statusColor()`（業務状態 → Vuetify カラー名）は既に
  brand と分離されており変更不要。
- 根拠ドキュメント: `docs/design/ARK_DESIGN_SYSTEM.md`（新規、CONFIRMED / INFERRED を明記）。
- 認証画面を共通シェル `components/auth/AuthCard.vue` に統一（ロゴマーク + navy）。
- `Pages/Welcome.vue`（未ログイン LP）を ARK ブランドの簡易 LP に置換。
- `Admin/Reservations/Index.vue` のローカル status 配色マップを撤去し `tokens.ts` に一本化。
  予約「経路」バッジは status ではなくデータ区分なので別系統色（ARK 自社 Web = primary）。
- `Admin/Schedule/Index.vue` の teal 系アクセント（`#00897b` / focus `#1565c0`）を navy へ。
  タイムライン地のブルーグレー・スケールは業務可読性のため維持。

## 2. Passkey 撤去

| 対象 | 対応 |
|---|---|
| `config/fortify.php` | `Features::passkeys()` と `passkeys` 設定ブロックを削除 |
| `app/Models/User.php` | `PasskeyUser` 実装 / `PasskeyAuthenticatable` trait を削除 |
| `app/Providers/AppServiceProvider.php` | Passkey イベントリスナ登録を削除 |
| `app/Listeners/AuditPasskeyEvents.php` | 削除 |
| `app/Http/Middleware/PreventLastMfaRemoval.php` | `PreventStaffTotpDisable.php` へ置換（業務ロールの TOTP 無効化を拒否） |
| `app/Domain/Auth/MfaPolicy.php` | TOTP のみで判定（`isSatisfiedBy` = `hasConfirmedTotp`）。passkey メソッド削除 |
| `app/Http/Controllers/Admin/MfaController.php` | Passkey 一覧のレスポンスを削除 |
| `app/Http/Middleware/EnsureStaffMfa.php` | 除外ルートから passkey.* を削除 |
| `resources/js/lib/webauthn.ts` | 削除 |
| `resources/js/Pages/Auth/Login.vue` / `ConfirmPassword.vue` | Passkey 導線を削除しシンプル化 |
| `resources/js/Pages/Admin/Profile/Mfa.vue` | Passkey カードを削除。TOTP + SMS + Google 連携のみ |
| `passkeys` テーブル | **新規** migration `2026_09_10_000008_drop_passkeys_table` で drop（履歴 migration は不変更） |
| `laravel/passkeys` (composer) | `laravel/fortify` の依存のため残置。アプリからの参照は 0 |
| `.env.example` / `.env` | `PASSKEYS_*` を削除 |

`grep -rniE "passkey|webauthn|PublicKeyCredential" app resources routes config` → コード参照 0
（テスト内はコメントのみ）。Fortify の `/passkeys/*` / `/user/passkeys/*` ルートは非生成を `route:list` で確認。

## 3. 認証フロー整理（TOTP 一本化）

- フロー: メール・パスワード（または Google）→ 主認証成功 → 業務ロールのみ 6 桁 TOTP チャレンジ。
  - Fortify の `RedirectIfTwoFactorAuthenticatable` がパスワードログイン時のチャレンジを担う（既存動作）。
  - TOTP 未設定の業務ロールは `EnsureStaffMfa` が `/admin/mfa` へ隔離（`/admin` 本体は不可）。
- MFA 対象: `staff` / `manager` / `admin`（`MfaPolicy::REQUIRED_ROLES`）。customer は不要（据え置き）。
- 業務ロールは TOTP を無効化不可（`PreventStaffTotpDisable`、`web` グループで横断）。customer は可。
- Recovery Code 導線は Fortify 標準のまま維持。TwoFactorChallenge 画面に残置。
- MFA challenge 画面（`Auth/TwoFactorChallenge.vue`）を AuthCard 化しシンプルに（Passkey 導線なし）。

## 4. Google ログイン（Laravel Socialite）

- `composer require laravel/socialite`（`-W`。追加は socialite / league/oauth1-client / firebase/php-jwt のみ、
  既存パッケージのバージョン変更なし。`composer audit` GREEN）。
- `config/services.php` に `google` ブロック（`.env` のみで credential 管理）。
- **migration** `2026_09_10_000009_create_user_social_accounts_table`
  - `id / user_id FK cascade / provider(32) / provider_user_id(191) / provider_email null / timestamps`
  - `UNIQUE(provider, provider_user_id)` / `INDEX(user_id, provider)`
  - OAuth token は保存しない。
- **migration** `2026_09_10_000010_make_users_password_nullable`
  - Google のみで登録したユーザーはパスワード未設定（`null`）。連携解除時の自己ロックアウト判定を正確に。
- **`app/Models/UserSocialAccount.php`** / `User::socialAccounts()` HasMany。
- **`app/Http/Controllers/Auth/GoogleAuthController.php`**
  - `GET /auth/google/redirect`（guest / login intent、`throttle:google-oauth`）
  - `GET /auth/google/callback`（`throttle:google-oauth`、stateful state 検証）
  - `GET /auth/google/confirm` + `POST /auth/google/link-existing`（既存 Customer をパスワード確認して連携）
  - `GET /auth/google/link`（auth + `password.confirm`、設定画面からの連携）
  - `DELETE /auth/google/unlink`（auth + `password.confirm`、パスワード未設定なら拒否）
- 分岐:
  1. `provider_user_id` が既存 → そのユーザーでログイン。
  2. email 一致の既存ユーザーあり:
     - 特権ロール → **auto-link 禁止・ログインさせない**（`login` へ安全なメッセージ）。監査 `auth.google.link_blocked`。
     - Customer → `auth.google.confirm` へ。**silent link しない**。パスワード確認後に連携＋（未認証なら）
       `email_verified_at` を確定。
  3. email 一致なし → **Customer ロールのみ**新規作成。`email_verified_at = now()`
     （Google `email_verified=true` を必須確認済み）。
- MFA 合流（`loginAndContinue`）:
  - session ID を必ず `regenerate()`（fixation 対策）。
  - 業務ロール + 確認済み TOTP → `Auth::login` せず `login.id` をセットして `two-factor.login` へ
    （TOTP コード入力必須。Google が MFA を bypass しない）。
  - 業務ロール + TOTP 未設定 → ログインするが `/admin/mfa` へ隔離。
  - customer → `intended()`（既定 `/`）。
- Open redirect: `?return` は内部パス（`^/` かつ `//` でない）のみ許可。
- Google credential はフロントに露出しない（`config('services.google.client_secret')` を返す箇所なし）。
- **画面**: `Auth/LinkGoogle.vue`（既存アカウント連携）、`Customer/Profile/Security.vue`（連携状態 + link/unlink）、
  `Admin/Profile/Mfa.vue` に Google 連携カード。ログイン / 登録画面に Google ボタン（公式ガイドラインの
  ロゴ + 白地・アウトライン。ARK Primary CTA と視覚的に区別）。

## 5. テスト

- `tests/Feature/Auth/GoogleLoginTest.php`（新規, 24 ケース）:
  redirect / new customer（customer ロールのみ）/ linked login / provider_user_id 重複で user 増えない /
  既存 Customer は silent takeover 不可（confirm へ）/ パスワード確認で連携 / 誤パスワード拒否 /
  staff・admin の email collision は auto-link せずログインもしない / link-existing も特権拒否 /
  invalid state を安全に拒否 / callback error で内部情報を出さない / 未検証 Google email 拒否 /
  staff Google → TOTP チャレンジ（未ログイン・`/admin` 不可）/ admin Google（TOTP 無）→ `/admin/mfa` 隔離 /
  customer Google → ポータル / 設定からの link / 他ユーザー使用中の Google は連携拒否 /
  unlink はパスワード必須 / `UNIQUE(provider, provider_user_id)` / client_secret 非露出。
- `MfaPolicyTest` / `MfaSecurityTest` / `MfaNonRegressionTest` を TOTP 前提に書き換え
  （passkey ヘルパーを confirmed-TOTP ヘルパーへ）。
- `AdminAccessTest` は docblock のみ更新（`two_factor_confirmed_at` 前提で元から通る）。

## 5b. 認証 Red Team（Codex gpt-5.x, READ-ONLY）

CRITICAL: 0 / HIGH: 0。MEDIUM 3 + LOW 2 を受けて以下を対応（すべて本 Phase 内で fix）:

| 指摘 | 対応 |
|---|---|
| MEDIUM: 1 ユーザーに複数 Google identity を連携でき、unlink が 1 行しか消さない（乗っ取り継続の恐れ） | `UNIQUE(user_id, provider)` を追加。`handleLink()` で既存連携ありなら拒否。`unlink()` は provider 単位で全行削除 |
| MEDIUM: `POST /user/confirm-password`（再認証）にレート制限が無い | `ThrottleFortifyRequests` に `password.confirm.store` → limiter `password-confirm`（user+IP で 6/min）を追加 |
| MEDIUM: `force=1` で TOTP 秘密鍵を再生成しても `two_factor_confirmed_at` が残り、未確認の鍵が「確認済み」扱いになる | `TwoFactorAuthenticationEnabled` を購読し、再生成時は `two_factor_confirmed_at` を null に戻す（`auth.two_factor_reset` 監査）。以後 `EnsureStaffMfa` が再確認まで `/admin/mfa` に隔離 |
| LOW: `make_users_password_nullable` の `down()` が null パスワード行で失敗 | `down()` で null パスワードを使用不能ハッシュで backfill してから NOT NULL 化 |
| LOW: `drop_passkeys_table` は forward-only で破壊的 | 本番運用前・テーブル空を前提と明記（migration コメント） |
| 補足: 同時 callback で email UNIQUE 衝突時 500 | `QueryException` を捕捉し、既存 social 行で login 解決／安全なエラーへ |
| 補足: 残存 passkey コメント 2 件 | `routes/web.php` / `config/mfa.php` を修正 |

Red Team が「問題なし」と確認した項目: email 一致だけの account takeover 不可 / 特権 role 自動付与不可 /
Google 経由の MFA bypass 不可（`login.id` はサーバ側・リクエスト入力不可）/ session fixation 対策あり /
Socialite stateful（state 検証あり）/ open redirect 不可 / IDOR 不可 / secret 非永続・非露出 /
既存の privileged 未認証ユーザーを Google で email 認証突破できない。

## 6. 実接続の判定（勝手に GREEN にしない）

- REAL GOOGLE OAUTH QA: **INCOMPLETE**（実 Google Cloud OAuth Client 未作成。Socialite モックの自動テストは GREEN）。
- REAL STRIPE TEST MODE QA: INCOMPLETE（据え置き）。
- Membership Production Readiness: NOT READY（据え置き）。
- SALON BOARD / Peak Manager: BLOCKED / OFFICIAL SPEC WAITING（据え置き）。
- Visual QA: 実ブラウザ目視は HUMAN-ONLY。コードレベル / ビルド / 自動テストは実施。

## 7. Google Cloud 側で人間が行う作業（コード完成後）

1. Google Cloud プロジェクトの確認 / 作成。
2. OAuth 同意画面（ブランディング / スコープ: `openid` `email` `profile` / テストユーザー or 公開）。
3. 認証情報 → OAuth 2.0 クライアント ID（種類: ウェブ アプリケーション）を作成。
4. 承認済みリダイレクト URI に各環境の `"{APP_URL}/auth/google/callback"` を登録。
5. 発行された Client ID / Secret を各環境の `.env` に設定（`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` /
   `GOOGLE_REDIRECT_URI`）。**値をチャットや Git に貼らない。**
