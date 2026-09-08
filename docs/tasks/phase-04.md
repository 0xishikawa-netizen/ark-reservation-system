# Phase 4 — 回数券 / Ticket Pack 機能

設計正本: `docs/PLAN.md`（rev.5, §6 / §7 / §10 / §12 / §13）。本書はそれを Phase 4 に落としたタスク計画 + 実行ログ。
差異が生じたら **PLAN 優先**。安全性・データ整合性に関わる差異は報告する。

## baseline

- Phase 3 baseline commit: **`77d03d3` "Phase 3 reservation baseline"**（remote なし・push なし）。
- Phase 4 の `git diff` レビュー基準は `77d03d3`。
- Phase 4 の変更は commit しない。remote push もしない。

## 前提（変更禁止）

- `RESERVATION_AUTHORITY=local` / `EXTERNAL_RESERVATION_GATEWAY=null` / Stripe 未接続。
- `ExternalReservationGateway` は `NullExternalReservationGateway` のまま。

## Phase 4 で実装しないもの（絶対）

Stripe / PaymentIntent / Payment Element / Stripe webhook / Cashier subscription 業務 /
Membership / membership_usage_transactions（Phase 6）/ Peak Manager 実 API / SALON BOARD 実 API /
Saga / Reporting / LINE / WordPress 変更 / 本番 DB / 本番接続 / multi-store / store_id / Phase 5+ 先行実装。

- **オンライン カード決済で PURCHASE を生成するフローは作らない**（Stripe 未接続）。
  `PURCHASE` は enum / migration / 台帳 type としては用意するが、Phase 4 で使うのは
  「テスト」および「PLAN で許可された管理操作（＝GRANT）」の範囲のみ。
  「決済していないのに購入済み」と見える UI は作らない。

## HTTP ステータス規約（Phase 3 と統一）

- 409 = リソース競合（残数不足で HOLD できない・dedupe 競合・楽観ロック不一致）。
- 422 = 業務バリデーション（`ValidationException`。不正な policy 値・reason 未入力・不正な数量など）。

---

## 残数の定義（PLAN §7 / §10。**二重減算しない**）

RESERVE 系 delta は既に負。したがって:

```
available = SUM(ticket_transactions.delta)          ← そのまま「今すぐ使える数」
held      = 未解消 RESERVE_HOLD の本数               ← 参考表示（既に available から引かれている）
total     = available + held
```

- **`SUM(delta) - held` は禁止。**
- `ticket_wallets.balance` は `available` の高速参照キャッシュ。**台帳追記と同一 DB transaction 内でのみ更新。** 正本は台帳。
- `tickets:reconcile` が `SUM(delta)` と `ticket_wallets.balance` の不一致を検出（通常は read-only）。

## ledger type / delta ルール（PLAN §10 を正とする）

| type | delta | 意味 / いつ |
|---|---|---|
| `PURCHASE` | `+N` | 購入（Phase 4 では未使用フロー。テスト / migration / 将来用）。wallet 生成・`expires_at` 設定 |
| `GRANT` | `+N` | 店舗付与（`ticket.grant` / reason 必須 / 監査）。wallet 生成・`expires_at` 設定 |
| `REVOKE` | `-N` | 付与取消（`ticket.grant` / reason 必須 / 監査）。available を超える REVOKE は 422 |
| `RESERVE_HOLD` | `-1` | 予約作成時。wallet `FOR UPDATE` → `available >= 1` 検査 → 追記。1 予約 = 1 wallet |
| `RESERVE_RELEASE` | `+1` | キャンセル / no_show(restore) / 来店直前。未解消 HOLD があるときのみ |
| `CONSUME` | `-1` | 来店確定（completed）/ no_show(consume)。直前に `RESERVE_RELEASE +1` を入れて履歴を明示（available 増減ゼロ） |
| `EXPIRE` | `-available` | 期限切れジョブ。**available 分のみ** 0 にする。`ticket_wallets.status=expired` |
| `ADJUST` | `±N` | 手動調整（`ticket.grant` / reason 必須 / 監査） |

- **完了フロー**: `RESERVE_RELEASE +1` → `CONSUME -1`（available 増減なし・held 解消・1 回消化）。
- **キャンセル**: `RESERVE_RELEASE +1` のみ（available が戻る）。
- すべての追記は `dedupe_key` UNIQUE で冪等。重複 INSERT は握って既存を返す（残数を動かさない）。

## dedupe_key 設計（PLAN §10 / DB_SCHEMA）

