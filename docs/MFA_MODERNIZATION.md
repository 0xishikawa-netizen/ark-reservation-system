# MFA Modernization — Passkey 中心の認証への移行（Phase 5.5 候補）

> **本書は調査・設計のみ。Phase 5（Stripe 単発決済）では実装しない。**
> 実装はユーザーの明示許可後、Phase 5.5 として行う。
> 調査日: 2026-09-08 / 調査者: Claude Opus 5 / 対象: `ark-reservation-system`

## 0. 結論（先に要点）

1. **サードパーティの WebAuthn ライブラリは不要。** 本リポジトリが既に依存している
   `laravel/fortify ^1.0` が **`laravel/passkeys` v0.2.1 を同梱**しており、
   `config/fortify.php` には既に `passkeys` 設定ブロックが存在する（Phase 1 が意図的に無効化しただけ）。
2. Fortify は **passkey による再認証（`password.confirm` の代替）ルートも標準提供**している。
   これは Phase 5 の返金操作の再認証を Phase 5.5 で強化できることを意味する。
3. **SMS OTP は「国内直収接続」の国内事業者を推奨**。到達率が Phase 5.5 の成否を決める。
   Twilio は運用・実装が容易だが国際経路のため到達率で劣る場合がある。
4. **現行 TOTP は Phase 5 の決済セキュリティ上の問題にはならない。** よって Phase 5 を止める理由はない。
5. ただし **`EnsureStaffTwoFactor` の判定条件（`two_factor_confirmed_at != null`）は
   Passkey 導入時に必ず破綻する**ため、Phase 5.5 の最初の修正対象。

---

## 1. 現状（リポジトリ実測）

| 項目 | 現状 |
|---|---|
| 認証基盤 | Laravel Fortify ^1.0 + spatie/laravel-permission。**web guard 1 つ** |
| スタッフ MFA | TOTP 必須。`Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])` |
| MFA 強制 | `app/Http/Middleware/EnsureStaffTwoFactor.php`（`/admin` グループ） |
| 判定条件 | `users.two_factor_confirmed_at != null` |
| Recovery Code | Fortify 標準（`two_factor_recovery_codes`、暗号化 cast） |
| 機微操作の再認証 | `password.confirm` middleware（返金・回数券付与・スタッフ管理・設定変更） |
| 顧客 MFA | 課さない（PLAN §12 のとおり。変更しない） |
| Passkey | **`Features::passkeys()` がコメントアウトされ無効**（Phase 1 の判断） |

`config/fortify.php` の該当箇所（既に存在する）:

```php
'passkeys' => [
    'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
    'allowed_origins' => [config('app.url')],
    'timeout' => 60000,
],
// features:
// Passkeys（WebAuthn）は Phase 1 では有効化しない
```

---

## 2. 推奨ライブラリ（調査 #1〜#3）

### 採用: `laravel/passkeys`（Fortify 同梱・第一党）

- 既に `vendor/laravel/passkeys` v0.2.1（2026-05-18 リリース）として**インストール済み**。
  `laravel/fortify ^1.0` の依存として入っているため、**追加の composer require は不要**。
- Laravel 公式（`github.com/laravel/passkeys-server`）。MIT。
- Fortify がルート・コントローラ・レート制限まで面倒を見るため、**独自の WebAuthn 実装を書かない**
  （ユーザー指示「独自暗号実装禁止」を自動的に満たす）。

Fortify が提供するルート（実測）:

| ルート名 | パス | 用途 |
|---|---|---|
| `passkey.login-options` | `GET /passkeys/login/options` | 認証チャレンジ発行 |
| `passkey.login` | `POST /passkeys/login` | Passkey ログイン |
| `passkey.confirm-options` | `GET /passkeys/confirm/options` | **再認証**チャレンジ |
| `passkey.confirm` | `POST /passkeys/confirm` | **再認証**（`password.confirm` の代替） |
| `passkey.registration-options` | `GET /user/passkeys/options` | 登録チャレンジ |
| `passkey.store` | `POST /user/passkeys` | Passkey 登録 |
| `passkey.destroy` | `DELETE /user/passkeys/{passkey}` | Passkey 削除 |

