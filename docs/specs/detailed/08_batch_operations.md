# 詳細設計 08 — 定期処理・ジョブ・監視・運用

関連：基本設計 F-SYS / §9.4、既存文書 `docs/OPERATIONS.md`（復旧手順の正本）、`docs/TROUBLESHOOTING.md`、`docs/DEPLOYMENT.md`

## 1. 実行基盤

| 項目 | 設定 |
|---|---|
| スケジューラ | `routes/console.php`（`php artisan schedule:run` を毎分 cron で起動する前提） |
| 重複起動防止 | すべて `withoutOverlapping()` |
| キュー | `QUEUE_CONNECTION=database`（`jobs` / `failed_jobs`）。新着予約の配信はキューを使わず即時（`ShouldBroadcastNow`） |
| 冪等 | すべての定期処理は何度実行しても同じ結果になる。突合系は既定で読み取りのみ、差異があれば非 0 終了＝失敗として記録 |

## 2. スケジュール一覧

時刻は JST（`APP_TIMEZONE`）想定。

| 時刻 | コマンド | 内容 | 失敗時 |
|---|---|---|---|
| 01:00 | `backup:clean` | バックアップの世代整理（7 日＋週 1 を 4 週、容量上限） | 通知メール |
| 01:30 | `backup:run` | DB＋`storage/app` のバックアップ（整合性検証つき） | 通知メール・システム状態で異常表示 |
| 07:00 | `backup:monitor` | 最新が 1 日以内か・容量上限内かを確認 | 通知メール |
| 毎分 | `payments:expire {--limit=200}` | 支払い期限切れの仮予約を失効、枠・与信・HOLD を解放。capture 済みは失効させず要対応 | 次の分に再実行 |
| 毎分 | `reservations:dispatch-outbox {--sync}` | 外部連携 Outbox の送信（外部連携が無効なら何もしない） | backoff 付き再試行 |
| 10 分ごと | `payments:settle-pending-refunds {--dry-run}` | `pending` のまま止まった返金を同じキーで再送して決着 | 要対応のまま残る |
| 15 分ごと（設定） | `reservations:poll-external {--sync}` | 外部予約の取込（`reservation_integration.inbound.poll_cron`） | |
| 02:40 | `system:prune-technical-logs {--dry-run}` | `webhook_events` / `audit_logs` を保持期間で削除（未解決・失敗・金銭・PII は残す） | |
| 02:45 | `db:snapshot-size` | DB 合計サイズを `db_size_snapshots` に記録。閾値超過で非 0 | 容量確認 |
| 02:50 | `reservations:prune-sync-logs {--dry-run}` | 連携ログ・完了 Outbox・解決済み競合を保持期間で削除 | |
| 03:00 | `tickets:expire {--dry-run}` | 期限切れ回数券の残数を失効（押さえ中は除く） | |
| 03:15 | `tickets:reconcile` | 回数券の台帳とキャッシュ・状態の突合 | 差異で非 0 |
| 03:30 | `reservations:prune-slots {--days=} {--dry-run}` | 保持期間を過ぎた終了済み予約の占有スロットを削除 | |
| 03:45 | `payments:reconcile {--repair} {--limit=500} {--stripe}` | 決済のローカル状態と Stripe・予約の矛盾検出。`--repair` は安全な派生状態だけ修復（金銭操作はしない） | 差異で非 0 |
| 04:00 | `memberships:grant-current {--dry-run}` | 当期の利用回数の付与漏れを補う | |
| 04:15 | `memberships:expire-grace {--dry-run}` | 猶予期限を過ぎても未回復の会員を `paused` | |
| 04:30 | `memberships:reconcile` | 月額の状態・台帳・キャッシュと Stripe の突合 | 差異で非 0 |
| 05:00（設定） | `reservations:reconcile-providers {--provider=} {--dry-run}` | 外部予約との突合（`reservation_integration.reconcile.cron`） | 差異で非 0 |
| 06:00 | `shifts:generate {--staff=}` | 基本シフトから勤務枠を生成（追加のみ） | 管理画面「今すぐ反映」 |

### 手動コマンド

| コマンド | 用途 |
|---|---|
| `stripe:replay {event_id}` | Stripe イベントを取得し、Webhook と同じ冪等経路で再処理 |
| `ark:legacy-import:convert` | 旧帳票ブックを過去データ取込の中間 CSV へ変換（DB 変更なし） |
| `ark:backup:verify-restore --database=<検証用DB> [--backup=<zip>]` | 最新バックアップを別 DB へ復元し、主要テーブルの件数を稼働 DB と比較（`OPERATIONS.md` §2.1） |

## 3. キュージョブ