| 操作 | dedupe_key |
|---|---|
| GRANT | `grant:{wallet_id}` （1 GRANT = 1 wallet 生成なので wallet 単位で一意） |
| REVOKE | `revoke:{wallet_id}:{yyyymmddHHMMSS}` もしくは呼び出し側指定の操作 ID |
| RESERVE_HOLD | `resv:{reservation_id}:RESERVE_HOLD` |
| RESERVE_RELEASE | `resv:{reservation_id}:RESERVE_RELEASE` |
| CONSUME | `resv:{reservation_id}:CONSUME` |
| EXPIRE | `expire:{wallet_id}:{yyyymm}` |
| ADJUST | 呼び出し側指定の操作 ID（管理操作ごとに一意） |

- 同一予約に対し HOLD / RELEASE / CONSUME が **各 1 回だけ**成立する（retry で二重減算しない）。
- CONSUME 時、すでに HOLD で available から引かれている場合に **再び available を減らす二重減算を絶対に起こさない**
  （= 直前 `RESERVE_RELEASE +1` とセットで net zero）。

---

## FEFO（First Expired, First Out）

- 消化順: `expires_at` 昇順 → 同一なら `created_at` 昇順 → `id` 昇順（決定的な tie-breaker）。
- 期限切れ（`expires_at < today` / `status=expired`）の wallet は選ばない。
- 1 予約 = 1 wallet（複数 wallet 分割消化はしない）。HOLD 可能な wallet が無ければ 409。

---

## no_show / expiration ポリシー（**管理画面から設定変更可・機微設定**）

ユーザー追加要件。コード固定にせず、権限保持者が管理画面から変更できる。ただし安全性最優先。

### 設定キー（`settings` テーブル・Phase 1 `Settings` service 経由でのみ更新）

| key | 型 | 許可値 | 既定 |
|---|---|---|---|
| `ticket.no_show_policy` | string | `restore` \| `consume` | `restore` |
| `ticket.expiration_hold_policy` | string | `preserve_hold` | `preserve_hold` |

- `restore` = no_show 時 `RESERVE_RELEASE +1` のみ（回数を顧客へ返す）。
- `consume` = no_show 時 `RESERVE_RELEASE +1` → `CONSUME -1`（1 回分消化）。
- `expiration_hold_policy` は**危険な任意設定にしない**。Phase 4 は `preserve_hold` の 1 値のみ実装:
  - 期限到来時は **available 分のみ EXPIRE**。unresolved HOLD は維持。
  - 対応予約を勝手に cancel しない。HOLD を勝手に RELEASE しない。
  - **期限後に RELEASE された場合**（cancel / no_show restore 等）は、その **同一業務 transaction 内で必要な `EXPIRE` を追加し**、
    expired wallet の available が復活しないようにする。
    - 例: `GRANT +5` / `HOLD -1` → available=4, held=1。
      期限到来 `EXPIRE -4` → available=0, held=1。
      その後 cancel / no_show restore → `RESERVE_RELEASE +1` **と同一 transaction で** `EXPIRE -1` → available=0, held=0。

### 権限 `ticket_policy.manage`（新規）

| role | 初期割当 |
|---|---|
| admin | YES |
| manager | NO |
| staff | NO |
| customer | NO |

- 将来 manager へ許可する場合は Role/Permission 設定で明示変更できる構造でよいが、**Phase 4 既定は admin のみ**。
- 設定画面（GET 含む）そのものを `can:ticket_policy.manage` で保護（閲覧も保護）。
- 変更 POST/PATCH は `can:ticket_policy.manage` + `password.confirm` の両方を要求。
- 変更は Controller から直接 DB 更新せず **Action / Service 経由**。
- `Settings` service 側で型・許可値を検証し、**未知の値を保存できない**ようにする（不正値 → 422）。

### 監査（設定変更時に必ず `audit_logs` へ）

- action 例: `ticket.policy.no_show.changed` / `ticket.policy.expiration_hold.changed`。
- 記録: `actor_user_id` / setting key / old value / new value / reason / changed_at（`created_at`）。
- **reason 必須**（未入力 → 422）。PII は記録しない。
- `AuditLogger::log($action, $entity=null, $summary, $actor)` を利用。summary は
  `"{key}: {old} → {new}（理由: {reason}）"` 程度（PII なし）。

### UI `/admin/settings/tickets`（「回数券運用設定」）

- 無断キャンセル時の扱い: ○ 回数を返却する（restore） / ○ 1 回分を消化する（consume）
- 有効期限到来時の予約確保分: ○ 予約分は保持し、予約結果に応じて後処理する（preserve_hold・単一選択肢）
- 各選択肢に「顧客の回数券残数がどう変化するか」の説明を表示。
- 保存前に確認ダイアログ:
  「この変更は今後作成される回数券利用予約に適用されます。既存の HOLD 済み予約には遡及適用されません。」
- reason 入力欄（必須）。

---

## 既存予約への非遡及（**重要**）

管理者がポリシーを変更しても、**すでに HOLD 済みの予約の扱いは変わらない**。

