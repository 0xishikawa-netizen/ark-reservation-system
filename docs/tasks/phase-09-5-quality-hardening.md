# Phase 9.5 — Production Hardening / 全システム品質強化

新機能開発ではなく、Phase 0〜9 + 追加実装（開発管理者・キャンセルポリシー・キャンセル返金・
当日メニュー変更・差額 Payment・部分返金・Payment Rollup）を横断レビューし、本番運用で
起こり得るバグ・競合・二重処理・権限漏れ・データ不整合・障害復旧性の問題を発見して
最小修正する回。

- 開始 HEAD: `ed3ef10`
- baseline: `artisan test` 740 passed / 4426 assertions / 0 failed。

## 監査体制

- 6 領域（A Reservation/Cancellation/PriceAdj・B Payment/Refund/Stripe/Webhook・
  C Ticket/Membership/Ledger・D Auth/MFA/Authz/IDOR・E DB/Queue/Scheduler/Integration/Perf・
  F Security/PII/Logging/Config）を Codex READ-ONLY Red Team に並列発注。
- **Codex が途中で usage limit / connection error に達して全タスク failed**（`try again at 4:46 PM`）。
  各タスクの中間仮説のみ取得。**ディスクへの書き込みは無し（read-only）。git 破壊操作は未実施。**
- 以降の監査と修正は Sonnet 5 が担当（役割 §6.7「無理なら Sonnet 自身で継続」）。

## Finding 一覧と再判定

| ID | Sev | 領域 | 判定 | 対応 |
|---|---|---|---|---|
| H9.5-1 | MEDIUM | Payment / recovery | TRUE POSITIVE | 修正 |
| H9.5-2 | MEDIUM | Operations / DB capacity | TRUE POSITIVE | 修正 |
| H9.5-3 | LOW（金銭隣接） | Price Adjustment / concurrency | TRUE POSITIVE | 修正（防御。決定的再現テストは concurrency 限定のため無し・理由明記） |
| D-1 | — | Webhook 同一 event 並行 delivery（UNIQUE-insert loser が lockForUpdate なしで再読込）| DESIGN DECISION / DOCUMENT | Phase 9 レビューで既知・受容済み。下流の capture / GRANT dedupe が business effect を収束させ、金銭喪失シナリオを構築できない。要 monitoring（`webhook_events.attempts` の異常増）|
| — | append-only 台帳（ticket_transactions / membership_usage_transactions）| CLEAN | `create()` のみ・model event guard・bulk/raw/upsert 無し |
| — | 回数券 / 利用権 entitlement 並行消費・残数マイナス | CLEAN | wallet / membership 行に `lockForUpdate`＋`dedupe_key` DB UNIQUE＋concurrency テスト有り |
| — | Webhook の Payment 解決（`stripe_payment_intent_id` で解決・`kind` 絞り込み無し）| CLEAN | `single_addon` も正しく処理される |
| — | Stripe HTTP を DB::transaction 外で実行 | CLEAN | `assertStripeOutsideTransaction()`＋Stripe を触るテストは `DatabaseMigrations`（`RefreshDatabase` 不使用） |
| — | IDOR / ownership（reservation / payment / addon checkout+sync / ticket / membership / profile / history）| CLEAN | 認証ユーザーの `customer_id` または `ReservationPolicy` に収束。body/query id を信用しない |
| — | `.env` tracking / secret literal | CLEAN | `.env` は gitignore・追跡なし。tracked ファイルの key は `_xxx` placeholder か「漏洩しないことを検証する negative test の固定文字列」のみ |
| — | net_received / 差額計算（8000→10000 / →8000 / →9000 / →12000 / addon paid→9000 / addon pending→9000/12000 / refund pending 中）| CLEAN | `Σ max(0, amount − refunded_amount)`（captured status のみ）。過剰請求・過剰返金・二重 addon・stacking の各ガードあり（`ed3ef10` レビュー済み） |

### H9.5-1（MEDIUM）: timeout で `pending` のまま止まった返金の settle 経路が無い

- **Evidence**: `PaymentService::retryRefund` は Stripe timeout / 曖昧応答時に PaymentRefund を `pending` のまま残す（二重返金防止）。`ReconcilePayments` は read-only で `refunded_amount_mismatch` を検出するだけ。管理画面の再送アクションも無し。
- **Failure scenario**: 返金要求が Stripe timeout → PaymentRefund が `pending` 恒久化 → (1) `payments.refunded_amount` キャッシュが更新されない、(2) `ReservationAdjustmentService::refundDifference` は「未確定返金がある間は新規返金を重ねない」ため、**その予約の差額調整（addon / 返金）が恒久ブロック**。
- **Impact**: 該当予約で以後の当日金額調整が不能。金銭は Stripe 冪等キーで再送すれば収束するが、その再送を行う経路が無い。
- **Fix（最小）**: `payments:settle-pending-refunds` コマンド — `updated_at` が grace（5分）を超えた `pending` PaymentRefund に `PaymentService::retryRefund`（保存済み operation ID 由来の冪等キー・Stripe は transaction 外）を再適用。scheduler `everyTenMinutes` + `withoutOverlapping`。decline / 曖昧 timeout はそのまま記録され次回対象から外れる。
- **Regression test**: `SettlePendingRefundsCommandTest` — stuck pending → settle 成功 + `refunded_amount` 反映 / grace 内は無操作 / decline でもコマンドは exit 0。

### H9.5-2（MEDIUM）: `webhook_events` / `audit_logs` が無限増加（retention 設定はあるが prune 未実装）

