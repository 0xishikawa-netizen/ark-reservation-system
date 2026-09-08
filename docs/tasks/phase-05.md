# Phase 5 — Stripe 単発カード決済（Test Mode）

設計正本: `docs/PLAN.md`（rev.5, §7 / §9 / §12 / §13 / §16 Phase 5）。本書はそれを Phase 5 に落としたタスク計画 + 実行ログ。
差異が生じたら **PLAN 優先**。ただし本書 §「PLAN からの逸脱」に記載した項目は、**安全性上の理由で PLAN を上書きする**（ユーザー承認済み）。

## baseline

- Phase 4 baseline commit: **`574ff78` "Phase 4 ticket pack baseline"**（remote なし・push なし）。
- Phase 5 の `git diff` レビュー基準は `574ff78`。
- Phase 5 の変更は commit しない。remote push もしない。

## 前提（変更禁止）

- `RESERVATION_AUTHORITY=local` / `EXTERNAL_RESERVATION_GATEWAY=null`。
- `ExternalReservationGateway` は `NullExternalReservationGateway` のまま。Peak Manager / SALON BOARD / Hot Pepper には接続しない。
- **Stripe は Test Mode のみ**。`sk_live_` / `pk_live_` は記述も使用も絶対禁止。
  `AppServiceProvider::assertNoStripeLiveKeys()`（Phase 1 実装済み）を壊さない。

## Phase 5 で実装しないもの（絶対）

Membership / `membership_plans` / `memberships` / `membership_usage_transactions`（Phase 6）/
Cashier の Subscription 業務 / **オンライン回数券購入フロー** / 顧客マイページ集約（Phase 7）/
`Admin/SystemStatus`（Phase 8）/ 実 Gateway（Phase 10）/ Reporting / LINE / multi-store / `store_id` /
WordPress 変更 / 本番 DB / 本番 Stripe / git remote 追加 / git push。

- **`ticket_products.stripe_price_id` / `ticket_wallets.payment_id` は Phase 5 でも追加しない。**
  PLAN §16 の Phase 5 定義は「Stripe 単発決済」であり、オンライン回数券購入を含まない。
  Phase 4 残課題 #1 / #2 は Phase 6 以降へ再送りする（本書で確定）。
  → 予約の単発カード決済を優先し、購入フローを先行実装しない。

## HTTP ステータス規約（Phase 3 / 4 と統一）

- 409 = リソース競合（slot 競合・二重決済開始・楽観ロック不一致）。
- 422 = 業務バリデーション（`ValidationException`。金額不正・reason 未入力・状態不整合など）。
- 402 は使わない（Stripe の decline は 422 + 再試行可能な画面表示で扱う）。

---

# 1. PLAN からの逸脱（**安全性上の理由。ユーザー承認済み**）

Phase 5 のレビューで、PLAN / DB_SCHEMA / `config/stripe.php` に**二重課金を招く設計欠陥**を検出した。
以下は PLAN より本書を優先する。Phase 5 完了時に PLAN / DB_SCHEMA へ反映する（ユーザー承認後）。

## 1-1. Idempotency-Key に retry 回数を入れない（**最重要**）

PLAN §7 / `config/stripe.php` の現行テンプレート:

```
pi-create:{reservation_id}:{attempt}     ← 危険。採用しない
refund:{payment_id}:{reason_hash}        ← 危険。採用しない
```

**問題**:
- `{attempt}` を key に含めると retry のたびに key が変わる。Stripe から見て「別の操作」になり、
  **PaymentIntent が複数生成され二重課金が起きる**。Idempotency-Key の目的を完全に破壊する。
- `{reason_hash}` は「同一 payment に同一理由で 2 回目の部分返金」を行うと key が衝突し、
  **2 回目の返金が黙って 1 回目の結果を返す**（返金されない）。逆に理由文字列を変えるだけで
  二重返金が通ってしまう。金額の正しさを理由文字列に依存させてはならない。

**採用する設計**:

`payment_operation_id`（UUID v4）を **ユーザーが論理的な決済試行を開始した時に 1 度だけ生成**し、
その操作 ID から全操作の key を安定導出する。retry では**同じ key を再利用**する。

```
pi-create:{payment_operation_id}
pi-capture:{payment_operation_id}
pi-cancel:{payment_operation_id}
refund:{refund_operation_id}
```

- `payment_operation_id` は `payments.payment_operation_id`（UNIQUE）に永続化する。
  **プロセス内で生成し直さない。必ず DB の値を読む。**
- `refund_operation_id` は `payment_refunds.refund_operation_id`（UNIQUE）に永続化する。
  返金は payment とは別の論理操作なので独立した operation ID を持つ。
- **新しい `payment_operation_id` を生成してよいのは、顧客が明示的に新しい決済試行を開始した時だけ**
  （前の試行が failed / voided / expired で終了している時のみ）。
  画面リロード・ネットワーク再送・ジョブ retry では絶対に生成しない。

## 1-2. `payments.status` に `voided` を追加

DB_SCHEMA の `payments.status enum(pending,authorized,succeeded,failed,refunded,partially_refunded)` は
同じ DB_SCHEMA §5 の State Machine が要求する `authorized → voided` を表現できない。**`voided` を追加する**。

## 1-3. `payments.idempotency_key varchar(100) UNIQUE` を採用しない

1 カラムでは create / capture / cancel の 3 種の key を保持できない。
代わりに `payment_operation_id`（UNIQUE）を持ち、key は §1-1 のテンプレートで導出する。

