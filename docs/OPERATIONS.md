# OPERATIONS

1 人保守前提。**自動復旧できるもの**と**人間判断が必要なもの**を分ける（末尾の切り分け表）。
コマンドは VPS 常駐前提の記法。共有ホスティングは `php artisan ...` を該当パスで実行。

## 1. バックアップ（Task 11-33 で実装）

- **方式**：`spatie/laravel-backup` ^10.3。もともと本書で採用を決めていたもので、保守が続いており Laravel 13 に対応し、世代管理・監視・失敗通知を備えているため、独自実装よりコードが少なく 1 人で保守しやすい。DB のダンプにはサーバーに `mysqldump`、復元には `mysql` クライアントが必要（Sail のコンテナには同梱）。
- **対象**：DB（`mysql` 接続。gzip 圧縮）＋ `storage/app`（過去データ取込の原本コピー等）。`vendor` / `node_modules` / 保存先自身は除外。
- **保存先**：ディスク `backups`（`config/filesystems.php`）。既定はローカルの `storage/backups/<APP_NAME>/`（Git 管理外。`.gitignore` 済み）。`BACKUP_DISK_DRIVER` / `BACKUP_DISK_ROOT` で変更する。**オフサーバー保存（S3 互換など）は未設定**：本番では `league/flysystem-aws-s3-v3` の導入と認証情報の設定が必要（未検証）。
- **スケジュール**（`routes/console.php`）：01:00 `backup:clean` → 01:30 `backup:run` → 07:00 `backup:monitor`（いずれも `withoutOverlapping`）。
- **世代**：直近 7 日はすべて、加えて週 1 世代を 4 週（`config/backup.php` の cleanup）。容量上限 `BACKUP_MAX_STORAGE_MB`（既定 5000MB）を超えたら古いものから削除。
- **失敗検知**：失敗・不健全（最新が 1 日より古い／容量超過）は `BACKUP_NOTIFICATION_EMAIL`（未設定なら `ADMIN_ALERT_EMAIL`）へメール。管理画面「システム状態」の「最終バックアップ」に最新日時・サイズ・経過時間を表示（1 日超で異常表示）。
- **暗号化**：`BACKUP_ARCHIVE_PASSWORD` を設定すると zip を暗号化（復元検証コマンドも同じ値で開く）。本番では設定を推奨。
- 手動取得：`php artisan backup:run`（DB だけなら `--only-db`）。
- **マイグレーションを含むデプロイの直前は必ず手動 `backup:run`**。

## 2. リストア

### 2.1 復元検証（毎月・デプロイ前に実施）

```
php artisan ark:backup:verify-restore --database=<検証用DB名>
```

- 最新（または `--backup=` 指定）のバックアップ zip から DB ダンプだけを取り出し、**稼働 DB とは別の DB** に復元して、主要 16 テーブル（予約・枠・決済・返金・顧客・ユーザー・回数券・月額・来店・会計・監査・migrations）の件数を稼働 DB と比較する。`migrations` が一致しない・テーブルが欠ける・取込に失敗した場合は非 0 終了。件数差はバックアップ後の書き込みの可能性があるため警告に留める。
- 安全装置：復元先が稼働 DB と同名（大文字小文字を区別しない）なら拒否／DB 名は英数字と `_` のみ／zip 内の `../` 等を拒否／ダンプに `USE`・`CREATE DATABASE` 等の DB 切替文があれば取込前に拒否（稼働 DB への誤書込み防止）／パスワードはコマンドライン引数に出さない。
- 復元先 DB の作成権限が必要。開発環境のアプリ用ユーザーは `testing%` にだけ作成権限があるため `--database=testing_restore_check` を使う。
- **実施記録（開発環境・2026-10-07）**：`backup:run` → `ark:backup:verify-restore --database=testing_restore_check` で主要 16 テーブルの件数がすべて一致。追加で全 89 テーブルの存在と、reservations / payments / customers / ticket_transactions の `CHECKSUM TABLE` 一致を確認した。本番の保存先（S3 等）からの復元は未検証。

### 2.2 本番の復元手順

