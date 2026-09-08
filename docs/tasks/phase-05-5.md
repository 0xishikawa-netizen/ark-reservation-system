# Phase 5.5 — Auth Hardening / MFA Modernization

設計正本: `docs/PLAN.md`（§12 認証・権限 / §13 セキュリティ）+ `docs/MFA_MODERNIZATION.md`（調査・設計）。
本書はそれを Phase 5.5 に落としたタスク計画 + 実行ログ。

## baseline

- Phase 5 baseline commit: **`880761f` "Phase 5 single payment baseline"**（remote なし・push なし）。
- Phase 5.5 の `git diff` レビュー基準は `880761f`。

## 目的

`staff` / `manager` / `admin` の MFA を TOTP 必須から **Passkey 標準**へ移行する。

```
第一選択 : Passkey / WebAuthn（Touch ID / Face ID / Windows Hello）
代替     : TOTP（既存ユーザーの互換・移行期のバックアップ）
補助     : SMS OTP（フォールバック。単独では MFA 要件を満たさない）
最終復旧 : Recovery Code
```

`customer` には MFA を課さない（PLAN §12 のまま）。

認証強度: **Passkey > TOTP > SMS OTP**。

## Phase 5.5 で実装しないもの（絶対）

Phase 6 Membership / subscription / recurring billing / Cashier subscription migration /
Peak Manager・SALON BOARD・Hot Pepper 実 API / LINE / Reporting / multi-store / `store_id` /
**実 SMS provider への接続** / Stripe Live / 本番 DB / WordPress 変更 / remote 追加 / push。

---

# 1. 設計判断

## 1-1. WebAuthn は自前実装しない

`laravel/fortify ^1.0` が同梱する **`laravel/passkeys` v0.2.1**（Laravel 公式）を使う。
`Features::passkeys()` を有効化すると Fortify が
`fortify.passkeys.*` → `passkeys.*` へ config をマップし、
ルート・コントローラ・challenge / origin / RP ID 検証・credential 保存まで面倒を見る。

→ **challenge 検証・origin 検証・RP ID 検証・replay 防止・公開鍵保存・sign counter は
ライブラリ側の責務**。独自の暗号実装・独自 credential テーブルを作らない。

## 1-2. MFA 要件判定を 1 箇所へ集約（**最重要**）

現行 `EnsureStaffTwoFactor` は `users.two_factor_confirmed_at !== null` だけを見る。
このままでは **Passkey だけ登録したユーザーが「MFA 未設定」と判定され全員ロックアウトされる**。

→ `App\Domain\Auth\MfaPolicy` に判定を集約し、middleware / UI / テストが同じ関数を使う。

```
満たす（MFA 設定済み）: passkey が 1 つ以上 OR two_factor_confirmed_at !== null
SMS は「フォールバック」であり、単独では要件を満たさない（SMS だけの状態を標準にしない）
```

## 1-3. 段階移行（既存 TOTP ユーザーをロックアウトしない）

`passkey OR totp` を要件とするため、**既存 TOTP ユーザーは何もしなくてもログインできる**。
Passkey 登録は UI で強く promote するが強制しない。**TOTP credential を一括削除しない。**

## 1-4. SMS provider は未契約。interface のみ実装する

`SmsSender` interface + `LogSmsSender`（local。ログに**コードを出さない**）+ `FakeSmsSender`（テスト）。
**実 provider へは接続しない。** 具象実装は provider 契約後（`docs/OPEN_QUESTIONS.md`）。

---

# 2. DB

| テーブル | 変更 |
|---|---|
| `passkeys` | `laravel/passkeys` 同梱 migration を publish してそのまま使う（独自 schema を作らない） |
| `staff` | `phone`（text / `encrypted`）、`phone_hmac`（char(64) index）、`phone_verified_at` を追加 |
| `mfa_sms_challenges` | 新規（下記） |
| `users` | **変更しない**（`two_factor_*` は移行期の互換のため残す） |

**mfa_sms_challenges**

| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| user_id | FK users cascadeOnDelete | |
| purpose | varchar(20) | `login` / `phone_verification` |
| phone_hmac | char(64) | 送信先の照合用。**平文電話番号を保存しない** |
| code_hash | varchar(255) | `Hash::make()`。**平文 OTP を保存しない** |
| expires_at | datetime | |
| attempts | tinyint unsigned | verify 試行回数 |
| used_at | datetime null | 一回使用で無効 |
| sent_at | datetime | |
| ip | varchar(45) null | 濫用調査用 |
| timestamps | | `model:prune` 対象（7 日） |

電話番号は既存 PII 方針（`customers.phone` / `PiiHasher`）に合わせる:
at-rest は `encrypted` cast、等価検索は `PII_LOOKUP_KEY` による keyed HMAC。**`APP_KEY` に依存させない。**

---

# 3. セキュリティ要件

## 3-1. OTP（すべて config 化。ハードコードしない）

| 項目 | 既定 |
|---|---|
| 桁数 | 6 |
| 生成 | `random_int()`（CSPRNG）。`rand()` / `mt_rand()` 禁止 |
| TTL | 5 分 |
| 保存 | `Hash::make()`（argon2id）。**平文 DB 保存・ログ出力を禁止** |
| verify 試行上限 | 5 回で challenge 失効 |
| resend | 60 秒に 1 回 / 1 時間に 5 回 |
| rate limit | user 単位 + IP 単位の二重 |
| 送信先 | `phone_verified_at !== null` の番号のみ（`purpose=phone_verification` を除く） |

## 3-2. 自己ロックアウト対策（Phase 5.5 で最重要）

- **最後の MFA 手段は削除できない**（Passkey が 1 つで TOTP 未設定なら削除拒否）。
- Recovery Code の生成・保管を UI で強く促す。
- 復旧手順を `docs/OPERATIONS.md` に記載。
- **裏口の master password を作らない。local だけ MFA を完全バイパスする分岐も作らない。**

## 3-3. 機微操作の再認証

`password.confirm` は**削除しない**。Passkey 登録済みユーザーは Passkey 再認証も選べる
（Fortify の `passkey.confirm-options` / `passkey.confirm` を使用。どちらも
`auth.password_confirmed_at` セッションを立てるため、既存の `password.confirm` middleware がそのまま機能する）。

対象: 返金 / 回数券 付与・取消・調整 / 設定変更 / **Passkey 削除** / **電話番号変更** / スタッフ管理。

## 3-4. 監査（PII / secret を残さない）

`mfa.passkey.registered` / `mfa.passkey.removed` / `mfa.sms.sent` / `mfa.sms.verified` /
`mfa.sms.failed` / `mfa.phone.changed` / `mfa.recovery.regenerated` / `mfa.totp.disabled`

**OTP・Passkey の credential・recovery code 平文・電話番号平文を audit / log に出さない。**

---

# 4. Task 一覧

| Task | 内容 |
|---|---|
| 5.5-1 | `MfaPolicy` 抽出 + `EnsureStaffMfa` へ置換（**挙動を変えない**リファクタ + テスト） |
| 5.5-2 | Passkey 有効化（`Features::passkeys()` / migration / User モデル / config） |
| 5.5-3 | Passkey 登録・削除・一覧 UI + 最後の MFA 削除拒否 |
| 5.5-4 | SMS 基盤（`SmsSender` / `LogSmsSender` / `FakeSmsSender` / `mfa_sms_challenges` / `staff.phone`） |
| 5.5-5 | 電話番号確認・変更フロー（reauth 必須） |
| 5.5-6 | Recovery Code 確認 + TOTP 移行導線 |
| 5.5-7 | 機微操作の Passkey 再認証（`password.confirm` は fallback として維持） |
| 5.5-8 | セキュリティ / ロックアウト / rate limit / 監査テスト |
| 5.5-9 | E2E + 最終レビュー + 非退行確認（Phase 4 Ticket / Phase 5 Stripe） |

---

# 5. 実行ログ

（各 Task 完了時に追記）

### 2026-09-08 — Task 5.5-1（MfaPolicy 抽出）: ✅ 完了