## 1-4. Cashier は Phase 5 では導入しない

PLAN §16 Phase 5 は「Cashier」と記載するが、Cashier の導入は
`subscriptions` / `subscription_items` migration（= Phase 6 の成果物）を持ち込む。
Phase 5 に subscription は不要であり、「単発決済ロジックを Cashier の Subscription モデルへ寄せない」
というユーザー指示とも整合しない。

→ **Phase 5 は `stripe/stripe-php` のみ導入**。`customers.stripe_customer_id`（Phase 1 で作成済み）は自前管理。
Cashier は Phase 6（Membership + サブスク課金）で導入する。

## 1-5. `webhook_events` に payload を保存しない（PLAN 準拠の再確認）

PLAN §9 / DB_SCHEMA §2 のとおり **payload カラムを作らない**。
イベント再処理は「Stripe から現在オブジェクトを再取得」で行う（§5-3）。
raw payload の暗号化 off-DB 保管（PLAN §9 C）は Phase 5 では**実装しない**（任意項目のため）。

---

# 2. 決済モデル（Phase 5 の中核）

## 2-1. payment_method の分離（**Ticket 非退行の要**）

| `reservations.payment_method` | Phase 5 の扱い | Stripe | Ticket 台帳 |
|---|---|---|---|
| `single` | **カード決済（本 Phase の対象）** | PaymentIntent を作る | **HOLD を作らない** |
| `ticket` | Phase 4 のまま（非退行） | **PaymentIntent を作らない** | RESERVE_HOLD を作る |
| `onsite` / `unpaid` | Phase 3 のまま | 作らない | 作らない |
| `membership` | Phase 6 | 作らない | 作らない |

- この分岐は `ReservationService` / `ReservationCheckoutSaga` の中だけに置く。
  Controller / Vue / FormRequest に決済方式の分岐ロジックを書かない。
- **テストで両方向を保証する**（card 予約で ticket_transactions が 0 件 / ticket 予約で Stripe gateway が未呼出）。

## 2-2. reservation と payment の状態対応

```
reservations.status         reservations.payment_status    payments.status   顧客への表示
──────────────────────────────────────────────────────────────────────────────────────
pending_payment             pending_payment                pending           「お支払い手続き中」
pending_payment             authorized                     authorized        「予約確保中（決済確定処理中）」
confirmed                   paid                           succeeded         「予約完了・決済完了」
expired                     voided                         voided            「期限切れ・与信を取消しました」
pending_payment             failed → unpaid                failed            「決済に失敗しました（再試行可）」
confirmed                   refunded / partially_refunded  refunded / …      「返金済み」
```

- **`authorized` は決済完了ではない。** 顧客画面に「決済完了」「お支払いありがとうございました」を出さない。
  `authorized` 中の表示は「予約確保中」「決済確定処理中」に限定する。
- **`paid` / `succeeded` になるのは capture 成功後だけ。**

## 2-3. payments state machine（`app/Domain/Payment/PaymentStateMachine.php`）

```
pending    → authorized, failed, voided
authorized → succeeded, voided, failed
succeeded  → refunded, partially_refunded
partially_refunded → refunded, partially_refunded※
voided     → （終端）
failed     → （終端）
refunded   → （終端）
```

※ `partially_refunded → partially_refunded` は同一状態遷移を許さない `StateMachine` 基底の制約に当たるため、
**partial 返金の 2 回目以降は status を変えず `refunded_amount` のみ加算**し、
全額に達した時のみ `partially_refunded → refunded` を遷移させる。

- 遷移は必ず `app/Support/StateMachine` 経由（Phase 1 基盤）。直接 `status` を代入しない。
- **巻き戻し遷移は定義しない。** 古い webhook が来ても後退できない（§5-3）。

---

# 3. DB スキーマ（Task 5-1）

migration は `2026_09_08_0000XX_*` の連番を Phase 4（…000019）の続きから振る。

## 3-1. `payments`

| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| customer_id | FK `customers.user_id` restrictOnDelete | |
| reservation_id | FK `reservations` null restrictOnDelete | Phase 5 は常に非 null（`kind=single`） |
| kind | varchar(20) | Phase 5 は `single` のみ。`ticket_purchase` / `membership_invoice` は将来用（実装しない） |
| provider | varchar(20) default `stripe` | |
| payment_operation_id | char(36) **UNIQUE** | UUID v4。全 Idempotency-Key の安定な根（§1-1） |
| amount | int unsigned | 円（JPY はゼロ十進通貨。小数を持たない） |
| currency | char(3) default `jpy` | |
| status | varchar(24) | §2-3 |
| capture_method | varchar(10) default `manual` | |
| stripe_payment_intent_id | varchar(40) null **UNIQUE** | |
| stripe_charge_id | varchar(40) null | capture 後に記録 |
| authorized_at | datetime null | |
| paid_at | datetime null | capture 成功時のみ |
| voided_at | datetime null | |
| refunded_amount | int unsigned default 0 | キャッシュ。正本は `payment_refunds` の SUM |
| failure_code | varchar(50) null | Stripe の `code` のみ（要約） |
| failure_message | varchar(255) null | **顧客向けの生メッセージをそのまま画面に出さない** |
| needs_attention | bool default false | 孤立決済・曖昧状態の「要対応」（PLAN §16 Phase 5） |
| last_synced_at | datetime null | reconcile / webhook sync の最終突合時刻 |
| created_by | FK `users` null | |
| timestamps | | |