登録・削除は既定で `password.confirm` 配下（`fortify-options.passkeys.confirmPassword`）。

### 不採用（理由付き）

| 候補 | 判断 |
|---|---|
| `laragear/webauthn` | 良質だが、公式 Fortify 統合が既にあるため二重依存になる |
| `web-auth/webauthn-lib`（Spomky-Labs） | 低レベル。カスタムセレモニーが要るときのみ。本件は不要 |
| 外部 IdP（OIDC ホスト型） | 単一店舗・1 人運用に対して過剰。ベンダーロックイン |

### WebAuthn セキュリティ要件の充足（調査 #1）

`laravel/passkeys` 側で担保される想定（**Phase 5.5 でテストにより実証すること**）:

- challenge 検証 / origin 検証（`allowed_origins`）/ RP ID 検証（`relying_party_id`）
- replay 防止（challenge の一回性）/ credential public key 保存 / sign counter
- phishing resistance は WebAuthn の origin バインドにより本質的に成立

**本番の前提**: HTTPS 必須。`relying_party_id` は `config('app.url')` のホストから導出されるため、
**`member.ark-conditioning.com` と Staging の `stg-member...` で RP ID が変わる**。
→ **Passkey は環境ごとに別物になる**（Staging で登録した Passkey は本番で使えない）。運用手順書に明記が必要。

---

## 3. DB 設計（調査 #4 / #9 / #17）

### 3-1. `passkeys` テーブル（`laravel/passkeys` 同梱 migration。そのまま使う）

| 列 | 型 |
|---|---|
| id | bigint PK |
| user_id | FK users cascadeOnDelete |
| name | string（デバイス名。「Tatsuya の MacBook」等） |
| credential_id | string **UNIQUE** |
| credential | json（公開鍵・sign counter 等） |
| last_used_at | timestamp null |
| timestamps | |

> 注: PLAN §6 は「JSON を多用しない」方針だが、これは**ベンダー同梱スキーマ**であり
> 1 ユーザーあたり数行に留まる。容量リスクは無視できる。**変更せずそのまま採用**する。

### 3-2. SMS OTP 用（新規・Phase 5.5）

**OTP は DB に平文で保存しない。** 以下 2 案のうち **案 A を推奨**。

**案 A（推奨）: 専用テーブル `mfa_sms_challenges`**

| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| user_id | FK users cascadeOnDelete | |
| code_hash | varchar(255) | **`Hash::make()`（argon2id）**。平文を保存しない |
| phone_hmac | char(64) | 送信先の確認用。平文電話番号を持たない（`customers.phone_hmac` と同方式） |
| expires_at | datetime | TTL 5 分（§4） |
| consumed_at | datetime null | 一回使用で無効 |
| attempts | tinyint default 0 | verify 試行回数 |
| created_ip | varchar(45) null | |
| timestamps | | |

index: `(user_id, expires_at)`。`model:prune` 対象（保持 7 日）。

- 監査可能性（「いつ誰に OTP を送ったか」）が残るため、SMS 費用の不正利用調査ができる。
- `cache` ドライバ案（案 B）は速いが、**キャッシュ破棄で監査証跡が消える**ため金銭系システムでは劣る。

**電話番号の保持**: `users` にスタッフ用電話番号が無い。Phase 5.5 で
`staff.phone`（`encrypted` cast）+ `staff.phone_verified_at` + `staff.phone_hmac` を追加する
（`customers` の既存パターン `PiiHasher` を再利用）。

### 3-3. `users` の変更（調査 #17）

- `two_factor_secret` / `two_factor_recovery_codes` / `two_factor_confirmed_at` は**削除しない**（移行期の互換）。
- 追加候補: `mfa_policy_satisfied_at`（任意。判定をキャッシュしたい場合のみ。**初期は追加しない**）。

---

## 4. SMS OTP 設計（調査 #9〜#11）

