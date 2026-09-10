# OPERATIONS

1 人保守前提。**自動復旧できるもの**と**人間判断が必要なもの**を分ける（末尾の切り分け表）。
コマンドは VPS 常駐前提の記法。共有ホスティングは `php artisan ...` を該当パスで実行。

## 1. バックアップ

- `spatie/laravel-backup` を使用。日次で **DB + `storage/app`** をオフサーバー（S3 互換等）へ。
- 保持：7 日 + 4 週。
- 手動取得： `php artisan backup:run`
- 監視： `php artisan backup:monitor`（失敗は `ADMIN_ALERT_EMAIL` へ通知。Phase 8 で「システム状態」にも表示）
- **マイグレーションを含むデプロイの直前は必ず手動 `backup:run`**。

## 2. リストア

1. 対象バックアップ zip を取得・展開。
2. DB： `mysql -h HOST -u USER -p DBNAME < db-dump.sql`（**本番へ流す前に STG で必ず試す**）。
3. `storage/app` を展開先へ配置。
4. `php artisan config:clear && php artisan cache:clear`。
5. 整合性確認： `php artisan tickets:reconcile` / `memberships:reconcile` / `reservations:reconcile-payments`。
- **月次リストア訓練**：STG で毎月 1 回、最新バックアップからの復元を実施し所要時間を記録。

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
3. **30 日超 / event 消失 / 状態不明**： `php artisan reservations:reconcile-payments`
   （Stripe の PaymentIntent / Invoice / Refund オブジェクトを ID で取得し local を突合・修復）。
   サブスク側は `php artisan memberships:reconcile`。
4. `STRIPE_ARCHIVE_WEBHOOK_PAYLOAD=true` を有効にしている場合は、暗号化保管された raw payload から
   `php artisan stripe:replay --from-archive {stripe_event_id}` で再処理可能（DB は使わない）。
5. 署名検証失敗が続く場合：`STRIPE_WEBHOOK_SECRET` が該当エンドポイントのものか確認。

## 6. 外部予約同期失敗時

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
php artisan backup:run
php artisan backup:monitor
php artisan model:prune
php artisan reservations:expire-pending
php artisan reservations:expire {id} --reason=
php artisan reservations:reconcile-payments [--dry-run] [--fix]
php artisan reservations:reconcile-slots  [--dry-run] [--fix]
php artisan tickets:reconcile             [--dry-run] [--fix]
php artisan memberships:reconcile         [--dry-run] [--fix]
php artisan db:snapshot-size
php artisan stripe:replay {event_id} [--from-archive]
php artisan queue:failed / queue:retry all / queue:restart
```
（コマンドは Phase 3〜8 で順次実装。未実装のものは各 Phase の受け入れ条件に含める。）