index: `(reservation_id)`, `(status)`, `(needs_attention)`, `(stripe_payment_intent_id)` は UNIQUE で兼ねる

- **カード PAN / CVC / client_secret / Stripe レスポンス全文を保存しない。**
- 「1 予約に同時に有効な payment は 1 つ」は MySQL の部分 UNIQUE では表現できないため、
  **`reservations` 行を `lockForUpdate()` してから payments を作る**ことで直列化する（§4-1 TX1）。
  併せて「`status in (pending, authorized, succeeded)` の payment が既にあれば 409」を検査する。

## 3-2. `payment_refunds`

| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| payment_id | FK `payments` restrictOnDelete | |
| refund_operation_id | char(36) **UNIQUE** | UUID v4（§1-1） |
| amount | int unsigned | |
| reason | varchar(255) | **必須**（機微操作。PLAN §12） |
| status | varchar(16) | `pending` / `succeeded` / `failed` |
| stripe_refund_id | varchar(40) null | |
| failure_code | varchar(50) null | |
| failure_message | varchar(255) null | |
| created_by | FK `users` | 必須 |
| timestamps | | |

index: `(payment_id)`

- **`SUM(succeeded の amount) <= payments.amount` を必ず transaction 内で `FOR UPDATE` 検査**（二重返金防止）。

## 3-3. `webhook_events`

| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| stripe_event_id | varchar(64) **UNIQUE** | 冪等化の要 |
| type | varchar(60) | |
| api_version | varchar(20) null | |
| status | varchar(16) | `received` / `processed` / `ignored` / `failed` |
| related_type | varchar(60) null | 例 `payment` |
| related_id | varchar(40) null | |
| event_created_at | datetime null | Stripe の `event.created`。順序診断用（**判断には使わない**） |
| received_at | datetime | |
| processed_at | datetime null | |
| attempts | smallint unsigned default 0 | |
| error | varchar(500) null | |
| timestamps | | prune 用 |

index: `(status)`, `(type)`, `(received_at)`

- **payload カラムを作らない**（§1-5）。

## 3-4. `settings`（既存キーを使う。新規追加しない）

- `reservation.hold_minutes`（既定 `10` / int）は Phase 1 で seed 済み・**未使用**。
  Phase 5 でこれを `payment_expires_at` の算出に使う。**ハードコード禁止。**
- 値は `App\Support\Settings\Settings` 経由でのみ取得する。

---

# 4. 決済フロー（Saga）

## 4-1. 正常系（`RESERVATION_AUTHORITY=local` / manual capture）

**DB transaction と Stripe HTTP を厳密に分離する。**
`DB::transaction()` の中で Stripe を呼んだ時点で設計違反。レビューで必ず落とす。

```
[TX1] ローカル確定（Stripe を呼ばない）
      reservations 作成 status=pending_payment / payment_status=pending_payment
      payment_expires_at = now + settings('reservation.hold_minutes')
      reservation_resource_slots INSERT（UNIQUE で二重予約を DB 保証）
      payments 作成 status=pending / payment_operation_id=UUID（ここで1度だけ生成）
      ── commit ──

[HTTP] Stripe PaymentIntent create
      capture_method=manual / amount / currency / customer
      Idempotency-Key: pi-create:{payment_operation_id}
      metadata: reservation_id, payment_id, payment_operation_id

[TX2] payments.stripe_payment_intent_id を記録 ── commit ──
      （client_secret は DB に保存しない。応答で 1 回だけ Vue へ渡す）

[顧客] Payment Element で認証（3DS 含む）→ Stripe 側で authorization 成立

[HTTP] Stripe PaymentIntent retrieve（webhook 受信 or 顧客の戻りで起動）
      ★ 「client がそう言った」ではなく必ず Stripe の現在オブジェクトで確認する

[TX3] status pending → authorized / authorized_at 記録
      reservations.payment_status = authorized
      ★ reservations.status は pending_payment のまま（まだ予約完了ではない）
      ── commit ──

[ローカル処理] authority=local のため外部予約登録は無い（Null Gateway。呼ばない）

[HTTP] Stripe PaymentIntent capture
      Idempotency-Key: pi-capture:{payment_operation_id}

[TX4] payments status authorized → succeeded / paid_at / stripe_charge_id
      reservations.payment_status = paid
      reservations.status = pending_payment → confirmed
      payment_expires_at = null
      ── commit ──

[顧客] ここで初めて「予約完了・決済完了」
```

- TX1〜TX4 はそれぞれ**短い**。Stripe HTTP は必ずその外。
- 各 HTTP ステップは**同じ Idempotency-Key で安全に retry できる**こと。

## 4-2. 補償（Compensation）

| 発生 | 対応 |
|---|---|
| authorized のまま期限切れ | PaymentIntent **cancel（void）** → `voided` → reservation `expired` → slot 解放 |
| pending のまま期限切れ | PaymentIntent cancel（存在すれば）→ `voided`/`failed` → `expired` → slot 解放 |
| capture 済みで後段失敗 | **refund** → `refunded` → reservation は運用判断（Phase 5 は自動キャンセルしない・`needs_attention`） |
| authorization 失敗（decline） | `failed` → `payment_status=failed → unpaid` → 顧客は**新しい `payment_operation_id`** で再試行可 |