1. 対象バックアップ zip を取得し展開（暗号化していればパスワードを使う）。
2. DB ダンプ `db-dumps/*.sql.gz` を `gunzip`。
3. **新しい DB を作って**そこへ取り込む：`mysql -h HOST -u USER -p NEW_DBNAME < dump.sql`（稼働 DB へ直接流さない。**本番へ流す前に STG で必ず試す**）。
4. 取り込んだ DB で整合性確認：`php artisan tickets:reconcile` / `memberships:reconcile` / `payments:reconcile`（いずれも既定は読み取りのみ）。
5. 問題なければ `.env` の `DB_DATABASE` を新 DB に切り替え、`storage/app` を展開先へ配置。
6. `php artisan config:clear && php artisan cache:clear`。
- **月次リストア訓練**：STG で毎月 1 回、最新バックアップから 2.1 の検証を実施し所要時間を記録。

### 2.3 運用上の注意（Task 11-33）

- **返金は必ずアプリ（管理画面「決済」）から行う**。Stripe ダッシュボードで返金した場合も、ARK は返金前と Webhook で Stripe の返金累計を取り込み、上限を超える返金はしない（二重返金防止）が、返金理由・担当者の記録（`payment_refunds`）はアプリから行った返金にしか残らない。
- **管理画面の信頼済み端末**は 365 日 MFA を省略し、管理画面は無操作でも自動ログアウトしない（`SESSION_POLICY.md`）。店舗の共用端末・持ち出し端末では 2 段階認証画面で「この端末を信頼する」を選ばない。端末を紛失したら該当スタッフの TOTP を再設定する（全信頼済み端末が無効になる）。
- **SMS 送信**は現状ログ出力のみ（`MFA_SMS_DRIVER=log`）。本番で SMS の予備認証・ゲスト予約検索を使うには送信事業者の実装と設定が必要。

## 3. デプロイ / ロールバック

DEPLOYMENT.md §4-5 参照。要点のみ：
- デプロイ： build → `current` 切替 → `migrate --force`（migration があるとき）→ `*:cache` → `queue:restart`。
- ロールバック：`current` を前リリースへ。DB は原則ロールフォワード。

## 4. Queue 停止時

症状：予約確定メールが来ない / 外部同期が進まない / `failed_jobs` が増える / システム状態の「Queue 最終実行」が古い。

1. cron 稼働確認： `crontab -l` に `schedule:run` があるか、`php artisan schedule:list`。
2. worker 確認：VPS は `supervisorctl status`。落ちていれば `supervisorctl restart all`。
3. 滞留確認： `php artisan queue:monitor database:default --max=100`。
4. 詰まりジョブ： `php artisan queue:failed` → 原因を潰してから `php artisan queue:retry all`。
5. デプロイ直後に固まる場合は `php artisan queue:restart`（worker はコード更新後に自動終了する設計）。

## 5. Stripe Webhook 失敗時

症状：`webhook_events.status = failed` / 決済は Stripe 側成功だが `payments` に反映なし。

1. `webhook_events` を確認（`stripe_event_id` / `type` / `error`）。
2. **発生から 30 日以内**： `php artisan stripe:replay {stripe_event_id}`
   （Stripe から event を再取得 → 冪等ハンドラで再処理）。
3. **30 日超 / event 消失 / 状態不明**： `php artisan payments:reconcile --stripe`（差異の検出。安全な派生状態だけ直すなら `--repair`。金銭操作はしない）
   （Stripe の PaymentIntent / Invoice / Refund オブジェクトを ID で取得し local を突合・修復）。
   サブスク側は `php artisan memberships:reconcile`。
4. （未実装）raw payload の暗号化保管と `--from-archive` による再処理は実装されていない。30 日超は 3. の突合で回復する。
5. 署名検証失敗が続く場合：`STRIPE_WEBHOOK_SECRET` が該当エンドポイントのものか確認。

## 6. 外部予約同期失敗時

> 注（2026-10-07）：本節は Phase 1 時点の設計（`sync_logs` / `PushReservationJob`）の記述で、現在の実装は Outbox 方式（`reservation_sync_outbox` / `reservation_sync_events`、管理画面「外部連携」から再送）。実 API 未接続のため運用対象外。正本は `docs/specs/detailed/09_external_integration.md`。

症状：`reservations.sync_status = FAILED` / 管理画面「同期失敗」リストに件数。