| 項目 | 値 |
|---|---|
| 桁数 | 6 桁 |
| 生成 | `random_int(0, 999999)`（CSPRNG）をゼロ埋め。`rand()` / `mt_rand()` 禁止 |
| TTL | **5 分**（PLAN の他の期限と同様 config 化。ハードコード禁止） |
| 保存 | `Hash::make()`。**平文保存・ログ出力を絶対に禁止** |
| 一回性 | `consumed_at` を立てる。検証成功後は再利用不可 |
| verify 試行制限 | 同一 challenge に **5 回**まで。超過で challenge 失効 |
| resend 制限 | 60 秒に 1 回 / 1 時間に 5 回 / 1 日に 20 回（`RateLimiter`） |
| brute force | ユーザー単位 + IP 単位の二重 limiter |
| 送信先 | **`phone_verified_at` が非 null の番号のみ**。未確認番号へ送らない |
| 電話番号変更 | 変更時に `phone_verified_at` を null にし、**再確認するまで SMS OTP を MFA 手段として使えなくする**。変更操作自体を `password.confirm`（将来は passkey 再認証）+ 監査対象にする |
| audit | `mfa.sms.sent` / `mfa.sms.verified` / `mfa.sms.failed`。**コード本体は記録しない** |

**禁止事項（ユーザー指示の再確認）**: 平文 DB 保存 / ログ出力 / 期限なし / resend 無制限 /
verify 無制限 / SMS だけでのパスワードリセット / 未確認番号への送信 / local だけ MFA 完全バイパス。

> `local` 環境では SMS を実送信せず **ログドライバ（Mailpit 相当の擬似送信）** にする。
> ただし **MFA 自体はバイパスしない**（コードは出すが検証はする）。

---

## 5. SMS Provider 比較（調査 #8）

| 候補 | 国内到達率 | 価格（日本向け目安） | Laravel 実装 | 運用 | ロックイン |
|---|---|---|---|---|---|
| **国内直収系**（空電プッシュ / KDDI Message Cast 等） | ◎ 4 キャリア直収 | 中〜高（通数課金） | HTTP API。自作ラッパ | ◎ 日本語サポート | 中 |
| **Twilio**（ソフトバンク取扱） | ○ | 約 $0.089/通（ロングコード, 2026-08 時点） | ◎ SDK・情報量最多 | ◎ 日本語 24/365（SB 経由） | 低（番号移行可） |
| **AWS SNS** | △ キャリア差あり | 約 $0.08/通 | ○ AWS SDK | ○ 既存 AWS 依存があれば | 中 |
| **Vonage** | △ | 中 | ○ | △ 日本語情報少 | 中 |

### 推奨

**第一候補: 国内直収接続の国内事業者**（例: 空電プッシュ）。
理由: SMS OTP は**到達しなければ管理者がログインできない**という可用性リスクそのもの。
国際経路（Twilio / SNS）は迷惑メッセージフィルタや経路品質で不達が起きうる。
本システムは**単一店舗・管理者少数**であり通数が極小のため、単価差は月数百円レベルで無視できる。
→ **単価より到達率を優先すべき**ケース。

**第二候補: Twilio（ソフトバンク経由）**。実装容易性と日本語サポートを重視する場合。

**決定は保留**（ユーザー判断事項）。`docs/OPEN_QUESTIONS.md` へ追記する。
**Phase 5.5 では Provider 非依存の `SmsSender` interface + `LogSmsSender`（local/testing）を先に作り、
具象 Provider を後から差し替え可能にする**（既存 `ExternalReservationGateway` と同じ手法）。

---

## 6. MFA ポリシーと middleware（調査 #5 / #16）

### 現行の問題

`EnsureStaffTwoFactor` は `two_factor_confirmed_at != null` のみを見る。
Passkey を登録しただけのユーザーは **TOTP 未設定 = MFA 未達**と判定され、
Passkey を標準にした瞬間に**全スタッフがロックアウトされる**。

### 変更案

判定を「**有効な MFA 手段を最低 1 つ持っているか**」へ一般化する。

```
hasSatisfiedMfa(User $u): bool
    = $u->passkeys()->exists()                       // 第一選択
   || $u->two_factor_confirmed_at !== null           // 既存 TOTP（移行期の互換）
   || ($u->staff?->phone_verified_at !== null)       // SMS OTP フォールバック
```