- **`authorized`（未 capture）は refund ではなく cancel/void を使う。`succeeded`（capture 済み）のみ refund。**
  この判断を Controller / Vue / 管理 UI に書かない。**`PaymentService::cancelOrRefund()` 相当に集約**する。

## 4-3. 曖昧結果（timeout）の扱い（**最重要**）

Stripe API の timeout / 接続エラーを **failure と決めつけない**。Stripe 側で成功している可能性がある。

- `PaymentGatewayTimeoutException`（曖昧）と `PaymentGatewayDeclinedException`（確定的失敗）を**別例外**にする。
- timeout 時:
  - **`failed` へ遷移させない**（状態は据え置き）。
  - `needs_attention = true` を立て、`failure_code = 'ambiguous_timeout'` 等の要約を残す。
  - 解決手段は 2 つ。どちらも安全:
    1. **同じ Idempotency-Key で retry** → Stripe が元の結果を返す（重複生成されない）。
    2. **`payments:reconcile`** で Stripe の現在オブジェクトを取得して突合。
- Stripe の 5xx / ネットワーク断も同じ扱い。4xx の decline は確定的失敗として `failed`。

---

# 5. Webhook（Task 5-5）

## 5-1. 受け口

- ルート: `POST /stripe/webhook`（`web` グループの外。**session / CSRF を通さない**）。
  `bootstrap/app.php` の `validateCsrfTokens(except: ['stripe/webhook'])` で除外する。
- **署名検証必須**（`STRIPE_WEBHOOK_SECRET`）。検証失敗は 400。
- **攻撃的な rate limit を掛けない**（Stripe の正常な retry を阻害しない）。
- 応答は速く返す（重い処理は Queue へ）。処理失敗時は 500 を返して Stripe に retry させる。

## 5-2. 冪等化

- `webhook_events.stripe_event_id` **UNIQUE**。
- 受信したらまず INSERT を試み、**一意制約違反＝再送**として 200 を返して処理をスキップする。
- 同一 event の同時 2 本（Stripe の並行再送）も UNIQUE で 1 本に収束すること（テスト必須）。

## 5-3. 順序逆転への耐性（**設計の要**）

**event の到着順・event の中身で state を決めない。**

- webhook ハンドラは「どの payment に関係するか」を特定するだけ。
- 実際の状態更新は **`SyncPaymentFromStripe`（Stripe から PaymentIntent を retrieve して現在状態で判断）** が行う。
  → これにより `succeeded` の後に `amount_capturable_updated` が届いても、
  retrieve した現在状態が `succeeded` なので**巻き戻らない**。
- state machine に後退遷移を定義しない（§2-3）ので、仮に古い状態を適用しようとしても
  `InvalidStateTransitionException` で弾かれる（二重の防御）。
- `event_created_at` は診断・ログ用。**分岐条件に使わない。**

## 5-4. 対象イベント（Phase 5）

`payment_intent.succeeded` / `payment_intent.amount_capturable_updated` / `payment_intent.payment_failed` /
`payment_intent.canceled` / `charge.refunded`
（`invoice.*` / `customer.subscription.*` は Phase 6。`config/stripe.php` の `handled_events` に残っていてよいが**処理しない**。）

## 5-5. ログ

- **client_secret / カード情報 / payload 全文 / PII をログへ出さない。**
- 記録するのは event id / type / 対象 payment id / 結果のみ。

---

# 6. セキュリティ

## 6-1. CSP（**現状のままでは Payment Element が動かない**）

`app/Http/Middleware/SecurityHeaders.php` の現行 CSP は Stripe を全面ブロックする:

- `script-src 'self'` → `https://js.stripe.com` が読めない
- `frame-src` 未定義 → `default-src 'self'` に落ちて **Stripe の iframe がブロックされる**
- `connect-src 'self'` → `https://api.stripe.com` へ到達できない
- `Permissions-Policy: payment=()` → Payment Element の決済 API を封じる

**修正（顧客側ページのみ緩和。`/admin` は締めたまま）**:

| ディレクティブ | 追加 |
|---|---|
| `script-src` | `https://js.stripe.com` |
| `frame-src` | `https://js.stripe.com https://hooks.stripe.com` |
| `connect-src` | `https://api.stripe.com` |
| `Permissions-Policy` | `payment=(self)`（camera / microphone / geolocation は `()` のまま） |

**維持するもの（絶対に緩めない）**:
- `frame-ancestors 'none'`（他サイトから当アプリを埋め込ませない。Stripe の iframe とは無関係）
- `/admin` の `X-Frame-Options: DENY`
- `object-src 'none'` / `base-uri 'self'` / `form-action 'self'`

- 追加は**必要な最小ドメインのみ**。ワイルドカード（`https://*.stripe.com`）は使わない。
- local 環境の Vite 用緩和はそのまま残す。

## 6-2. 秘密情報

- **Vue に渡してよいのは publishable key（`pk_test_…`）と PaymentIntent の `client_secret` のみ。**
- `STRIPE_SECRET` を Inertia props / JS バンドル / ログへ**絶対に出さない**。
- `client_secret` は **DB に保存しない・ログに出さない**。応答で 1 回渡すだけ。
- `HandleInertiaRequests` で global share する場合も secret を含めない。

## 6-3. 認可

| 操作 | 要件 |
|---|---|
| 顧客の決済開始 / 再試行 | 本人の予約のみ（Policy）。他人の payment に触れない |
| 管理 payment 閲覧 | `admin.access` + `reservations.view` 相当 |
| **返金実行** | **manager 以上 + `refund.execute`（Phase 1 で seed 済み）+ `password.confirm` + reason 必須 + 監査** |
| capture / cancel の管理操作 | `reservations.manage` + 監査 |