1. `sync_logs`（`provider` / `operation` / `error` / `attempts`）を確認。
2. 一時エラー（タイムアウト・5xx）：管理画面の「再実行」→ `PushReservationJob` を再投入（冪等。既存 `external_reservation_id` があれば update に切替）。
3. 恒久エラー（バリデーション・権限・枠なし）：**人間判断**。手動で外部台帳（Peak Manager 等）に登録し、
   `php artisan reservations:link-external {reservation_id} {external_id}` で突合を記録。
4. `RESERVATION_AUTHORITY != local` で push 失敗のまま放置された予約は顧客未確定。
   ポリシーに従い枠・HOLD を解放するか、スタッフが電話で確定する。

## 7. 仮予約（pending_payment）の滞留

症状：管理画面「仮予約滞留」が 0 でない / 台帳に押さえられたままの枠。

1. `php artisan reservations:expire-pending`（毎分スケジュール。手動実行可）。
2. 個別に強制失効： `php artisan reservations:expire {reservation_id} --reason="manual"`。
   → `status=expired`、`reservation_resource_slots` と回数券/利用権 HOLD を解放。
3. 多発する場合：Stripe の決済完了 Webhook が届いているか（§5）、`RESERVATION_HOLD_MINUTES` が短すぎないか確認。

## 8. 残数不整合（回数券 / 利用権）

1. `php artisan tickets:reconcile --dry-run` / `php artisan memberships:reconcile --dry-run` で差分を表示。
2. 差分があれば内容を確認し、`--fix` で `ADJUST` トランザクションを追記して補正（キャッシュも再計算）。
   金額に影響する補正は manager 承認 + 監査ログ必須。

## 9. DB 容量確認

- 管理画面「システム状態」に現在値 + トレンド（`db_size_snapshots`）。
- 手動：
  ```sql
  SELECT table_name,
         ROUND((data_length+index_length)/1024/1024, 1) AS mb
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
  ORDER BY mb DESC;
  ```
- 閾値（`DB_SIZE_ALERT_MB`、既定 5GB の 70%）超過でメール通知。
- 肥大時の対処：`php artisan model:prune`（保持期間適用）→ それでも減らないテーブルを上記クエリで特定 →
  `sync_logs` / `webhook_events` / `audit_logs`（低価値カテゴリ）/ `reservation_resource_slots`（過去分）を優先的に prune。
  業務データ（顧客・予約・台帳・決済）は prune しない。

## 10. ログ確認

- アプリログ：`storage/logs/laravel-YYYY-MM-DD.log`（daily、`LOG_DAILY_DAYS` 日で自動削除）。
- 失敗ジョブ：`php artisan queue:failed` / DB の `failed_jobs`。
- 監査：`audit_logs`（管理画面から検索可能にする。Phase 8）。
- Stripe：`webhook_events`（DB）+ Stripe ダッシュボードの Events。
- 外部同期：`sync_logs`（DB）+ 管理画面「同期失敗」。

## 11. 自動復旧 / 人間判断の切り分け

| 事象 | 対応 |
|---|---|
| 一時的 API エラーで Job 失敗 | **自動**（retry / backoff）。上限超で「要対応」へ昇格 |
| 仮予約 pending_payment の放置 | **自動**（毎分 `reservations:expire-pending`） |
| Webhook 取りこぼし（30 日以内） | **半自動**（`stripe:replay {event_id}`） |
| Webhook 取りこぼし（30 日超）/ 状態不明 | **半自動**（`*:reconcile` で Stripe オブジェクトから再構築） |
| 決済成功後に外部予約登録が失敗（authority=外部） | **半自動**（補償 Saga：authorization cancel / capture 済みなら自動返金。冪等。結果を管理画面で確認） |
| 孤立決済（決済成功・予約なし）/ 二重課金疑い | **人間判断**（管理画面「要対応」→ 返金 or 手動予約を担当が決定） |
| 外部同期の恒久的失敗 | **人間判断**（手動突合 or 手動で外部台帳へ登録） |
| 残数不整合（reconcile が差分検出） | **人間判断**（差分を確認し `ADJUST` で補正、manager 承認） |
| DB 容量が閾値超過 | **半自動**（`model:prune`）→ 減らなければ **人間判断**（保持期間見直し） |
| バックアップ失敗が 2 日連続 | **人間判断**（ストレージ資格情報 / 容量 / 権限を確認） |