| ジョブ | 起動 | 内容 |
|---|---|---|
| `Integration\DispatchReservationOutboxJob` | `reservations:dispatch-outbox` | Outbox を 1 件ずつ取得（`FOR UPDATE SKIP LOCKED`）して外部へ送信 |
| `Integration\PollExternalReservationsJob` | `reservations:poll-external` | 外部から直近ウィンドウの予約を取得 |
| `Integration\ProcessExternalReservationJob` | 上記から | 外部予約 1 件を取り込み（`ShouldBeUnique`、`(provider, external_id)` のロック） |

詳細は [09_external_integration.md](09_external_integration.md)。

## 4. 保持期間（`config/retention.php`）

| データ | 既定保持 |
|---|---|
| `webhook_events` | 成功・無視 90 日／失敗は解決まで |
| `audit_logs` | 金銭・PII は長期／その他 365 日 |
| `reservation_sync_events` | 成功・no-op・skip 60 日／失敗は解決まで |
| `reservation_sync_outbox` | 完了 30 日／要対応は保持 |
| `reservation_sync_conflicts` | 解決・無視 180 日／未解決は保持 |
| `reservation_resource_slots` | 終了済み予約の過去分を `RETENTION_RESERVATION_SLOTS_PAST_DAYS` で削除 |
| `mfa_sms_challenges` | 7 日 |
| 業務データ（予約・顧客・決済・回数券・月額・来店・会計） | 削除しない（法定保存年数は `OPEN_QUESTIONS.md` #3） |

## 5. 監視（管理画面）

| 画面 | 権限 | 表示内容 |
|---|---|---|
| システム状態 `/admin/system/status`（`SystemStatusReport`） | failed_jobs.view | Stripe 設定状態、予約の正本（authority）、キュー、仮予約の滞留、同期失敗、要対応の決済・月額、最終バックアップ、DB 使用量 |
| 失敗ジョブ `/admin/system/failed-jobs`（`FailedJobsReader`） | failed_jobs.view | `failed_jobs` の一覧（例外クラス・時刻） |
| 監査ログ `/admin/system/audit-logs` | audit_logs.view | 操作の要約 |
| 決済一覧 `/admin/payments` | reservations.view | 要対応（`needs_attention`）での絞り込み |
| 外部連携 `/admin/integrations/reservations` | integrations.view | Outbox・同期イベント・競合（外部 ID はマスク） |

**自動で直るもの／人の判断が要るもの**（`OPERATIONS.md` §11）

| 自動回復 | 人が判断 |
|---|---|
| 通信タイムアウト後の決済・返金（突合・再送） | 孤立決済（予約が無い／失効済みなのに capture 済み） |
| Webhook の再送 | 二重課金の疑い、金額不一致 |
| 当期 GRANT の漏れ | キャンセル返金の失敗、ゲストのキャンセル返金 |
| 勤務枠の生成漏れ | 台帳とキャッシュの差異（突合が非 0） |
| Outbox の一時失敗 | Outbox の恒久失敗（needs_attention）、外部との競合 |

## 6. バックアップ（Task 11-33 / H-5 で実装）

| 項目 | 内容 |
|---|---|
| 方式 | `spatie/laravel-backup` ^10.3（`config/backup.php`）。DB は `mysqldump`＋gzip、ファイルは `storage/app` |
| 保存先 | ディスク `backups`（既定 `storage/backups/`、Git 管理外）。`BACKUP_DISK_DRIVER` / `BACKUP_DISK_ROOT` で変更。オフサーバー保存は未設定 |
| 世代 | 7 日はすべて＋週 1 を 4 週。`BACKUP_MAX_STORAGE_MB`（既定 5000）超過で古いものから削除 |
| 検知 | 失敗・不健全はメール（`BACKUP_NOTIFICATION_EMAIL` → `ADMIN_ALERT_EMAIL`）。システム状態の「最終バックアップ」に日時・サイズ・経過時間、1 日超で異常 |
| 暗号化 | `BACKUP_ARCHIVE_PASSWORD` を設定すると zip を暗号化 |
| 復元検証 | `ark:backup:verify-restore`。稼働 DB と同名の復元先・DB 切替文を含むダンプ・危険な zip エントリを拒否。開発環境で全 89 テーブルの復元とチェックサム一致を確認済み（2026-10-07） |
| 未検証 | 本番の保存先（S3 等）と認証情報、本番サーバーでの mysqldump の有無 |

## 7. デプロイ

`docs/DEPLOYMENT.md` が正本。要点：

- 環境：local（Sail）のみ構築済み。staging / production は未構築（VPS 前提の手順は記載済み）。
- マイグレーションを含むデプロイの直前に必ずバックアップ。
- アプリ用 DB ユーザーは最小権限（本番で DROP 不可）。マイグレーションは特権ユーザーで実行。
- 常駐プロセス：スケジューラ（cron）、キューワーカー、Reverb（`php artisan reverb:start`、ポート 8080）。