- `refund.execute` は既に admin / manager に付与済み（`RolePermissionSeeder`）。**新規 permission は作らない。**
- 返金ルートには必ず `password.confirm` middleware を付ける（Phase 4 の `ticket.grant` と同じ形）。

## 6-4. 監査（`audit_logs`。要約 1 行・スナップショットなし）

`payment.created` / `payment.authorized` / `payment.captured` / `payment.canceled` /
`payment.failed` / `payment.refunded` / `payment.expired` / `payment.reconciled`

- summary に **PII / client_secret / raw payload / カード情報を入れない**。
  金額・payment id・reservation id・理由の要約のみ。

---

# 7. 期限切れ（Task 5-3）

- コマンド: `payments:expire`（PLAN §14 の `reservations:expire-pending` と同一目的。
  Phase 5 では決済を伴うため `payments:expire` に統一し、PLAN 側へ後で反映する）。
- Scheduler: 毎分 `withoutOverlapping()`。
- 対象: `reservations.status = pending_payment` かつ `payment_expires_at <= now()`。
- **冪等**であること。同じ予約を複数回処理しても:
  - 二重 void しない（既に `voided` なら skip）
  - 二重 slot 解放しない（削除は冪等）
  - 二重 audit を出さない
- 手順（**Stripe HTTP は transaction の外**）:
  1. 対象を取得（ロックは短く）
  2. payment が `pending`/`authorized` なら **Stripe cancel**（`pi-cancel:{payment_operation_id}`）
  3. [TX] payments `voided` / reservations `expired` / slot 削除 / ticket HOLD があれば解放
- **capture 済み（`succeeded`）の予約は expire しない。** 期限処理の対象外とし、
  返金要否は人間判断（`needs_attention`）へ回す。自動返金しない。

---

# 8. reconciliation（Task 5-7）

- コマンド: `payments:reconcile`。**既定 read-only**。
- 検出する矛盾（最低限）:
  - local `payments.status` と Stripe PaymentIntent の現在 status
  - captured amount の不一致
  - refunded amount と `payment_refunds` SUM / `payments.refunded_amount` の不一致
  - `reservations.payment_status` と `payments.status` の不整合
  - `pending_payment` なのに `payment_expires_at` を過ぎている滞留
  - `needs_attention` の未解決件数
- **差異があれば非 zero exit**（CI / 運用監視で拾えるように）。
- `--repair` を**明示指定した時だけ**、安全な派生状態のみ修復:
  - 許可: `payments.refunded_amount` 再計算 / `reservations.payment_status` の追従 /
    `last_synced_at` 更新 / `needs_attention` の解除
  - **禁止: Stripe へ課金・capture・返金・cancel する repair。** 金銭操作は別の明示 Action のみ。
- `stripe:replay {event_id}`（PLAN §9 A）も実装する。Stripe から event を取得して**同じ冪等経路**へ流す。
  約 30 日の Stripe 保持期間に依存する短期手段であり、**本命は `payments:reconcile`**。

---

# 9. Task 一覧

Task 単位のユーザー承認待ちは不要。各 Task 完了ごとに Claude（Opus 5）が `git diff 574ff78` をレビューする。

### Task 5-1 — 依存 / config / DB / Model / Enum / StateMachine
`stripe/stripe-php` 導入。`config/stripe.php` を**マージ更新**（§1-1 の key テンプレート / timeout / capture_method=manual 既定）。
`payments` / `payment_refunds` / `webhook_events` migration + Model + Factory + Enum + `PaymentStateMachine`。
`.env.example` へ Stripe Test 変数を追記（既存を壊さない）。

### Task 5-2 — StripeGateway 抽象 + PaymentService（idempotency / timeout）
`StripeGateway` interface（create / retrieve / capture / cancel / refund）+ `StripeApiGateway` + `FakeStripeGateway`（テスト用）。
`PaymentService` に create / capture / cancel / refund / syncFromStripe を集約。
安定 Idempotency-Key 導出。timeout と decline の例外分離。`needs_attention`。

### Task 5-3 — ReservationCheckoutSaga + pending_payment + payments:expire
§4-1 の TX/HTTP 分離を実装。`ReservationService` に card 経路を追加（ticket 経路は非退行）。
`payments:expire` + scheduler（毎分・冪等）。

### Task 5-4 — Payment Element 顧客 UI + CSP
`/reserve` の支払い方法にカードを追加。Payment Element 画面・`authorized`「予約確保中」表示・
`paid` で初めて「決済完了」。再試行・期限切れ・cancel 結果の表示。支払い履歴。
`SecurityHeaders` の CSP 修正（§6-1）。

### Task 5-5 — Webhook（署名 / 冪等 / 順序耐性）
`POST /stripe/webhook` + 署名検証 + `webhook_events` UNIQUE 冪等化 + `SyncPaymentFromStripe` 経由の状態更新。
CSRF 除外。rate limit を掛けない。

### Task 5-6 — capture / cancel / refund + 管理 UI + reauth + audit
`PaymentService::cancelOrRefund()`（authorized→cancel / succeeded→refund の判断を集約）。
管理 payment 一覧・詳細・返金操作（manager 以上 + `password.confirm` + reason 必須）。二重返金防止。監査。