- **HOLD を作成する時点で、その予約に適用する no_show ポリシーを確定して snapshot する。**
- snapshot 保持先: 新規テーブル **`ticket_reservation_usages`**（下記）。
- 予約時 `no_show_policy=restore` → 後日 admin が `consume` に変更 → 既存予約は **restore**、変更後の新規 HOLD 予約は **consume**。
- 汎用 policy versioning framework は作らない。Phase 4 に必要な最小構成のみ（= 1 カラムの snapshot）。

---

## Task 一覧

Task ごとのユーザー承認待ちは不要。Codex 実装 → Claude Code レビュー → 是正指示 → 再レビュー → 次 Task。
Phase 4 完了時に STOP。

### Task 4-1 — DB / Model / Enum
`ticket_products` / `ticket_wallets` / `ticket_transactions` / `ticket_reservation_usages` の migration・Model・Enum。
FK / index / `dedupe_key` UNIQUE / append-only 制約方針。Factory。

### Task 4-2 — TicketLedgerService（append-only ledger + dedupe_key）
台帳追記の唯一の入口。`available` / `held` / `total` 算出。`ticket_wallets.balance` を同一 transaction で更新。
`dedupe_key` 重複は握って既存返却。負残・不正 delta を弾く。GRANT / REVOKE / ADJUST。

### Task 4-3 — FEFO + HOLD / RELEASE / CONSUME + expiration + policy 適用
FEFO 選択。`hold()` / `release()` / `consume()`（予約単位・冪等）。`tickets:expire` command（冪等・EXPIRE 二重計上なし）。
`ticket.no_show_policy` snapshot を使った restore / consume 分岐。`preserve_hold` の「期限後 RELEASE 時 EXPIRE 追加」。

### Task 4-4 — Reservation との HOLD / RELEASE / CONSUME 連携
`ReservationService` の既存 transaction 内に ticket 連携を追加（`payment_method=ticket` のときのみ）。
明確な Service 境界（bridge / coordinator）。Controller / Vue に整合ロジックを持たせない。
create→HOLD（+ snapshot）/ cancel→RELEASE / completed→CONSUME / no_show→snapshot policy。

### Task 4-5 — 回数券運用ポリシー設定画面
`/admin/settings/tickets` + `ticket_policy.manage` permission + `password.confirm` + Settings service 検証 + 監査。
確認ダイアログ・非遡及の明示。

### Task 4-6 — 管理側 回数券商品・顧客回数券管理
回数券商品 CRUD（Stripe 価格 ID 等 Phase 5 項目は先行実装しない）。顧客別 wallet 残数・履歴（Query 経由）。
GRANT / REVOKE / ADJUST（`ticket.grant` + `password.confirm` + reason 必須 + 監査 + Action/Service 経由）。

### Task 4-7 — 顧客側 回数券残数・履歴 + 予約時選択
`CustomerLayout` 配下: 保有回数券一覧 / available / held / expires_at / 利用履歴 / 予約時に利用可能な回数券を選択。
購入決済画面・Stripe・Payment Element は表示しない。

### Task 4-8 — tickets:reconcile + scheduler
`tickets:reconcile`（read-only 検査: SUM(delta) vs balance / unresolved HOLD / 不正な負数 / 重複・矛盾 / expiration 整合）。
差異で非 zero exit・対象 wallet/customer 特定出力（PII 過剰出力なし）。修復モードは明示オプション + audit のみ。
`tickets:expire` / `tickets:reconcile` を `routes/console.php` に日次スケジュール登録。

### Task 4-9 — concurrency / idempotency / boundary / authorization tests（MySQL 実接続）
9 シナリオ（残 1 で同時 2 HOLD / HOLD retry / cancel 二重 / complete 二重 / HOLD×CANCEL 競合 / FEFO /
期限切れ使用不可 / reconcile 差異検出 / dedupe_key UNIQUE 最終防衛）。authorization（customer/staff/manager/admin）。
reauth。CSRF。rate limit が必要な endpoint。

### Task 4-10 — Phase 4 総合検証
`migrate:fresh --seed` / `npm run build` / `artisan test` + 最終チェックリスト（PLAN 準拠）。localhost + E2E。
secrets / production connection scan / composer audit / npm audit。

---

## 実行ログ

### 2026-09-08 — Task 4-1（DB / Model / Enum / Factory）: ✅ 完了 修正0
- migration +4（`2026_09_08_000016_create_ticket_products_table` 〜 `000019_create_ticket_reservation_usages_table`）。累計 20。
- `ticket_products`：id / name / total_count / price / validity_days / is_active / sort_order / timestamps。index `(is_active, sort_order)`。
  **`stripe_price_id` は Phase 5 へ延期**（DB_SCHEMA 記載だが Phase 5 項目の先行実装を避けるため。PLAN-vs-impl 差異として記録）。