- 判定は `App\Domain\Auth\MfaPolicy` に集約し、middleware / UI / テストが同じ関数を使う。
- **SMS のみを唯一の MFA にしない**（ユーザー指示）。よって
  「SMS だけ」の状態は**猶予期間つきの警告**とし、Passkey 登録を促す。
  最終形では `passkeys OR totp` を必須、SMS は**フォールバック専用**（単独では満たさない）とするのが安全。
  → **この最終形を推奨**。移行完了後に切り替える。

### `password.confirm` との関係（調査 #15）

- **Phase 5 中は `password.confirm` を維持**（ユーザー指示。返金・回数券付与などに適用済み）。
- Phase 5.5 で `passkey.confirm` を用いた再認証へ**段階的に**置換可能。
  Fortify が `passkey.confirm-options` / `passkey.confirm` を提供するため自作不要。
- 置換時は「Passkey 未登録ユーザーは従来どおりパスワード再確認」へフォールバックする
  middleware（`reauth` エイリアス）を作り、ルート定義側は 1 箇所の変更で済むようにする。

---

## 7. TOTP からの移行（調査 #6 / #7 / #20）

**既存ユーザーをログイン不能にしない**ことが絶対条件。

```
Step 1  Features::passkeys() を有効化（TOTP はそのまま必須のまま）
Step 2  管理画面に「Passkey を登録」導線を追加。TOTP でログイン → Passkey 登録
Step 3  MfaPolicy を「passkey OR totp」に変更（この時点で Passkey 単独ログイン可）
Step 4  ログイン画面の第一選択を Passkey に。TOTP は「別の方法」へ格下げ
Step 5  SMS OTP をフォールバックとして追加（要 phone_verified_at）
Step 6  TOTP を「任意の予備手段」に降格。新規スタッフには TOTP を強制しない
```

- **Step 3 より前に TOTP を外さない。**
- 各 Step は独立してデプロイ可能・ロールバック可能にする。
- Step 4 以降で `two_factor_confirmed_at` ベースの分岐が残っていないかを grep で検証。

### Passkey 紛失時の復旧（調査 #13）

1. **Recovery Code**（Fortify 標準・維持）→ ログイン後に新しい Passkey を登録
2. Recovery Code も失った場合 → **他の admin による手動リセット**（`passkeys` 行削除 + 監査）
3. admin が 1 人しかいない状況を作らない運用ルール（`OPERATIONS.md` に追記）

> **単一管理者 + Passkey のみ**は自己ロックアウトの最大リスク。
> **Recovery Code の紙保管を必須**とし、admin は最低 2 名または Recovery Code 保管を運用条件にする。

### Recovery Code（調査 #12）

Fortify 標準を維持。要件（one-time / hashed・encrypted / 使用済み無効 / 再生成で旧無効 /
生成時のみ表示 / 監査）は Fortify 実装で充足。**Phase 5.5 でテストにより実証**する。

---

## 8. リスク（調査 #19）

| リスク | 深刻度 | 緩和 |
|---|---|---|
| **自己ロックアウト**（Passkey 紛失 + Recovery Code 紛失） | **高** | Recovery Code 紙保管必須 / admin 2 名 / 手動リセット手順を `OPERATIONS.md` へ |
| RP ID が環境ごとに異なる | 中 | Staging と本番で Passkey は別登録。手順書に明記 |
| `EnsureStaffTwoFactor` 変更漏れによる全員ロックアウト | **高** | `MfaPolicy` へ集約 + Feature テストで各組合せを検証。Step 3 の前後で必ずテスト |
| SMS 不達で管理者がログイン不能 | 中 | 国内直収 Provider / SMS を唯一の手段にしない |
| SMS 費用の不正利用（OTP 連打） | 中 | resend/verify の多層 rate limit + `mfa_sms_challenges` の監査 |
| ブラウザ/OS の Passkey 非対応 | 低 | TOTP・SMS のフォールバックを残す |

---

## 9. Phase 5.5 Task 分割案（調査 #20 / #18）

