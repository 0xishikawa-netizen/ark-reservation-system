# Phase 8 — 店舗管理 + システム状態

> Source of Truth は現行 repo。過去チャットではなく `docs/PLAN.md` §16（Phase 8 = 「ダッシュボード / 予約一覧 / 顧客 360 / 回数券管理 / 契約管理 / `Admin/SystemStatus` / DB 容量スナップショット + 通知」）と現行コードを正とする。
>
> **重要**: 本 Phase の開始時点で **REAL STRIPE TEST MODE QA は未完了**（実 Test credential 未設定）。
> Phase 8 の開発を進めることと「Membership を本番投入可能と判断すること」は完全に分離する。
> Phase 8 完了後も **Membership Production Readiness = NOT READY** のままとする。

## 0. 開始 baseline

- HEAD: `abc6bc1` "Complete Phase 6 Stripe integration hardening"
- branch `main` / remote なし / push 禁止 / working tree clean
- `artisan test` 623 passed / 3536 assertions / 0 failed
- `npm run build` green / `composer audit` 0 / `npm audit` 0
- Phase 0〜7 完了。Phase 6/7 Red Team Review 済み。

## 1. 現状調査（Sonnet 実施済み）

### 既に存在する管理画面（Phase 2〜7 成果・再利用対象）

| 領域 | 経路 / 実装 |
|---|---|
| マスタ CRUD | staff / staff-shifts / services / booths / ticket-products / membership-plans（Controller + Vue + FormRequest + `can:*.manage` + 機微操作は `password.confirm`） |
| 予約一覧 | `GET /admin/reservations`（`ReservationController@index` / `ReservationListQuery`） |
| 予約操作 | create / edit / update / cancel / complete / no-show（`can:reservations.manage`・`ReservationService` 経由） |
| 予約台帳 | `GET /admin/schedule`（`ScheduleController` / `ScheduleQuery` / `Schedule/Index.vue` 489 行）。**日表示・スタッフ軸・読み取り専用**。非勤務帯シェード有り |
| 顧客 | index / show / edit + `/customers/{c}/tickets` + `/customers/{c}/membership` |
| 回数券運用 | grant / revoke / adjust（`can:ticket.grant` + `password.confirm` + 理由必須 + 監査）/ policy 設定 |
| 契約（Membership）運用 | `CustomerMembershipController` show / adjust / cancel-now / sync（`can:membership.manage` + `password.confirm`） |
| 決済 | `AdminPaymentController` index / show / sync / refund（refund は `can:refund.execute` + `password.confirm` + 理由必須 + 監査） |
| システム | `GET /admin/system/failed-jobs`（`can:failed_jobs.view`） |
| 認可基盤 | `AdminAccess` + `AdminIdleTimeout` + `EnsureStaffMfa` middleware / spatie role `customer|staff|manager|admin` / `can:*` per route / Inertia `auth.can.*` |

### Phase 8 で埋める GAP（本 Phase のスコープ）

1. **Admin Dashboard がほぼ空**（`AdminDashboardController` は `failedJobsCount` のみ / `Dashboard.vue` 28 行）。運用トップとして機能していない。
2. **`Admin/SystemStatus` が存在しない**（PLAN.md §14/§16 の Phase 8 必須成果物）。
3. **監査ログビューアが無い**。`AdminLayout` は `/admin/system/audit-logs` へリンクするが route / controller / page が無い（**死んだナビリンク**）。`audit_logs.view` permission と `audit_logs` テーブルは既存。
4. **`db_size_snapshots` テーブルが未使用**。`db:snapshot-size` コマンド・日次スケジュール・閾値通知が無い（PLAN.md §14 Phase 8）。
5. **予約台帳が日表示・スタッフ軸のみ**。週表示・ブース軸・（可能なら）D&D / リサイズが無い。
6. **顧客詳細が薄い**（`Customers/Show.vue` は基本情報のみ）。予約履歴・支払い履歴の要約が無い（顧客 360）。
7. **テスト不足**: Dashboard / SystemStatus / AuditLog / Admin Payment / Admin Membership の Feature テストが無い。

### やらないこと（scope 外・明示禁止）

Stripe Live / production deployment / Peak Manager 実連携 / SALON BOARD / Hot Pepper API / 外部予約 API 実接続 /
multi-store / `store_id` / LINE / loyalty / recommendation / analytics platform / Phase 9 以降 / remote 追加 / push /
Membership business logic の再実装（Phase 6 の Service / StateMachine を再利用）。

## 2. アーキテクチャ原則（本 Phase でも不変）