- `ticket_wallets`：customer_id→`customers.user_id` restrict / ticket_product_id→`ticket_products.id` restrict /
  purchased_count / `balance`（signed smallint・available キャッシュ）/ expires_at(date) / status / timestamps。
  index `(customer_id, status)` `(expires_at)` `(status)`。**`payment_id` は Phase 5 へ延期**（payments 未存在）。
- `ticket_transactions`（追記のみ）：ticket_wallet_id→restrict / type / delta(signed) / reservation_id→nullOnDelete /
  staff_id→`staff.user_id` nullOnDelete / reason / `dedupe_key` UNIQUE（`ticket_transactions_dedupe_key_unique`）/ `created_at` のみ（updated_at なし）。
  index `(ticket_wallet_id, id)` `(reservation_id)`。
- `ticket_reservation_usages`（1 予約 = 1 行・ポリシー snapshot）：reservation_id→unique + cascadeOnDelete /
  ticket_wallet_id→restrict / `no_show_policy`（HOLD 時 snapshot: restore|consume）/ status(held|released|consumed) /
  held_at / released_at / consumed_at / timestamps。index `(ticket_wallet_id)` `(status)`。
- Enum 5：`TicketTransactionType`（DB リテラル大文字・`requiresReservation()`）/ `TicketWalletStatus` /
  `TicketReservationUsageStatus` / `TicketNoShowPolicy` / `TicketExpirationHoldPolicy`。
- Model 4：`TicketTransaction` は `$timestamps=false` + `updating`/`deleting` イベントで `RuntimeException`（追記専用ガード）。
  `TicketWallet::scopeFefo()`（expires_at→created_at→id 昇順）/ `scopeActive()`（status=active かつ expires_at>=today）。
- Factory 4。テスト `tests/Feature/Ticket/`（Schema / AppendOnly / WalletScope / Enum）10 passed。
- **検証**：`migrate:fresh --seed` 成功、`artisan test` **242 passed / 1350 assertions**（+10）、`npm run build` 型エラー 0。
  Service/Controller/Vue/routes/seeder/settings/config 未変更。Phase 5+ 痕跡なし。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-2（TicketLedgerService）: ✅ 完了 修正0
- `app/Domain/Ticket/TicketLedgerService.php`（`AuditLogger` DI）＝台帳追記の唯一の入口。
  - `append(wallet, type, delta, dedupeKey, ?reservationId, ?staffId, ?reason)`：事前 dedupe 検索 → 短い `DB::transaction`
    （wallet `lockForUpdate` → delta 符号検証 → `currentAvailable = SUM(delta)` → `newAvailable < 0` なら
    HOLD は `InsufficientTicketBalanceException`(409) / その他は `ValidationException`(422) → txn 追記 → wallet.balance/status を
    同一 transaction 更新）。`QueryException` SQLSTATE 23000（dedupe UNIQUE 競合）は捕捉して既存行を返す（二段構え冪等）。
  - `available()` = `SUM(delta)` / `held()` = `count(RESERVE_HOLD) - count(RESERVE_RELEASE)` / `total()` = available + held /
    `summary()`。**`SUM(delta) - held` は不使用。**
  - `grant(customer, product, ?count, operationKey, reason, actor)`：`grant:{operationKey}` 冪等。wallet 作成 + GRANT append + 監査を
    1 外側 transaction。`expires_at = today + product.validity_days`。
  - `revoke()` / `adjust()`：`revoke:{op}` / `adjust:{op}` 冪等・reason 必須（空 → 422）・`wasRecentlyCreated` のときのみ監査。
- `app/Exceptions/Ticket/InsufficientTicketBalanceException.php`（→ 409）。`bootstrap/app.php` の `$renderConflict` に 1 行追加（他不変）。
- テスト `TicketLedgerServiceTest` 12 件。**`artisan test` 254 passed / 1433 assertions**（+12）、build 型エラー 0。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-3（FEFO + HOLD/RELEASE/CONSUME + expiration + policy）: ✅ 完了 修正0
- `app/Domain/Ticket/TicketPolicyResolver.php`：`noShowPolicy()` / `expirationHoldPolicy()`（Settings 読み・不正値は既定へ）。
  `ALLOWED_NO_SHOW = ['restore','consume']` / `ALLOWED_EXPIRATION_HOLD = ['preserve_hold']`（4-5 の検証用定数）。
- `app/Domain/Ticket/TicketFefoSelector.php`：`selectForHold(customerId)` = `active()->fefo()->lockForUpdate()` を回して
  `ledger->available >= 1` の最初の wallet（なければ null）。