| Task | 内容 |
|---|---|
| 5.5-1 | `MfaPolicy` 抽出 + `EnsureStaffTwoFactor` を Policy 経由へ（**挙動を変えない**リファクタ + テスト） |
| 5.5-2 | `Features::passkeys()` 有効化 + `passkeys` migration + 管理画面の Passkey 登録/削除 UI |
| 5.5-3 | Passkey ログイン UI（第一選択化）。TOTP は「別の方法」 |
| 5.5-4 | `MfaPolicy` を「passkey OR totp」へ拡張 + 移行導線（TOTP ユーザーへの登録促し） |
| 5.5-5 | `staff.phone` + `phone_verified_at` + `phone_hmac` migration + 電話番号確認フロー |
| 5.5-6 | `SmsSender` interface + `LogSmsSender` + `mfa_sms_challenges` + OTP 発行/検証 + rate limit |
| 5.5-7 | 具象 SMS Provider 実装（**Provider 決定後**） |
| 5.5-8 | `reauth` middleware（passkey 再認証 / password.confirm フォールバック）へ機微操作を移行 |
| 5.5-9 | セキュリティテスト一式（下記 #18）+ 総合検証 |

### Feature / security テスト案（調査 #18）

- Passkey 登録・ログイン・削除の正常系
- **origin / RP ID 不一致の拒否**、challenge 再利用（replay）の拒否
- Recovery Code の一回性・再生成で旧コード無効
- OTP: TTL 超過拒否 / 一回性 / verify 試行超過で失効 / resend 制限 / 未確認番号へ送らない
- **OTP がログ・レスポンス・audit に出ない**（secrets scan）
- `MfaPolicy` の全組合せ（passkey のみ / totp のみ / sms のみ / なし）で `/admin` 到達可否
- 移行 Step ごとに既存 TOTP ユーザーがログインできること（**ロックアウト回帰テスト**）

---

## 10. Phase 5 への影響（STOP 条件の判定）

**現行 TOTP 方式が Phase 5 の決済セキュリティ上の重大な問題になるか → ならない。**

理由:
- 返金など機微操作は `refund.execute` + `password.confirm` + 監査で保護され、
  MFA 方式の違いはこの保護の強度を変えない。
- TOTP は phishing 耐性で Passkey に劣るが、**単一店舗・管理者少数・`/admin` の idle timeout あり**
  という条件下で Phase 5 を止めるほどのリスクではない。
- Passkey 化は**強化**であって、Phase 5 の前提条件ではない。

→ **Phase 5 は予定どおり続行。MFA は Phase 5.5 として分離**（ユーザー指示どおり）。

### Phase 6 の前に Phase 5.5 を実施すべきか → **すべき（推奨）**

理由:
1. Phase 6（Membership）は**継続課金**を扱う。定期課金の管理画面は単発決済より事故時の影響が大きく、
   管理者認証を先に強化しておく方が合理的。
2. Phase 5.5 は認証層の変更であり、**業務ロジックが増えるほど移行コストが上がる**（触る画面が増える）。
   Phase 6 の前が最も安く済む。
3. Phase 5 完了時点で `/admin` の機微操作（返金）が実運用に入るため、
   phishing 耐性のある Passkey の価値が実際に発生する。

---

## 11. OPEN QUESTIONS（`docs/OPEN_QUESTIONS.md` へ転記する項目）

1. SMS Provider の決定（国内直収 vs Twilio）。到達率の実測は契約後でないとできない。
2. 移行完了後の最終 MFA ポリシー: `passkey OR totp` 必須 + SMS はフォールバック専用、で確定してよいか。
3. admin を 2 名以上にする運用が可能か（自己ロックアウト対策）。不可なら Recovery Code の保管方法。
4. Staging と本番で Passkey が別登録になる点の運用受容。
5. スタッフ電話番号の収集・本人確認の業務手順（SMS OTP の前提）。

---

## 参考

- [laravel/passkeys — Packagist](https://packagist.org/packages/laravel/passkeys)
- [laragear/webauthn — Packagist](https://packagist.org/packages/laragear/webauthn)（不採用候補）
- [SMS送信APIおすすめ比較｜OTP認証・到達率・料金 — MCB FinTechカタログ](https://catalog.monex.co.jp/article/?p=47340)
- [【2026年版】SMS送信サービス比較｜料金相場・到達率 — MCB FinTechカタログ](https://catalog.monex.co.jp/article/?p=13702)