- `App\Domain\Auth\MfaPolicy` を新設。`EnsureStaffTwoFactor` を削除し `EnsureStaffMfa` へ置換。
- 判定: **Passkey 1 つ以上 OR 確認済み TOTP**。SMS は `hasVerifiedPhone()` として別枠（要件を満たさない）。
- 誘導先を `admin.two-factor-setup` → `admin.mfa.show`（統合 MFA 画面）へ変更。
  既存テスト `AdminAccessTest` を意図的な挙動変更として更新。

### 2026-09-08 — Task 5.5-2 / 5.5-3（Passkey 有効化・UI）: ✅ 完了

- `Features::passkeys()` 有効化。`passkeys` migration を publish し `000023` へリネーム。
- `User` に `PasskeyUser` 実装 + `PasskeyAuthenticatable` trait。
- `PASSKEYS_USER_HANDLE_SECRET` を **`APP_KEY` と独立**した env に（鍵ローテーションで既存 Passkey を失わないため）。
- `Admin/Profile/Mfa.vue`（一覧・追加・削除・移行導線）、ログイン画面と再認証画面に Passkey ボタン。
- `resources/js/lib/webauthn.ts` は base64url 変換のみ。**暗号処理は書かない**。
- `PreventLastMfaRemoval` middleware で最後の MFA 手段の削除を拒否。
- `AuditPasskeyEvents` で登録・削除・認証を監査（**credential 本体は記録しない**）。

**実装中に発見・修正した不具合**: Vue が credential を最上位に展開して送信していたが、
`laravel/passkeys` は `credential` 配下を期待する。テストで検出し 3 画面すべて修正。

### 2026-09-08 — Task 5.5-4 / 5.5-5（SMS 基盤・電話番号確認）: ✅ 完了

- `SmsSender` interface + `LogSmsSender`（local）+ `FakeSmsSender`（testing）。**実 provider へは接続しない。**
- `mfa_sms_challenges`（`000025`）/ `staff.phone` `phone_hmac` `phone_verified_at`（`000024`）。
- `SmsOtpService`: CSPRNG 6 桁 / TTL 5 分 / `Hash::make` 保存 / 一回使用 / 試行 5 回 /
  再送 60 秒・5 回/時 / IP 制限 / 監査。**平文 OTP・平文電話番号は DB にもログにも監査にも残さない。**
- 電話番号の登録・変更は `password.confirm` + 新番号への OTP 検証を必須にした
  （旧番号 → 新番号を即 verified にしない）。

### 2026-09-08 — Task 5.5-6 / 5.5-7（Recovery / TOTP 移行 / 再認証）: ✅ 完了

- Recovery Code は Fortify 標準を維持（暗号化保存・再生成で旧無効）。テストで平文非保存を確認。
- TOTP は削除せず「代替」として維持。TOTP のみのユーザーは移行期間中そのままログイン可能
  （`MfaPolicy` が満たすと判定）。UI で Passkey 登録を promote。
- 再認証: Fortify の passkey confirm が `session()->passwordConfirmed()` を立てるため、
  **機微操作（返金など）のルート定義は一切変更せずに** Passkey 再認証が使える。
  `password.confirm` は fallback として維持。

### 2026-09-08 — Task 5.5-8 / 5.5-9（テスト・検証）: ✅ 完了

- `MfaPolicyTest`（15）/ `SmsOtpTest`（13）/ `MfaSecurityTest`（17）/ `MfaNonRegressionTest`（5）= **50 件追加**。
- 最終: `migrate:fresh --seed` ✅ / **471 passed / 2683 assertions** ✅ / `npm run build` ✅ /
  `composer audit` ✅ / `npm audit` ✅ / 各種 secrets スキャン ✅。

**WebAuthn のセレモニー自体（challenge / origin / RP ID / 署名検証）は `laravel/passkeys` の責務**であり、
実認証器が必要なためアプリ側テストでは再現しない。アプリ側は
「不正な credential が 422 で拒否される」「ルートの認可・再認証・所有権」「最後の手段の削除拒否」を検証している。