- `app/Domain/Ticket/TicketReservationService.php`（予約 1 件の ticket ライフサイクル・冪等）：
  - `hold()`：usage 既存なら return。transaction 内で FEFO 選択（null → `InsufficientTicketBalanceException` 409）→
    `append(RESERVE_HOLD, -1, resv:{id}:RESERVE_HOLD)` → **その時点の `no_show_policy` を snapshot** して `ticket_reservation_usages` 作成 → 監査 `ticket.held`。
  - `release()`：usage が `held` のときのみ。`append(RESERVE_RELEASE, +1)` → **`wallet.expires_at < today` なら
    同一 transaction で `append(EXPIRE, -1, expire:{wallet}:resv:{id})`**（期限後 RELEASE の available 復活防止・月次 EXPIRE と dedupe 衝突なし）→
    usage=released → 監査 `ticket.released`。
  - `consume()`：usage が `held` のときのみ。`append(RESERVE_RELEASE, +1)` → `append(CONSUME, -1)`（available 増減ゼロ・二重減算なし）→
    usage=consumed → 監査 `ticket.consumed`。
  - `handleNoShow()`：**`usage.no_show_policy`（snapshot）** で `release()` / `consume()` を分岐（設定変更を既存予約へ遡及しない）。
- `app/Console/Commands/ExpireTickets.php`（`tickets:expire {--dry-run}`）：`expires_at < today` かつ status!=expired の wallet を
  `chunkById` → 各 wallet を re-lock・re-check → `available > 0` なら `append(EXPIRE, -available, expire:{wallet}:{Ym})` →
  `status=expired` → 監査 `ticket.expired`。**未解消 HOLD・対応予約は不変**（preserve_hold）。二段冪等（status フィルタ + 月次 dedupe）。
- `routes/console.php`：`Schedule::command('tickets:expire')->dailyAt('03:00')->withoutOverlapping();` を 1 行追加。
- テスト `TicketReservationServiceTest`（FEFO / 冪等 / snapshot / 非遡及 / preserve_hold）+ `ExpireTicketsCommandTest`：計 +18。
- **検証**：`artisan test` **272 passed / 1520 assertions**、build 型エラー 0、`tickets:expire --dry-run` 動作。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-4（Reservation との HOLD/RELEASE/CONSUME 連携）: ✅ 完了 修正0
- `ReservationInput` に `paymentMethod: PaymentMethod = Onsite`（末尾・デフォルト）追加。
- `ReservationService` コンストラクタに `TicketReservationService $tickets` 追加（DI・全呼び出しは `app()` 経由なので非破壊）。
  - `create()`：`payment_method = $in->paymentMethod`。reservation + slots INSERT の後、**同一 transaction 内**で
    `paymentMethod === Ticket` なら `tickets->hold($reservation, actor)`。残不足 → `InsufficientTicketBalanceException`（`QueryException` ではないので
    slot 変換 catch に巻き込まれず）→ transaction 全体 rollback（予約・slots・usage 巻き戻し）→ 409。
  - `cancel()` → `tickets->release()`、`markCompleted()` → `tickets->consume()`、`markNoShow()` → `tickets->handleNoShow()`。
    いずれも既存 transaction 内・payment_method で分岐せず（usage 不在なら no-op）。`reschedule()` は非変更。
- `TicketReservationService` の 4 メソッドに `?Authenticatable $actor = null` を追加し監査へ伝播。
- テスト `ReservationTicketIntegrationTest` 11 件（create 残あり/なし/slot 競合、cancel、completed、no_show restore/consume、
  非遡及、Onsite 無影響、冪等、reschedule）。
- **検証**：`artisan test` **283 passed / 1582 assertions**、build 型エラー 0。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-5（回数券運用ポリシー設定画面）: ✅ 完了 修正1（テスト assert の enum 比較のみ）
- 権限 `ticket_policy.manage` 追加（`RolePermissionSeeder`：admin のみ。manager/staff/customer は無）。
  RolePermission 系テスト件数を permissions 16 / role_has_permissions 29 に更新、admin のみ true を assert。
- `SettingsSeeder` に `ticket.no_show_policy=restore` / `ticket.expiration_hold_policy=preserve_hold`（string）を追加。
- `app/Actions/Ticket/UpdateTicketPolicy.php`：`ALLOWED_NO_SHOW` / `ALLOWED_EXPIRATION_HOLD` で**再検証**（FormRequest と二重・未知値は 422）→
  `DB::transaction` 内で **変更されたキーのみ** `Settings::set` + 監査（`ticket.policy.no_show.changed` /
  `ticket.policy.expiration_hold.changed`、actor / key / old / new / reason、PII なし）。reason 空 → 422。