## 11b. 管理者アカウントの復旧（MFA ロックアウト）

**最も危険なのは「単一 admin の自己ロックアウト」。**
認証アプリ（TOTP）・電話・Recovery Code をすべて失うと、正規の復旧手段は無い（裏口は意図的に作っていない）。
（Phase 9.6 で Passkey は撤去。MFA 手段は TOTP のみ。）

### 予防（必ず守る）

1. **admin は 2 名以上**にする。1 名運用なら次を必須にする。
2. **Recovery Code を紙で保管**する（生成時のみ表示。再生成すると旧コードは無効）。
3. 認証アプリの QR / secret を安全に控える（端末紛失時の再設定用）。
4. 業務ロールは TOTP を無効化できない（`PreventStaffTotpDisable`）。端末変更は「無効化」ではなく
   認証アプリ側で新しい QR を読み込んで再設定する。

### 症状別の対応

| 症状 | 対応 |
|---|---|
| 認証アプリを変えたい / 端末を機種変更した | ログイン後 `/admin/mfa` →「認証アプリの再設定」で新しい QR を発行して読み込む |
| 認証アプリが使えない | **Recovery Code** でログイン → 直ちに `/admin/mfa` で再設定 |
| Recovery Code も失った / admin が 1 名だけ | 他の admin が対象ユーザーの `two_factor_*` を null にして再設定させる。**必ず理由を記録し監査に残す** |
| admin が 1 名でその 1 名が失った | サーバーに SSH できる運用者が `php artisan tinker` で当該ユーザーの MFA 資格情報を削除する。**この操作は最後の手段。実施したら必ず記録する** |

```
# 最終手段（サーバー上で実行。実施記録を必ず残すこと）
# 対象ユーザーの MFA 資格情報を消し、次回ログイン後に再設定させる
User::where('email', '対象アドレス')->update([
    'two_factor_secret' => null,
    'two_factor_recovery_codes' => null,
    'two_factor_confirmed_at' => null,
]);
```

- **パスワード（または Google）だけで `/admin` に入れる状態にはしない。** 上記の後、対象ユーザーは
  `/admin` へ入る前に MFA 設定画面へ誘導される（Google ログインでも同じ）。

### SMS が届かない

SMS は**フォールバック**であり、これだけでは MFA 要件を満たさない。
届かない場合は TOTP / Recovery Code を使う。
（実 SMS provider は未契約。現在は `log` ドライバのため実送信されない。）

## 12. 運用コマンド一覧

```
# バックアップ（§1-2）
php artisan backup:run [--only-db]
php artisan backup:clean
php artisan backup:monitor
php artisan ark:backup:verify-restore --database=<検証用DB> [--backup=<zip>]
# 決済
php artisan payments:expire [--limit=200]
php artisan payments:reconcile [--stripe] [--repair] [--limit=500]
php artisan payments:settle-pending-refunds [--dry-run]
php artisan stripe:replay {event_id}
# 回数券・月額
php artisan tickets:expire [--dry-run]
php artisan tickets:reconcile
php artisan memberships:grant-current [--dry-run]
php artisan memberships:expire-grace [--dry-run]
php artisan memberships:reconcile
# 予約・勤務枠・外部連携
php artisan reservations:prune-slots [--days=] [--dry-run]
php artisan shifts:generate [--staff=]
php artisan reservations:dispatch-outbox [--sync]
php artisan reservations:poll-external [--sync]
php artisan reservations:reconcile-providers [--provider=] [--dry-run]
php artisan reservations:prune-sync-logs [--dry-run]
# システム
php artisan db:snapshot-size
php artisan system:prune-technical-logs [--dry-run]
php artisan queue:failed / queue:retry all / queue:restart
```
（2026-10-07 に `routes/console.php` と `app/Console/Commands` の実在コマンドへ揃えた。各コマンドの実行時刻は `docs/specs/detailed/08_batch_operations.md` §2。）
（コマンドは Phase 3〜8 で順次実装。未実装のものは各 Phase の受け入れ条件に含める。）