### Task 5-7 — payments:reconcile + stripe:replay
§8 のとおり。read-only 既定 / 差異で非 zero exit / `--repair` は派生状態のみ。

### Task 5-8 — テスト（Stripe Test Mode / Fake gateway）
§10 の 18 項目を網羅。

### Task 5-9 — Phase 5 総合検証
`migrate:fresh --seed` / `npm run build` / `artisan test` / `composer audit` / `npm audit` /
secrets scan / production connection scan / Stripe Live 混入確認 / Phase 6+ 先行実装なし。

---

# 10. テスト要件（Task 5-8）

実 Stripe API は使わない（`FakeStripeGateway` + Stripe 公式 test fixture 相当）。**Live API は絶対禁止。**

| # | 内容 | 期待 |
|---|---|---|
| 1 | PaymentIntent create retry（同一 operation id） | PaymentIntent が 1 つだけ・payments 行が増えない |
| 2 | authorize retry | 二重請求なし |
| 3 | capture retry | 二重 capture なし・`paid_at` が動かない |
| 4 | cancel retry | 二重 cancel なし |
| 5 | refund retry（同一 refund_operation_id） | 二重返金なし・`refunded_amount` が増えない |
| 6 | webhook 同一 event 再送（並行含む） | `webhook_events` 1 行・二重処理なし |
| 7 | webhook 順序逆転（succeeded の後に古い event） | status が巻き戻らない |
| 8 | Stripe timeout | `failed` にならない・`needs_attention=true`・reconcile で解決 |
| 9 | `pending_payment` 期限切れ | reservation `expired` / slot 解放 / authorization cancel |
| 10 | capture 済みで期限処理 | expire 対象外・自動返金しない・`needs_attention` |
| 11 | ticket 予約 | Stripe gateway が**一度も呼ばれない** |
| 12 | card 予約 | `ticket_transactions` が 0 件（HOLD を作らない） |
| 13 | 顧客認可 | 他人の payment / client_secret に触れない |
| 14 | manager/admin 返金認可 | customer / staff は 403。`password.confirm` 必須 |
| 15 | CSRF | webhook は除外・他の決済 POST は保護される |
| 16 | CSP | Stripe ドメインが許可され、`frame-ancestors 'none'` と `/admin` の `X-Frame-Options: DENY` は維持 |
| 17 | secrets | `STRIPE_SECRET` / `client_secret` が Inertia props・HTML・ログに出ない |
| 18 | Stripe Live key 拒否 | `sk_live_` で起動例外（既存 `StripeLiveKeyGuardTest` を壊さない） |
| 19 | 過剰返金 | `SUM(refund) > payments.amount` を 422 で拒否（並行実行でも） |
| 20 | 二重決済開始 | 同一予約に有効な payment が 2 つできない（409） |

同時実行テストは Phase 4 と同じく **MySQL 実接続**で行う（`RefreshDatabase` + 実トランザクション）。

---

# 11. Codex への共通指示

毎回 `gpt-5.6-sol` を明示。作業前に必読:
`AGENTS.md` / `docs/PLAN.md` / `docs/ARCHITECTURE.md` / `docs/DB_SCHEMA.md` / **`docs/tasks/phase-05.md`（本書）**。

- **指示された Task 1 つだけ**を実装する。Phase 6 以降に手を出さない。
- `git add` / `git commit` しない。差分はワークツリーに残す。
- `docs/**` / `AGENTS.md` / `.env.example` の既存内容を消さない（追記・マージ）。
- **`DB::transaction()` の中で Stripe API を呼ばない。**
- Idempotency-Key に retry 回数・時刻・ランダム値を混ぜない（§1-1）。
- Controller / Vue から DB を直接更新しない。必ず Action / Service 経由。
- `declare(strict_types=1);` / TS `any` 回避 / `npm run build` の型エラー 0。
- Codex CLI の自動アップデートは禁止。

---

# 12. 実行ログ

（各 Task 完了時に追記）

### 2026-09-08 — Task 5-1（依存 / config / DB / Model / Enum / StateMachine）: ✅ 完了 修正0

- Codex 実装（gpt-5.6-sol）。Docker 非アクセスのため `composer require` と Sail 検証は Claude 側で実行。
- 追加: `payments` / `payment_refunds` / `webhook_events` migration（000020〜000022）、
  Model 3 / Enum 4（`App\Enums\Payment\*`）/ `PaymentStateMachine` / Factory 3 / テスト 2 ファイル。
- `config/stripe.php`: idempotency テンプレートを operation ID 方式へ置換（`{attempt}` / `{reason_hash}` を削除）、
  `capture_method` 既定を `manual` へ、HTTP timeout / retry 設定を追加。
- `stripe/stripe-php ^21.3` 導入（Cashier は導入せず。§1-4）。
- レビュー結果: 仕様一致。`payments.status` は `$fillable` から除外され StateMachine 経由のみ。
  後退遷移なし。`webhook_events` に payload カラムなし。Live キーなし。Stripe SDK 使用箇所ゼロ（Task 5-1 の範囲どおり）。
- 検証: `migrate:fresh --seed` ✅ / `artisan test` ✅ **359 passed / 2253 assertions**（Phase 4 の 344 から +15、退行 0）/ `npm run build` ✅ 型エラー 0。

---

# 13. Stripe 障害マトリクス（Phase 5 完了前レビュー）