- `UpdateTicketPolicyRequest`（`authorize` = `can('ticket_policy.manage')`、`Rule::in` + reason required）。
- `TicketPolicySettingsController`（`show` / `update`）：Controller から直接 DB 更新なし・Action 経由のみ。
- ルート：`GET /admin/settings/tickets`（`can:ticket_policy.manage`）/ `PATCH`（`can:ticket_policy.manage` + `password.confirm`）。
- Vue `Admin/Settings/Tickets.vue`：ラジオ 2 種 + 各選択肢の残数影響説明 + reason 必須 + **確認ダイアログ**
  「この変更は今後作成される回数券利用予約に適用されます。既存の HOLD 済み予約には遡及適用されません。」。
  `HandleInertiaRequests` に `auth.can.ticketPolicyManage`、AdminLayout ナビに「回数券運用設定」（admin のみ表示）。
- テスト `TicketPolicySettingsTest` 9 件（customer/staff/manager 403・admin 200・password.confirm 前は不変・
  有効更新で監査・reason 無 422・不正値 422・変更キーのみ監査・非遡及）。
- **検証**：`artisan test` **292 passed / 1647 assertions**、build 型エラー 0。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-6（管理側 回数券商品・顧客回数券管理）: ✅ 完了 修正0（Codex は最終サマリ生成時に usage limit・実装/テストは全て disk 反映済み）
- 権限 `ticket_products.manage` 追加（**admin のみ**。services.manage 等と同様 manager にも付与せず）。
  RolePermission 系テスト件数を permissions 17 / role_has_permissions 30 に更新（4-5 の +1 と 4-6 の +1 の累計）、admin のみ true を assert。
- 回数券商品 CRUD：`TicketProductController`（index/create/store/edit/update/setActive）+
  `CreateTicketProduct` / `UpdateTicketProduct` / `ToggleTicketProductActive`（Action・`Arr::only` ホワイトリスト・監査
  `ticket_product.created/.updated/.activated/.deactivated`）+ `StoreTicketProductRequest` / `UpdateTicketProductRequest`
  （name/total_count 1..999/price >=0/validity_days 1..3650/sort_order/is_active。**stripe_price_id なし**）+
  `TicketProductListQuery` + Vue `Admin/TicketProducts/{Index,Create,Edit}.vue`。ルート `can:ticket_products.manage`。
- 顧客回数券の残数/履歴：`CustomerTicketQuery`（`walletsFor` = `TicketLedgerService::summary` で available/held/total、`historyFor` = txn 履歴・PII なし）+
  `CustomerTicketController::show`（`can:customers.view`）+ Vue `Admin/Customers/Tickets.vue`。`Admin/Customers/Show.vue` に導線。
- GRANT / REVOKE / ADJUST：`CustomerTicketController::{grant,revoke,adjust}` → **`TicketLedgerService` 経由**（Controller で直接 DB 更新なし）。
  ルート `can:ticket.grant`（＝admin+manager・PLAN §12「機微操作は manager 以上」）+ `password.confirm`。
  `GrantTicketRequest` / `RevokeTicketRequest` / `AdjustTicketRequest`（reason 必須・`operation_key` uuid 必須で冪等）。
- `auth.can` に `ticketProductsManage` / `ticketGrant`。AdminLayout ナビ「回数券商品」。
- テスト `tests/Feature/Admin/Tickets/`（ProductManagement / CustomerTicketView / GrantRevokeAdjust）計 +14。
- **検証（Claude Code 実行）**：`migrate:fresh --seed` 成功、`artisan test` **306 passed / 1857 assertions / 0 failed**、
  `npm run build` 型エラー 0。forbidden パス（docs/config/AGENTS/.github）変更なし。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-7（顧客側 回数券残数・履歴 + 予約時選択）: ✅ 完了 修正0
- `Customer/TicketController::index`（自分のみ・ルートパラメータ無し・customer 無しは 403）→ Vue `Customer/Tickets/Index.vue`
  （保有回数券 available/held/total/期限/状態 + 履歴・購入/Stripe UI なし）。`CustomerLayout` ナビに「回数券」。
- 予約ウィザード：`ReserveController::create` の props に `ticket.available_total` / wallets を追加。
  `StoreReservationRequest` に `payment_method`（`nullable`・`Rule::in(['onsite','ticket'])`）+ `validateTicketBalance()`
  （ticket 選択かつ active wallet の available 合計 < 1 → 422）。`store()` は `payment_method` を `PaymentMethod::Ticket|Onsite` に
  マップして `ReservationInput(paymentMethod:)` へ渡すのみ（staffId 自動割当・SlotUnavailable 処理は非変更）。
  すり抜けても `ReservationService::create` 内の `hold()` が 409。