- Controller / Vue から business DB を直接更新しない。`ReservationService` / Ticket services / Payment services / Membership services / StateMachine / Action を再利用。
- 集計キャッシュは 1 集計 1 カラム。新規 business ロジックを Phase 8 側に重複実装しない。
- 認可は Policy / Permission（`can:*`）で制御。Controller 判定だけに依存しない。deny-by-default。
- 機微操作（refund / Ticket ADJUST / Membership ADJUST / Membership cancel-now / staff 管理 / plan 変更）は `password.confirm`（+ 既存方針どおり MFA）を維持・弱体化しない。
- Stripe 内部情報（secret / raw error / 過剰な client_secret）を画面へ露出しない。PII は必要最小限。
- `audit_logs` は要約 1 行（スナップショット JSON なし）。

## 3. タスク分割

各タスク: Sonnet 現状調査 → Acceptance Criteria 確定 → Codex 実装発注 → Sonnet が実 diff / tests / build / security・authorization をレビュー → 是正 → Task 完了。ユーザー承認待ちなし・自動継続。

### Task 8-1 — Phase 8 設計 + Admin Dashboard（運用トップ）

- 本ドキュメント作成（Sonnet）。
- `AdminDashboardController` を運用概要へ拡張。読み取り専用の集約クエリ `App\Queries\AdminDashboardQuery`（既存 Query 群の隣）に集計を集約。
- 表示項目（情報過多にしない・権限でフィルタ）:
  - 本日の予約件数 / 次の来店（直近数件）
  - 要対応決済（`payments.needs_attention` または pending 滞留）件数 + リンク
  - grace / paused / canceling の Membership 件数 + リンク
  - 回数券残数警告（期限間近 or 残 0 の wallet 件数）
  - 仮予約滞留（`pending_payment` で `payment_expires_at` 超過）件数
  - 失敗ジョブ件数（既存）
- `Dashboard.vue` をカード群で再構成。empty / loading / 権限なし状態を持つ。数値カードはリンク先の既存一覧へ絞り込みクエリで遷移。
- AC: customer は `/admin` に到達不可 / staff は自身の権限に応じたカードのみ / N+1 なし / 集計は Query クラス内 / 既存テスト非退行。

### Task 8-2 — `Admin/SystemStatus`

- `GET /admin/system/status` → `SystemStatusController`（`can:failed_jobs.view`。既存 system 系と同じ権限で統一）。route + `AdminLayout` ナビ追加。
- `App\Support\System\SystemStatusReport`（読み取り専用サービス）が以下を返す:
  - Stripe モード: `test` / `live` / `placeholder` / `missing`（config の prefix 判定のみ。**secret 値は返さない・ログに出さない**）
  - Reservation Authority（`config('reservation.authority')`）
  - キュー健全性: 失敗ジョブ件数 / 最古の失敗ジョブ時刻
  - 仮予約滞留: `pending_payment` 超過件数
  - 同期失敗: `sync_logs` テーブル・`backup:run` は現状未実装のため「未計測（日次バッチのログ / 失敗ジョブを参照）」と明示（新テーブルは追加しない）
  - DB 使用量: `db_size_snapshots` 最新行（`total_mb` / `captured_on`）+ `config('retention.db_size_alert_mb')`（既存・env `DB_SIZE_ALERT_MB`）超過フラグ（Task 8-4 と連動）
  - **REAL STRIPE TEST MODE QA: 未完了 / Membership Production Readiness: NOT READY を明示表示**
- `SystemStatus.vue`: 各項目を OK / 注意 / 未計測 のバッジで表示。破壊的操作なし（純表示）。
- AC: secret 非露出 / 権限外アクセス不可 / 値が取れない項目は落ちずに「未計測」/ テスト追加。

### Task 8-3 — 監査ログビューア（死んだナビリンク解消）

- `GET /admin/system/audit-logs` → `AuditLogController@index`（`can:audit_logs.view`）。route 追加。
- `App\Queries\AuditLogQuery`: action / actor / entity_type / 期間 でフィルタ、`created_at desc`、ページネーション。読み取り専用。
- `System/AuditLogs.vue`: フィルタ + テーブル + ページャ。`summary` はそのまま表示（既に 1 行要約・JSON なし）。actor は user 表示名へ解決（PII 最小限）。
- AC: `audit_logs.view` を持たない staff は 403 / customer は到達不可 / N+1 なし / 大量データでページャ動作 / テスト追加。

### Task 8-4 — `db:snapshot-size` コマンド + 日次 + 閾値