**大原則: 「失敗を証明できない」ものはすべて曖昧（ambiguous）として扱い、`failed` へ確定させない。**
確定的失敗として `failed` にしてよいのは、Stripe がカード拒否を明示した場合（`PaymentGatewayDeclinedException`）だけ。

例外の対応:

| Stripe 側の事象 | 例外 | local の扱い |
|---|---|---|
| カード拒否（`CardException` / 4xx decline） | `PaymentGatewayDeclinedException` | **確定的失敗**。`failed` へ遷移してよい |
| 接続断・read timeout・DNS 失敗（`ApiConnectionException`） | `PaymentGatewayTimeoutException` | **曖昧**。状態据え置き + `needs_attention` |
| Stripe 5xx | `PaymentGatewayTimeoutException` | **曖昧**。同上 |
| その他の 4xx / 予期しない例外 | `PaymentGatewayException` | **曖昧扱い**（失敗を証明できないため）。同上 |

## 13-1. 操作別マトリクス

| # | 操作 | 障害発生点 | local 状態 | Stripe 状態 | retry 方法 | retrieve 方法 | reconcile | ユーザー表示 | 運用者対応 |
|---|---|---|---|---|---|---|---|---|---|
| 1 | PI create | 応答前に切断 | `pending` / PI 未記録 | PI 存在の可能性 | 同一 `pi-create:{payment_operation_id}` で再実行 → Stripe が元の PI を返す | 次回 `startCheckout` が retrieve へ分岐 | `--stripe` で PI 突合 | 「決済を開始できませんでした。再度お試しください」 | 通常不要（自動収束） |
| 2 | PI create | 応答後・DB commit 前 | `pending` / PI 未記録 | PI 存在 | 同上。**新しい operation ID を発行しない** | 同上 | 同上 | 同上 | 通常不要 |
| 3 | authorize | 顧客の 3DS 中に離脱 | `pending` | `requires_action` | 顧客が決済画面へ戻れば継続 | `syncFromStripe` | 期限超過は `stale_pending_payment` で検出 | 「お支払い手続き中」 | 期限切れで自動失効 |
| 4 | authorize | timeout | `pending` 据え置き | `requires_capture` の可能性 | webhook or `sync` | `syncFromStripe` | `capture_state_mismatch` | 「確認に時間がかかっています」 | 自動収束。残れば要対応 |
| 5 | capture | timeout | `authorized` 据え置き + `needs_attention` | capture 済みの可能性 | 同一 `pi-capture:{payment_operation_id}` | `syncFromStripe`（`amount_received` で判定） | `capture_state_mismatch` | 「予約確保中／確定処理中」。**「決済完了」と出さない** | 管理画面「Stripeと同期」 |
| 6 | capture | Stripe 5xx | 同上 | 同上 | 同上 | 同上 | 同上 | 同上 | 同上 |
| 7 | capture | 成功したが DB commit 失敗 | `authorized` のまま | `succeeded` | 同一 key の capture は再度同じ結果 | `syncFromStripe` が `succeeded` を取り込み前進 | `capture_state_mismatch` | 同上 | 自動収束 |
| 8 | cancel/void | timeout | `authorized` 据え置き + `needs_attention` | canceled の可能性 | 同一 `pi-cancel:{payment_operation_id}` | `syncFromStripe` | `capture_state_mismatch` | 「期限切れ・与信取消処理中」 | 次回の `payments:expire` が再試行 |
| 9 | cancel/void | 直前に capture 済み | `authorized` | `succeeded` | cancel せず **返金判断へ移行** | `syncFromStripe` | `paid_but_reservation_not_confirmed` | 「予約確保中」 | **`captured_after_expiry` で要対応。自動返金しない** |
| 10 | refund | timeout | `payment_refunds` = `pending` + `needs_attention` | 返金済みの可能性 | 同一 `refund:{refund_operation_id}` で `retryRefund()` | Stripe の refunded 額 | `stripe_refund_mismatch` | 管理画面のみ | `--stripe` で突合後 `retryRefund` |
| 11 | refund | 成功したが DB commit 失敗 | `pending` のまま | 返金済み | 同上（同一 key なので二重返金しない） | 同上 | 同上 | 同上 | 同上 |
| 12 | refund | 並行 2 リクエスト | `FOR UPDATE` + pending 込みの予約額計算で片方を 422 | 1 件のみ | — | — | `over_refund` は発生しない | 「返金可能額は N 円です」 | 不要 |
| 13 | webhook | 同一 event 再送 | `webhook_events.stripe_event_id` UNIQUE で 1 回に収束 | — | Stripe が自動再送 | — | — | 影響なし | 不要 |
| 14 | webhook | 順序逆転 | **event の中身で判断しない**。現在オブジェクトを retrieve して前進のみ | — | — | `syncFromStripe` | — | 影響なし | 不要 |
| 15 | webhook | 大幅遅延（中間状態を観測できず） | `pathTo()` で中間状態を辿って追いつく | — | — | 同上 | — | 影響なし | 不要 |
| 16 | webhook | 処理中に例外 | `webhook_events.status=failed` | — | 500 を返して Stripe に再送させる | — | — | 影響なし | 失敗イベントを確認 |
| 17 | webhook | 予約が既に失効 | **capture しない**（枠は解放済み） | `requires_capture` | — | — | — | 「期限切れ」 | 与信は `payments:expire` が void |
| 18 | 全般 | Stripe 到達不能が継続 | 状態据え置き。`payments:expire` は失効させず持ち越し | 不明 | 次回スケジュール実行 | — | `stripe_unreachable` | 現状維持 | Stripe 障害情報を確認 |