- `Customer/Reservations/Show.vue` に「お支払い：回数券」バッジ。
- テスト `TicketsTest`（自分のみ 200・他人分は含まれない・未認証 login・staff 403）+ `ReservationBookingTest` 追記
  （ticket 残あり→HOLD / 残なし→422 / onsite 無影響 / すり抜け 409）計 +8。
- **検証**：`artisan test` **314 passed / 1960 assertions**、build 型エラー 0。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-8（tickets:reconcile + scheduler）: ✅ 完了 修正1（テスト assert の統合のみ）
- `app/Console/Commands/ReconcileTickets.php`（`tickets:reconcile {--wallet=} {--repair} {--dry-run}`）：
  既定 **read-only**。検査＝`SUM(delta)` vs `balance` / `held` vs `ticket_reservation_usages(status=held)` / 負の available・held /
  status 矛盾（exhausted なのに available>0 等）/ `expire 未処理`（expires_at<today かつ status≠expired）。
  差分あり → `warn` で `種別 wallet#N customer#N 期待値=… 実値=…`（**PII なし**）＋ `return FAILURE`（非 zero）。
  `--repair`（明示時のみ）＝ wallet ごと `DB::transaction` で **派生キャッシュのみ**（`balance` = `SUM(delta)`、
  `status` = expires 判定 + available）再計算、変更 wallet だけ監査 `ticket.reconcile.repaired`。
  **`ticket_transactions` / usage / reservation は不変**。`--dry-run` は書き込まず「修復予定」表示。修復後に再検査。
- `routes/console.php`：`tickets:reconcile` 日次 03:15（`--repair` なし）追加。既存 `reservations:prune-slots`(03:30) / `tickets:expire`(03:00) と併存。
- テスト `ReconcileTicketsCommandTest` 10 件（整合 exit0 / balance 改ざん検出・非 zero・PII なし / `--repair` で cache のみ修復・ledger 不変 /
  `--dry-run` / status 矛盾 / expire 未処理 / held 不整合 / `--wallet=` / 負の available）。
- **検証**：`artisan test` **324 passed / 2005 assertions**、build 型エラー 0、`tickets:reconcile` clean 実行 exit 0。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-9（concurrency / idempotency / boundary / authorization tests）: ✅ 完了 修正1（テスト assert の統合のみ）
- `tests/Feature/Ticket/TicketConcurrencyTest.php`（**MySQL 2 コネクション**・`DatabaseMigrations`・`mysql_second`・`innodb_lock_wait_timeout=1`）：
  4 シナリオ / 50 assertions（~7.5s＝実ロック待ち）。
  ① 残 1 に未コミット同時 2 HOLD → 1 件だけ成功（loser は errno 1205、commit 後は `InsufficientTicketBalanceException`）・
  usage/HOLD 各 1・available=0・途中も非負。② commit 後 HOLD は決定的に 409。③ RELEASE 競合 → 二重返却なし（`RESERVE_RELEASE` 1 件）。
  ④ dedupe_key UNIQUE がコネクション跨ぎの最終防衛（23000/1062 → 既存行返却・balance 不変）。sqlite は skip。
- `tests/Feature/Ticket/TicketIdempotencyTest.php`：HOLD/cancel/completed retry・FEFO（期限近い wallet 優先）・
  期限切れ/expired 使用不可（409 / 422）・preserve_hold（期限後 RELEASE で `EXPIRE -1` 相殺）・reconcile 差異検出（非 zero）。
- `tests/Feature/Ticket/TicketAuthorizationTest.php`：customer/staff/manager/admin × 全回数券エンドポイントの期待コード・
  GRANT/REVOKE/ADJUST の `password.confirm` ゲート・`Gate::forUser` バイパス無し・CSRF 419・
  ticket 予約 POST の `throttle:reserve`（11 回目 429）。
- アプリ本体の変更なし（テスト 3 ファイル追加のみ）。
- **検証**：`artisan test` **344 passed / 2164 assertions / 0 failed**、build 型エラー 0。baseline `77d03d3` 不変。

### 2026-09-08 — Task 4-10（Phase 4 総合検証）: ✅ 完了（Claude Code 主導）
- `migrate:fresh --seed`（20 migration + RolePermission/Settings seeder）クリーン。`DemoMasterSeeder`（local）投入。
- `npm run build`：`vue-tsc --noEmit` 型エラー 0、vite build 成功。
- `artisan test`：**344 passed / 2164 assertions / 0 failed**（MySQL。Phase 3 完了時 232 → +112）。
- `composer audit` / `npm audit`：脆弱性 0。
- 秘密情報スキャン（Phase 4 変更ファイル）：実キー・`64ssq_ark_db`・Gateway API キー混入なし
  （検出は Phase 1 の Stripe Live キー**ガード**実装/テスト/config コメントのみ）。