- `App\Models\DbSizeSnapshot`（`db_size_snapshots`）。
- `App\Console\Commands\SnapshotDbSize`（`db:snapshot-size`）: `information_schema` から現在 DB の合計 MB を集計し `captured_on`（今日）で `updateOrCreate`。冪等。
- `routes/console.php` に `Schedule::command('db:snapshot-size')->dailyAt('02:45')->withoutOverlapping()`。
- 閾値: `config('retention.db_size_alert_mb')`（**既存**・env `DB_SIZE_ALERT_MB` は `.env.example` にも既にある）。超過時はコマンドが warning ログ + 非 zero exit（`*:reconcile` と同じ様式）。新しい config キー・通知チャネルは追加しない。
- SystemStatus / Dashboard が最新値と超過フラグを表示。
- AC: コマンド冪等（同日二重実行で 1 行）/ 閾値未設定時は警告扱いにしない / テスト追加。

### Task 8-5 — 予約台帳の拡張（週表示 + ブース軸）

- `ScheduleController` / `ScheduleQuery` に `view=day|week` と `axis=staff|booth` を追加（default は現行維持 = day / staff）。
- 週表示: 7 日 × 時間軸。日単位カラム内はコンパクト表示。既存の非勤務帯シェードは day のみで可。
- ブース軸: レーンを booth に切替（`booths` アクティブのみ）。`booth_id` null は「ブース未割当」レーン。
- **D&D / リサイズは本タスクでは行わない**（安全性優先。読み取り専用の可視化のみ拡張）。将来タスクとして本ドキュメントに残す。
- `Schedule/Index.vue` を day/week・staff/booth の 4 状態に対応。横スクロールのみ（縦の body スクロールを壊さない）。
- AC: 既存 `ScheduleQueryTest` 非退行 / 週表示で N+1 なし（1 クエリで期間分取得）/ 権限は現行どおり（`reservations.view` で閲覧・`reservations.manage` で新規ボタン）/ テスト追加。

### Task 8-6 — 顧客詳細 360

- `CustomerController@show` に読み取り専用の要約を追加（専用 Query）:
  - 直近の予約履歴（数件 + 「予約一覧で顧客絞り込み」リンク）
  - 支払い履歴の要約（直近数件 + `/admin/payments` への顧客絞り込みリンク。金額・状態のみ。Stripe 内部 ID は出さない）
  - 回数券サマリ（保有 wallet 数 / 合計残 → 既存 tickets ページへ）
  - Membership サマリ（現在の status / plan / 当期残 → 既存 membership ページへ）
- `Customers/Show.vue` にカードを追加。PII は既存 show と同レベルに留める。
- AC: N+1 なし / customer 取り違えなし（route model binding + 明示 scope）/ 他人の payment / reservation が混ざらない / テスト追加。

### Task 8-7 — 認可 / 監査 / PII ハードニング

- Codex READ-ONLY 監査: 全 `/admin/*` エンドポイントについて
  - customer ロールで全件 403/リダイレクト
  - staff（限定権限）で権限外操作が 403
  - route model binding の IDOR（他顧客の reservation/payment/membership/ticket-wallet を ID 指定で操作できないか）
  - 一覧 API の PII 過剰露出
  - `Payments/Show.vue` と controller で Stripe secret / raw error / 不要な client_secret 露出
  - 機微操作の `password.confirm` 欠落
- TRUE POSITIVE のみ最小修正 + regression test。FALSE POSITIVE は修正しない。

### Task 8-8 — Admin E2E / 非退行テスト

- 追加: Dashboard / SystemStatus / AuditLog / Schedule(week,booth) / Customer 360 / db:snapshot-size。
- 認可マトリクス: customer 不可 / staff 権限境界 / manager / admin。
- 非退行: Phase 3 / 4 / 5 / 5.5 / 6 / 7 の既存 Feature スイートが全て green のまま。
- concurrency: 既存の予約二重 / 回数券 / Membership 同時実行テストを壊さない。

### Task 8-9 — Sonnet Phase 8 総合レビュー + 最終検証 + commit

- architecture / authorization / IDOR / business logic 重複 / reservation・ticket・membership 整合 / payment 安全 / MFA / reauth / audit / PII / responsive / race / failure handling / Phase 9 先行実装 をレビュー。
- 安全性・整合性・非退行に限り修正。
- `migrate:fresh --seed` / `npm run build` / `artisan test` / `composer audit` / `npm audit` + 各種 scan。
- 全 green の場合のみ `git commit -m "Phase 8 store admin baseline"`。remote 追加禁止・push 禁止。

## 4. 完了条件

- 上記 Task 8-1〜8-9 が Sonnet レビュー通過。
- 最終自動検証すべて green。
- 最終報告に **REAL STRIPE TEST MODE QA = 未完了** / **Membership Production Readiness = NOT READY** / 残 Stripe 手動 QA 一覧 を明記。
- Phase 9 へは進まない（ユーザーの明示許可待ち）。