## 13-2. 「やらないこと」（安全側の明示）

- **timeout を `failed` に確定しない。**
- **capture 済みを `payments:expire` が自動返金しない。** 返金は必ず人間の明示操作（reason + reauth + 監査）。
- **`--repair` が Stripe へ capture / refund / cancel を行わない。** 派生状態（`refunded_amount` 等）のみ。
- **古いイベントで状態を巻き戻さない。** state machine に後退遷移を定義していないため構造的に不可能。
- **失効した予約の与信を capture しない。**

### 2026-09-08 — Task 5-2（Gateway 抽象 + PaymentService）: ✅ 完了 修正3（Opus 5 レビュー指摘）

- Codex 実装（gpt-5.6-sol）。**これが Codex への最後の委譲。以降は Opus 5 が自ら実装。**
- Opus 5 レビューでの是正:
  1. **失敗を証明できない `PaymentGatewayException`（4xx 非 decline / 想定外）が曖昧扱いされていなかった。**
     `failed` にはしていなかったが `needs_attention` も立たず reconcile から漏れる。→ 曖昧扱いへ統一。
  2. catch 順を「decline（確定）→ timeout（曖昧）→ base（曖昧）」へ整理。
  3. `cancelOrRefund()` が `pending` を扱えず期限切れ処理で使えなかった → `pending`/`authorized` とも cancel(void) へ。
- 検証: 全 green。

### 2026-09-08 — Task 5-3（pending_payment + Saga + payments:expire）: ✅ 完了（Opus 5 実装）

- `ReservationPaymentStateMachine`（前進のみ）／`ReservationService` にカード経路／`ReservationCheckoutSaga`／
  `CheckoutSession`／`payments:expire` + 毎分 scheduler。
- `settings('reservation.hold_minutes')`（既定 10・Phase 1 で seed 済み）を使用。ハードコードなし。
- 安全上の追加判断:
  - PaymentIntent 未作成の pending は Stripe を呼ばずローカルのみ void。
  - **失効・キャンセル済み予約の与信は capture しない**（解放済み枠への課金防止）。
  - capture 済みで期限到来は expire せず `captured_after_expiry` で要対応（自動返金しない）。
- テスト 12 件追加。`DatabaseMigrations` を採用し `DB::transactionLevel()===0` の検証を実効化。

### 2026-09-08 — Task 5-4（CSP + Payment Element 顧客 UI）: ✅ 完了（Opus 5 実装）

- `SecurityHeaders` を顧客／管理で分岐。顧客のみ Stripe 4 ドメインを最小限許可。
  `frame-ancestors 'none'` と `/admin` の `X-Frame-Options: DENY` は不変。管理側は `frame-src 'none'`。
- `Customer/Payments/Checkout.vue`（Payment Element・残り時間表示・生の Stripe エラー非表示）。
- 予約画面にカード選択を追加。

### 2026-09-08 — Task 5-5（Webhook）: ✅ 完了（Opus 5 実装）

- `POST /stripe/webhook`（署名検証必須・CSRF 除外・rate limit なし）。
- `StripeWebhookProcessor`: `stripe_event_id` UNIQUE で冪等化。
  **イベントの中身で状態を決めず、Stripe の現在オブジェクトを retrieve して前進のみ適用。**
- 実装中に発見・修正した順序バグ: 中間状態（authorized）を観測できないまま `succeeded` が届くと
  `pending_payment → paid` が state machine に無く予約側だけ進んでしまう。
  → `StateMachine::pathTo()` を追加し、定義済み遷移だけを辿って追いつくよう修正。
- テスト 10 件。

### 2026-09-08 — Task 5-6（capture/cancel/refund + 管理 UI + reauth）: ✅ 完了（Opus 5 実装）

- `Admin/PaymentController`（一覧・詳細・返金・手動同期）＋ `RefundPaymentRequest`。
- 返金は `can:refund.execute` + `password.confirm` + reason 必須 + 監査。新規 permission は作らず Phase 1 の seed を使用。
- 与信のみの決済を返金しようとすると 422（cancel を使う）。
- テスト 12 件（CSP / secrets / 認可 / 過剰返金 / 与信への返金拒否）。

### 2026-09-08 — Task 5-7（reconcile + replay）: ✅ 完了（Opus 5 実装）

- `payments:reconcile`（既定 read-only・差異で非 zero exit・`--repair` は派生状態のみ・
  **Stripe への金銭操作は行わない**）＋日次 scheduler。`stripe:replay {event_id}`。

### 2026-09-08 — Task 5-8（テスト）/ Task 5-9（総合検証）: ✅ 完了（Opus 5 実装）

- `StripeApiGatewayExceptionMappingTest`（11 件）を追加。
  **Codex 実装では未検証だった SDK 例外 → 曖昧/確定の対応付け**を網羅
  （connection reset / DNS / 5xx / decline / 400 / 401 / 429、生メッセージ非漏洩、key 非改変）。
- `CardCheckoutE2ETest`（3 件）: 予約→与信→capture→paid→顧客/管理画面、期限切れ→void、paid→管理者返金。
- 最終: `migrate:fresh --seed` ✅ / **421 passed / 2549 assertions** ✅ / `npm run build` ✅ /
  `composer audit` ✅ / `npm audit` ✅ / secrets・production・Live キー・Phase 6+ スキャン ✅。