- **Evidence**: `config/retention.php` に `prune.webhook_events.success_days`（90・`keep_failed`）と
  `prune.audit_logs.low_value_days`（365・`keep_categories`）が宣言されているが、これらを prune する
  コマンド / scheduler / `Prunable` trait がどこにも無い（`reservations:prune-slots` と
  `reservations:prune-sync-logs` は別テーブル担当）。`webhook_events` は Stripe event ごとに 1 行増える。
- **Impact**: 本番で `webhook_events` が単調増加し DB 容量・index 肥大。運用障害。
- **Fix（最小）**: `system:prune-technical-logs` — `webhook_events`（`status != failed` かつ
  `received_at` が保持日数超）と `audit_logs`（action に `payment` / `refund` / `ticket` /
  `membership` / `pii` を含まない かつ `created_at` が `low_value_days` 超）をバッチ削除
  （1000 件ずつ・長時間ロック回避）。`--dry-run` 対応。scheduler `dailyAt('02:40')` +
  `withoutOverlapping`。**failed / 金銭・PII 系 audit は削除しない。**
- **Regression test**: `PruneTechnicalLogsCommandTest` — 古い非 failed webhook は削除・failed と直近は保持 /
  `payment.*` `ticket.*` `membership.*` `*refund*` audit は無期限保持・`auth.login` 等は 365 日超で削除 /
  `--dry-run` は 0 件削除。

### H9.5-3（LOW→修正）: `voidAddon` が settle 済み addon で `ValidationException` を握り潰さず 422

- **Evidence**: `ReservationAdjustmentService::voidAddon` は `PaymentGatewayException` のみ catch。
  `cancelableAddons`（`pending` / `authorized` を取得）の取得後〜`voidAddon` 実行の間に webhook / sync が
  addon を `succeeded` にすると、`PaymentService::cancel` が `ValidationException`（「pending または
  authorized 状態の決済だけを取消できます」）を投げ、それが `requestAdjustment` を貫通して管理画面に 422。
- **Impact**: 稀な並行競合で、正当に支払われた addon なのに管理者に不明瞭なエラー。金銭・データ被害は無し。
- **Fix（最小）**: `voidAddon` 冒頭で `$addon->fresh()` を再確認し、既に `pending` / `authorized` でなければ
  「処理済み」として `true` を返す。加えて `catch (ValidationException) { return true; }`。
- **Regression test**: 決定的再現は単一 connection では不可（マイクロ秒競合）。既存
  `test_price_raised_again_voids_old_addon_and_creates_one_full_gap` で通常 void 経路の非退行を担保。

## 検証

- `pint --dirty`: passed（変更対象のみ。無関係ファイルの整形なし）。
- 対象テスト: `SettlePendingRefundsCommandTest` / `PruneTechnicalLogsCommandTest` /
  `ReservationAdjustmentServiceTest` / `ReservationAddonCheckoutTest` / `AdminReservationAdjustmentTest` GREEN。
- Full regression: `artisan test` 746 passed / 4453 assertions / 0 failed（740 → +6）。
- `migrate:fresh --seed` GREEN（開発管理者復元）/ `npm run build` GREEN / `composer audit` 0 / `npm audit` 0。
- `schedule:list`: `payments:settle-pending-refunds`（*/10）・`system:prune-technical-logs`（02:40）登録確認。
- static scan: secret / live key / `dd(` / `dump(` / `console.log(` / ledger UPDATE-DELETE / `.env` tracked → 検出なし。

## Quality Gate

- CRITICAL 残: **0**
- HIGH 残: **0**
- 金銭系 MEDIUM 残: **0**（H9.5-1 修正）
- Authorization 系 MEDIUM 残: **0**
- PII / Secret 系 MEDIUM 残: **0**
- Data Consistency 系 MEDIUM 残: **0**
- Operations MEDIUM 残: **0**（H9.5-2 修正）
- 判定: **ARK AUTOMATED QUALITY GATE = GREEN**

## 変わらない OPEN 事項（本回の自動テスト結果で GREEN / READY にしない）

- **REAL STRIPE TEST MODE QA = INCOMPLETE**（実 Test credential なし。通常カード / 3DS-SCA /
  invoice.paid / payment failure / retry / cancel-resume / refund / addon payment / price adjustment /
  webhook duplicate-retry-reverse / 実 Stripe API shape は別途人手 QA が必要。Fake / automated が
  大量に GREEN でも「本番決済品質確認済み」とは表現しない）。
- **Membership Production Readiness = NOT READY**。
- **SALON BOARD Real Integration = BLOCKED / OFFICIAL SPEC WAITING**。
- **Peak Manager Real Integration = BLOCKED / OFFICIAL SPEC WAITING**。
- **Phase 10 未着手 / Phase 11 未着手**。

## DESIGN DECISION / DOCUMENT ONLY（今回修正しない）

- D-1 Webhook 同一 event の並行 delivery: Phase 9 レビューで受容済み。business effect は下流 dedupe で
  収束。将来 `webhook_events` に処理 mutex（`lockForUpdate` 再読込 or advisory lock）を入れる余地は
  あるが、現状で金銭喪失シナリオは無く、Phase 9.5 の「最小修正」方針外。
- `reservations:prune-sync-logs` / `system:prune-technical-logs` の一括 `->delete()`: バッチ化済み
  （後者）/ 日次・時間窓限定で許容（前者）。超大規模データでの更なる chunk 化は将来課題。