- Phase 5+ 先行実装スキャン（`Laravel\Cashier` / `Stripe\` / `PaymentIntent` / `PaymentElement` / `class *Membership` /
  `membership_usage` / `stripe_price_id` / `->charge(` / `billable(`）：**app/routes/database に一切なし**。
  `ExternalReservationGateway` は `NullExternalReservationGateway` のまま。
- MySQL 実接続：`TicketConcurrencyTest`（残 1 同時 2 HOLD → 1 件成功 / RELEASE 競合破損なし / dedupe_key UNIQUE 最終防衛）green。
- 機微ルートの middleware 確認：`GRANT/REVOKE/ADJUST` = `can:ticket.grant` + `password.confirm`、
  `PATCH /admin/settings/tickets` = `can:ticket_policy.manage` + `password.confirm`、`GET` も `can:ticket_policy.manage`、
  回数券商品 = `can:ticket_products.manage`（admin のみ）。
- localhost：`/` 200、`mypage/tickets` / `admin/ticket-products` / `admin/settings/tickets` は未認証 302（login）。
- **E2E（tinker・rollback）**：
  ① GRANT+5 → ticket 予約 HOLD（available 4 / held 1 / total 5）→ `markCompleted` → CONSUME（available 4 / held 0 / total 4）。
     ledger 履歴 = `GRANT , RESERVE_HOLD , RESERVE_RELEASE , CONSUME`（PLAN §10 準拠・二重減算なし）。
  ② GRANT+3 → ticket 予約 HOLD（available 2 / held 1）→ `cancel` → RELEASE（available 3 / held 0・完全復元）。
     ledger 履歴 = `GRANT , RESERVE_HOLD , RESERVE_RELEASE`。
  いずれも `balance` キャッシュ == `SUM(delta)`、`total` == `available + held`。

---

## ✅ Phase 4 完了 — 2026-09-08

### 実装（Task 4-1〜4-10）
ticket_products / ticket_wallets / ticket_transactions（追記型・`dedupe_key` UNIQUE・updated_at なし）/
ticket_reservation_usages（no_show ポリシー snapshot・非遡及）/
TicketLedgerService（`append` 唯一の入口・`available=SUM(delta)` / `held` / `total`・二重減算なし・cache 同一 transaction 更新・
二段冪等）/ GRANT・REVOKE・ADJUST（reason 必須・operationKey 冪等・監査）/
FEFO（expires_at→created_at→id）/ HOLD・RELEASE・CONSUME（予約単位冪等）/ tickets:expire（preserve_hold・available のみ失効）/
ReservationService 連携（create→HOLD / cancel→RELEASE / completed→CONSUME / no_show→snapshot policy・すべて既存 transaction 内）/
回数券運用ポリシー設定画面（`ticket_policy.manage` admin のみ + `password.confirm` + 監査 + 確認ダイアログ・非遡及明示）/
管理側 回数券商品 CRUD ＋ 顧客回数券の残数/履歴 ＋ GRANT/REVOKE/ADJUST（`ticket.grant` + `password.confirm`）/
顧客側 回数券残数・履歴 ＋ 予約時の回数券利用選択（購入/Stripe UI なし）/ tickets:reconcile（read-only 検査・`--repair` は派生キャッシュのみ）/
concurrency・idempotency・authorization テスト（MySQL 2 コネクション）。

### 最終数値
- migration：Phase 4 で +4／累計 20。
- テスト：**344 passed / 2164 assertions**（Phase 3 完了時 232 → +112）。
- build：型エラー 0。audit：脆弱性 0。
- 変更ファイル：baseline `77d03d3` 比 modified 21 / 新規 64（未コミット）。

### 残課題（Phase 5 以降・非ブロッカー）
1. `ticket_products.stripe_price_id` / `ticket_wallets.payment_id` は Phase 5（Stripe 単発決済）で追加。DB_SCHEMA 記載を後送りした差異。
2. オンライン購入（カード決済で PURCHASE 生成）は Phase 5。現状 wallet 生成は GRANT のみ。
3. `ticket.expiration_hold_policy` は `preserve_hold` の 1 値のみ（安全側固定）。他方式が要れば別途設計。
4. `no_show` × 回数券は既定 `restore`（`OPEN_QUESTIONS` #12「no-show 扱い」の店舗確定を反映可能な設定化で対応済み）。
5. reconcile の `--repair` は `balance` / `status` の再計算のみ（ledger は不変）。EXPIRE 相当の失効は `tickets:expire` が担当。
6. CI は git remote 未接続のため実走なし。

### Phase 5 開始条件
回数券機能が完成し、`migrate:fresh --seed` / build / 344 テスト / MySQL concurrency すべて green。
Phase 5（Stripe 単発決済・Test Mode）に着手可能。**ユーザーの明示許可を待つ。**
