# ARK Conditioning 予約・決済システム — 設計プラン (rev.6)

> これは承認済みの設計正本。実装は本プランと `docs/ARCHITECTURE.md` / `docs/DB_SCHEMA.md` に従う。
> Phase ごとの実装可否と Current Task は本書および対応する `docs/tasks/phase-XX.md` の両方で管理する。

## Current execution status

- **Current Phase**: Phase 11 — Reporting / Business Automation（実装・原本書式互換・ローカル実操作確認は完了、旧資料との実数値照合のみ外部資料待ち）
- **Current Task**: Task 11-23（時間帯別稼働率の表示拡張）
- **Task status**: Task 11-11 DONE。Task 11-13 BLOCKED（旧Excel / Google Sheetsの実数値照合のみ）。Task 11-14 DONE。Task 11-15 DONE。Task 11-16 DONE。Task 11-17 DONE（2026-09-26）。Task 11-18 DONE（2026-09-26、Reports・ブッキングボード・設定メニューのUI/UX統一。`docs/REPORTS_UI.md`）。Task 11-19 DONE（2026-09-27、来店・会計入力）。Task 11-20 DONE（支払配分・税抜・物販）。Task 11-21 DONE（顧客カルテ・顧客統計）。Task 11-22 DONE（スタッフ別売上・指名売上）。2026-09-27にユーザーが11-19〜11-26と11-13再開を一括承認（1 Taskずつ順に実施）。
- **Task specification**: `docs/tasks/phase-11.md`
- Phase 11 全体の一括実装は許可しない。現在承認済みの実装Taskはない。次回以降は実物帳票サンプルとの比較結果を踏まえて新Taskを承認してから着手する（Cloud引継ぎ: `docs/handoff/2026-09-26-cloud-handoff.md`）。Task 11-13の実原本との実数値照合は資料受領後に再承認・再開する。ローカル実操作・自動検証の証跡は`docs/PHASE11_OPERATIONAL_VERIFICATION.md`および`docs/EXCEL_EXPORT.md`。

## Context

ARK Conditioning の既存 WordPress サイト（`ark-conditioning.com`、お名前.com レンタルサーバー）に、
自社予約・事前決済・単発決済・月額サブスク（利用権）・回数券・顧客マイページ・店舗管理画面・顧客管理・会計/決済管理を追加する。

現在の予約は `Hot Pepper Beauty ⇅ Peak Manager`、`EPARK → Peak Manager` の集約構成。
将来的には `Hot Pepper / SALON BOARD ⇅ 自作予約システム` にしたいが、外部 API 有無・利用料・双方向同期仕様・
どちらが System of Record かが未確定。→ **外部予約連携部分だけを差し替え可能に抽象化**し、それ以外を先に完成させる。

**実顧客・実予約・実個人情報・実決済を扱う。「動くこと」より、二重処理が絶対に起きにくいこと・
障害時に 1 人で原因追跡と復旧ができることを最優先する。** 過度に複雑なアーキテクチャ・将来使うか分からない機能の先行実装はしない。

既存 WordPress・本番 DB・本番 Stripe には一切接続しない。`ark-system-proposal/` は別物（営業スライド）。触らない。

## 1. 設計原則と優先順位

優先順位（上が優先）：
1. 安全性　2. データ整合性　3. 保守性（1 人運用）　4. バックアップ/復旧　5. 予約・決済の確実性
（以降、上位を損なわない範囲で）6. 操作性　7. 管理画面 UX　8. パフォーマンス　9. DB 容量効率
（将来、上位を満たした後）10. 高度な分析　11. レポート自動送信　12. その他の業務自動化

**ルール：高機能化によって保守性や安全性が下がる設計は採用しない。**

アーキテクチャ原則：
- モジュラーモノリス。マイクロサービス化しない。Laravel 1 アプリ。
- モジュール分割：`Reservation` / `Customer` / `Ticket` / `Membership`（利用権）/ `Payment`（Stripe 課金）/ `Reporting` / `ExternalIntegration`。
- YAGNI：複数店舗対応・LINE 連携・高度分析は今は作らない（拡張余地だけ残す）。

1 人保守の最終原則：
- モジュラーモノリス維持。不要な抽象化を増やさない（レイヤーは Domain / Service / Action / Model + 外部通信の Gateway のみ）。
- 重要処理は State Machine / Action / Service の入口を 1 つにする。
- **DB 更新を Controller や Vue から直接行わない**。必ず Action / Service 経由。
- 金銭・予約・回数消化の**自 DB 内 1 原子操作（残数チェック + 台帳追記 + slot 確保 + キャッシュ更新など）は短い DB transaction 1 つで完結**。
  **外部 HTTP 通信（Stripe / Gateway）中に DB transaction を開いたままにしない**。外部を跨ぐ整合性は State Machine + Saga/Compensation + Idempotency。
- すべての外部通信ジョブ・状態遷移は冪等。失敗状態は管理画面で確認できる。手動復旧手順は `OPERATIONS.md`。
- 自動復旧できるもの（retry で解決）と人間判断が必要なもの（孤立決済・二重課金疑い）を分け、後者は「要対応」表示。

## 2. 技術選定（確定）

| 項目 | 採用 |
|---|---|
| バックエンド | Laravel 13（PHP 8.3+）/ モジュラーモノリス |
| フロント統合 | Inertia.js（SPA+API 完全分離にはしない。1 アプリ） |
| UI | Vue 3 + TypeScript + Vuetify 3 |
| 業務固有 UI | 予約台帳は Vue 専用コンポーネント自作（Vuetify カレンダーに依存しない） |
| 決済（課金） | Stripe + laravel/cashier（課金契約のみ）+ Payment Element。初期は必ず Test Mode |
| DB | MySQL（実バージョンは Phase 0 で確認） |
| 認証 | Laravel 標準 + spatie/laravel-permission。**web guard 1 つ** + Role/Permission/Policy |
| 管理者保護 | TOTP MFA + 短い idle timeout + `/admin` 認可 + 機微操作の再確認。複数 guard は使わない |
| キュー | Laravel Queue（DB ドライバ）+ cron `schedule:run` |
| バックアップ | spatie/laravel-backup（DB+storage、オフサーバー保管） |
| テスト | Pest/PHPUnit + Factory/Seeder。整合性・同時実行は必須 |

顧客側＝スマホファースト。管理側＝PC・タブレットファーストの高機能 UI。
1 アプリ内に顧客エリア `/` と管理エリア `/admin` を route group + middleware + 別 Vue ページツリーで共存。guard は分けずロール + `/admin` 認可 middleware で分離。

ホスティング：Laravel 13 + Inertia + Vuetify + キュー + 予約台帳は共有レンタルサーバーでは要件を満たせない可能性が高い。
Staging/Prod は小規模 VPS を推奨。ローカル開発は環境非依存。

## 3. アーキテクチャ / System of Record 切替

`config/reservation.php` の `authority`（env `RESERVATION_AUTHORITY`）：

| 値 | 予約の正本 | 顧客へ「予約完了」を出すタイミング | 外部通信 |
|---|---|---|---|
| `local`（既定・開発時） | 自作 DB | 自作 DB へ commit した時点 | 任意（best-effort。失敗しても予約有効） |
| `peak_manager` | Peak Manager | 外部予約登録が成功した後のみ | 必須 |
| `salon_board` | SALON BOARD | 外部予約登録が成功した後のみ | 必須 |

- `authority != local` で外部登録前は `reservations.status = pending_external_sync`。
  `pushReservation()` 成功で `confirmed`、失敗で `sync_failed`（顧客は再試行、枠・HOLD 解放）。
- `gateway`（env `EXTERNAL_RESERVATION_GATEWAY` = `null` / `peak_manager` / `salon_board`）は `authority` と独立。
- 開発既定：`RESERVATION_AUTHORITY=local` / `EXTERNAL_RESERVATION_GATEWAY=null`。

## 4. WordPress との分離

別リポジトリ・別 DB（`64ssq_ark_db` に触れない）・別ドメイン（`member.ark-conditioning.com`）。連携は当面ハイパーリンクのみ。

## 5. モジュール構成

`app/Modules/{Reservation,Customer,Ticket,Membership,Payment,Reporting,ExternalIntegration}`、
各配下 `{Models,Actions,Services,Policies,Jobs,Events}`。補助 `app/Support`（`StateMachine` / `Money` / `SlotKey` / `Retention`）。
`ExternalIntegration/Gateways/Reservation/` に `ExternalReservationGateway.php`（interface）,
`NullExternalReservationGateway.php`（既定・「外部なし」を表す・**no-op 禁止 / `UnsupportedOperationException` で fail-fast**）,
`PeakManagerReservationGateway.php` / `SalonBoardReservationGateway.php`（Phase 10）。
モジュールごとの composer package 化・ServiceProvider 乱立はしない。

## 6. DB 設計（詳細は docs/DB_SCHEMA.md）

- バイナリ・API レスポンス全体・webhook payload を DB に保存しない。JSON / TEXT / LONGTEXT を多用しない。過剰 Index を作らない。
- 業務データ（顧客・予約・回数券/利用権の台帳・決済・返金）は永続保持。技術データ（`webhook_events` / `sync_logs` / `audit_logs` / `reservation_resource_slots` / アプリログ）は保持期間つきで `model:prune`。
- キャッシュカラムは 1 集計につき 1 個（`ticket_wallets.balance` / `memberships.period_available`）。台帳追記と同一 transaction で更新、`*:reconcile` で突合。
- 主要テーブル：`users` `customers` `staff` `staff_shifts` `services` `service_staff` `booths`
  `membership_plans` `memberships` `membership_usage_transactions`
  `ticket_products` `ticket_wallets` `ticket_transactions`
  `reservations` `reservation_resource_slots` `payments` `payment_refunds`
  `webhook_events` `sync_logs` `audit_logs` `settings` `db_size_snapshots` + Laravel 基盤。
- `stores` / `store_id` は作らない（単一店舗前提）。`external_links` polymorphic も作らない。`sync_jobs` テーブルも作らない（Queue + `sync_logs`）。

## 7. データ整合性・安全性（最重要）

- トランザクション境界の原則：自 DB 内整合性は短い transaction 1 つ（対象行 `FOR UPDATE` + 台帳追記 + slot 確保 + キャッシュ更新）。
  Stripe / Gateway への HTTP は transaction の外。外部を跨ぐ整合性は State Machine + Saga/Compensation + Idempotency。
- State Machine（`app/Support/StateMachine` 経由でのみ遷移）：
  - `reservations.status`: `pending_payment → pending_external_sync|confirmed` / `pending_payment → expired` / `pending_external_sync → confirmed|sync_failed 扱い` / `confirmed → completed` / `confirmed|pending_external_sync → canceled|no_show`
  - `reservations.payment_status`: `unpaid → pending_payment → authorized → paid` / `pending_payment|authorized → failed → unpaid` / `authorized → voided` / `paid → refunded|partially_refunded`
  - `payments.status`: `pending → authorized → succeeded(=capture 済み) → partially_refunded|refunded` / `partially_refunded → refunded` / `pending|authorized → voided` / `pending|authorized → failed`
    （**後退遷移は定義しない**。古い webhook で巻き戻さない）
    - authorize 成功＝`authorized`（顧客表示「予約確保中」）。**capture 成功後のみ `paid`/`succeeded`、顧客へ「決済完了」**。
  - `ticket_wallets.status`: `active → exhausted|expired`
  - `memberships.status`: `active → paused → active` / `active|paused → canceled`
- 仮予約：単発決済は `status=pending_payment` + `payment_expires_at`（既定 10 分）で枠 HOLD。`ExpirePendingReservationsJob`（毎分）が期限切れを `expired` にし slot・回数券/利用権 HOLD を同一 transaction で解放。
- **二重予約の DB レベル保証**：`reservation_resource_slots(resource_type, resource_id, slot_start)` **UNIQUE**。
  予約 0 件から同時 2 リクエストでも後発の slot INSERT が一意制約違反で rollback（アプリのチェック漏れに依存しない）。
  `UNIQUE(staff_id, starts_at)` のような粗い制約は使わない。
- 回数券 / 利用権（追記型台帳・二重減算禁止）：`available = SUM(delta)`（RESERVE 系は既に負）、`held = 未解消 RESERVE 本数`、`total = available + held`。
  「`SUM(delta) − 予約中`」は禁止。すべての追記は `dedupe_key` UNIQUE で冪等。
- 決済と外部登録の補償 Saga（`authority != local`）：
  `capture_method=manual` で authorize → `pushReservation()` → 成功で capture（`paid`・「予約完了」＋「決済完了」）/ 失敗で authorization cancel（`voided`・`expired`）。
  capture 済みで後段失敗 → 自動返金 → `refunded` → `expired`。
  各ステップ・補償ステップは**論理的な決済試行ごとに安定した** Idempotency-Key で冪等（二重返金・二重取消・二重 capture 防止）。
  key は `payments.payment_operation_id`（UUID・DB 永続）と `payment_refunds.refund_operation_id`（UUID・DB 永続）からのみ導出する:
  `pi-create:{payment_operation_id}` / `pi-capture:{payment_operation_id}` / `pi-cancel:{payment_operation_id}` / `refund:{refund_operation_id}`。
  **retry 回数・時刻・乱数・理由文字列を key に含めない**（rev.5 の `:{attempt}` / `:{reason_hash}` 案は Phase 5 で撤回。
  前者は retry ごとに key が変わり二重課金を招き、後者は同一理由の 2 回目の部分返金が握り潰される）。
  新しい `payment_operation_id` を発行してよいのは、顧客が明示的に**新しい決済試行**を開始したときだけ。途中失敗は `failed_jobs` + 「要対応」。
- 顧客に「成功」を出すのは自作 DB へ commit 確定時のみ。`authority != local` は `pushReservation()` 成功後のみ「予約完了」。決済完了表示は capture 完了後のみ。

## 8. 外部予約ゲートウェイ

`ExternalReservationGateway` interface：`capabilities()` / `fetchAvailability()` / `pushReservation()` / `updateExternalReservation()` / `cancelExternalReservation()` / `pullReservations()`。
- 自作 DB の予約 CRUD は `Reservation` モジュールの `ReservationService`（唯一の入口）。Gateway は外部通信のみ・DB に触れない。
- `NullExternalReservationGateway`（既定）：`capabilities()` すべて false。他メソッドは **no-op 成功にせず `UnsupportedOperationException` で fail-fast**。
  `ReservationService` は `capabilities()` が false のとき Gateway を呼ばない（テストで保証）。空き枠は `staff_shifts` + 既存予約からローカル算出。
- push は `PushReservationJob`（retry + backoff、冪等）。pull は `PullReservationsJob`（`external_reservation_id` UNIQUE で二重取込防止）。
- 契約テスト：`Null` と「記録用フェイク」の両方が同一契約テストを通過。

## 9. Stripe（課金のみ）

- laravel/cashier は課金契約の管理専用。「月何回」は Membership モジュールが持つ。
- 初期は必ず Test Mode。`APP_ENV in (local,testing)` で Live キー検出 → 起動時例外。
- 保存は ID と要約のみ。カード情報・レスポンス全体・webhook payload は保存しない。
- Idempotency-Key を create / capture / cancel / refund の操作ごとに安定生成（§7 のとおり operation ID から導出。retry で変化させない）。
- Webhook：署名検証必須、`webhook_events.stripe_event_id` UNIQUE で冪等化。
- 復旧：A. `stripe:replay {event_id}`（約 30 日）/ B. Stripe オブジェクトから `*:reconcile`（無期限・本命）/ C.（任意）raw payload を暗号化して DB 外へ 30〜90 日保管。

## 10. 回数券・利用権の台帳

- 回数券 `ticket_transactions`：PURCHASE / RESERVE_HOLD / RESERVE_RELEASE / CONSUME / GRANT / REVOKE / EXPIRE / ADJUST。消化元 wallet は FEFO。1 予約 = 1 wallet。
- 利用権 `membership_usage_transactions`：GRANT（期首 +included）/ RESERVE / RELEASE / CONSUME / ADJUST。当期 available = 当該 `period_start` の `SUM(delta)`。
- すべての追記は `dedupe_key` UNIQUE で冪等。キャッシュは同一 transaction で更新。`*:reconcile` で突合。

## 11. 予約フロー

顧客（スマホ）：サービス→担当（任意）→日付→空き枠→支払い方法→確認 → `ReservationService` が 1 transaction で
（reservations 作成 / `reservation_resource_slots` INSERT / 回数券 RESERVE_HOLD or 利用権 RESERVE）→
単発は Stripe PaymentIntent → 成功で confirmed / 失敗・放置で `ExpirePendingReservationsJob` が expired → 確認メール（Mail のみ）。
`authority != local` は §7 の補償 Saga に従い、push 成功後に「予約完了」。

店舗（PC/タブレット）：予約台帳（横=時間 / 縦=スタッフ or ブース、カード、D&D で時間・担当変更、端リサイズで所要時間、
`version` 楽観ロック + slot 付替えを 1 transaction、source 色分け、日/週表示、顧客詳細サイドパネル、会計フロー）。
実装は素の div + CSS grid/absolute + Pointer Events。

## 12. 認証・権限

単一 `web` guard。顧客/スタッフは spatie role（customer / staff / manager / admin）+ Policy で分離。
顧客はセルフ登録 + メール確認 + リセット + rate limit。スタッフは管理者作成。
管理者保護：**MFA 必須**、`/admin` の短い idle timeout、`/admin/*` は deny-by-default、
機微操作（返金・回数券/利用権の付与/取消/調整/期限変更・契約解約）は manager 以上 + 理由必須 + 再認証 + 監査。

**MFA 方式（Phase 5.5 で TOTP 必須へ / Phase 9.6 で Passkey 撤去・TOTP 一本化）**

| 手段 | 位置づけ |
|---|---|
| TOTP（6 桁） | **主手段**。業務ロール（staff/manager/admin）は必須 |
| SMS OTP | フォールバック。**単独では MFA 要件を満たさない**（SIM スワップ耐性が無いため） |
| Recovery Code | 最終復旧（Fortify 標準） |

- 認証フロー：メール・パスワード（または Google）→ 主認証成功 → 業務ロールのみ TOTP チャレンジ。
- 要件判定は `App\Domain\Auth\MfaPolicy` に集約する。`two_factor_confirmed_at` を各所で直接見ない。
  満たす条件：**確認済み TOTP**。
- 業務ロールは **TOTP を無効化できない**（`PreventStaffTotpDisable`）。裏口の master password や
  local だけの MFA バイパスは作らない。
- 再認証は `password.confirm`。
- 顧客には MFA を課さない。
- **Google ログイン（Phase 9.6 / Socialite）**：顧客は利用可。特権ロールは callback から
  自動作成・自動昇格・silent link しない。Google ログインでも業務ロールの TOTP チャレンジは省略されない。
  identity は `user_social_accounts`（`UNIQUE(provider, provider_user_id)`、token 非保存）。

## 13. セキュリティ

Eloquent/Query Builder のみ（バインド必須）/ Vue 既定エスケープ・`v-html` 禁止・CSP / CSRF（Inertia XSRF）/ argon2id /
Stripe Webhook 署名検証・冪等化 / rate limit / `audit_logs` は要約 1 行（スナップショット JSON なし）/
個人情報は必要に応じ `encrypted` cast + 正規化値のキー付き HMAC lookup（平文の検索コピーは持たない）/
`.env` は Git 禁止・環境ごとに別キー / アプリ DB ユーザは最小権限 / 開発は本番 WP DB・本番 Stripe に接続しない。

## 14. 監視・運用（段階的）

Phase 1：`failed_jobs` を管理画面で確認、ログの見方を `OPERATIONS.md`。
Phase 5〜8：決済失敗 / Webhook 失敗 / 同期失敗 / 仮予約滞留のリスト + 再実行、`db_size_snapshots` 日次 + 閾値通知、バックアップ通知。
Phase 8：`Admin/SystemStatus`（Stripe / Reservation Authority / Queue / 仮予約滞留 / 同期失敗 / 最終バックアップ / DB 使用量）。
自動復旧 / 人間判断の切り分け表は `OPERATIONS.md`。

運用コマンド：`backup:run` / `model:prune` / `tickets:reconcile` / `memberships:reconcile` /
`reservations:reconcile-payments` / `reservations:reconcile-slots` / `db:snapshot-size` / `stripe:replay {event_id}` / `reservations:expire-pending`。

## 15. 環境・デプロイ

Local（Sail、`authority=local`、`gateway=null`、Stripe Test、Mailpit）/ Staging（`stg-member...`、`64ssq_ark_test`、Basic 認証 + noindex）/
Production（`member...`、新規 DB、Stripe Live、VPS 推奨）。
デプロイ：GitHub Actions ビルド → Deployer zero-downtime → `migrate --force`（migration 含むときのみ、直前に DB バックアップ）→ `*:cache` → `queue:restart`。
ロールバック：`current` を前リリースへ。DB は原則ロールフォワード。

## 16. フェーズ

| Phase | 内容（要約） |
|---|---|
| 0 設計・環境確認 | 完了。Laravel 13 実アプリ scaffold + Inertia + Vue3 + TS + Vuetify3 + build + test + local 起動。 |
| 1 基盤 | 認証（単一 guard + role）+ スタッフ MFA + `/admin` idle timeout、基盤 migration、`app/Support/StateMachine`、`ExternalReservationGateway` interface + `NullExternalReservationGateway`、Stripe Live キー起動ガード、レイアウト、CSRF/XSS/SQLi + rate limit、`audit_logs` 雛形、`failed_jobs` 可視化、Pest + CI 緑 |
| 2 マスタ | customers / staff（+ shifts）/ services / service_staff / booths + 管理 CRUD |
| 3 自作予約（gateway=null） | reservations + state machine + `reservation_resource_slots` UNIQUE + `ReservationService` + 予約台帳 + 仮予約失効 + 同時実行テスト |
| 4 回数券 | ticket_products / wallets / transactions（追記型・`dedupe_key`）+ FEFO + HOLD/RELEASE/CONSUME/EXPIRE + reconcile |
| 5 Stripe 単発決済（Test） | Cashier / Payment Element / `pending_payment` + `payment_expires_at` / manual capture 経路 / 操作別 Idempotency-Key / Webhook 冪等化 / `stripe:replay` / 孤立決済「要対応」 |
| 5.5 認証強化（MFA） | Passkey（第一選択）/ SMS OTP フォールバック / Recovery Code / TOTP 移行 / `MfaPolicy` / 最後の手段削除禁止 |
| 6 利用権 + Stripe 課金（Test） | membership_plans / memberships / membership_usage_transactions（`dedupe_key`）+ Cashier 課金 + 期首 GRANT + reconcile |
| 7 顧客マイページ | 集約ダッシュボード + 支払い方法管理 + 予約変更/キャンセル（巻き戻し） |
| 8 店舗管理 + システム状態 | ダッシュボード / 予約一覧 / 顧客 360 / 回数券管理 / 契約管理 / `Admin/SystemStatus` / DB 容量スナップショット + 通知 |
| 9 外部予約連携基盤（実装済み・AUTOMATED GREEN） | Provider 非依存基盤 `app/Domain/Integration/*`（Contract / Capability / Resolver / DTO）/ 5 テーブル（mapping UNIQUE 2 本・outbox・append-only events・conflicts・sync_state）/ Inbound（advisory lock + `ReservationService` 経由 + conflict 検出）/ Outbound（Outbox パターン・外部 HTTP は transaction 外・`SKIP LOCKED` + lease + sequence 直列化）/ Reconcile / Mock provider / Peak Manager・SALON BOARD は skeleton（推測実装なし）/ Admin ステータス + 手動 retry / 詳細は `docs/tasks/phase-09.md`。実 API 結合は Phase 10。 |
| 10 Peak Manager / SALON BOARD 連携 | 具象 Gateway + sandbox + 補償 Saga。**両 API 不可時の fallback：予約は現行 Peak Manager をそのまま利用、自作は決済/回数券/Membership/顧客/会計のみ担当** |
| **11 Reporting / Business Automation（実装・UI統一完了、実数値照合待ち）** | 日計・月計・顧客統計・新規/再診/離反/継続率・勤怠/稼働率・時間帯別稼働率・年間実績・既存6シートExcel出力・過去データ取込基盤。Task 11-14〜11-18はDONE。**Current Taskなし。** Task 11-13の旧帳票実績値とARK実績値の照合だけBLOCKED。Google Sheets実書込、SALON BOARD連携、外部予約サイト同期、メール自動送信は対象外。 |

## 17. Codex 実行ガバナンス

Codex が Task 仕様の確認 → 実装 → 検証 → `git diff` による自己レビュー（要件一致 / コード品質 / 安全性・整合性・冪等性 / DB 設計・容量 / 復旧設計 / テスト（同時実行・二重処理を含む）/ WP 影響ゼロ / Gateway 拡張性 / 保守性）→ 是正、を反復する。
実装できるのは、本書の Current Phase と対応する Task 文書の CURRENT / APPROVED が一致する 1 Task だけ。Task 完了後も、次 Task を自動的に Current にせず、明示的な状態更新を待つ。
禁止：Stripe 本番 / Peak Manager・SALON BOARD 実接続 / 本番 DB / WordPress 変更 / Controller・Vue からの直接 DB 更新。

## 18. Open Questions

`docs/OPEN_QUESTIONS.md` を参照（ホスティング / サーバー実測 / 保持期間 / 外部 API / スロット粒度 / 与信二段階 / DNS / 業務ルール / データ移行 / 複数店舗 / Codex 整備 ほか）。

## 19. 外部 API 待ちでも止めない構造

`NullExternalReservationGateway` を既定に Phase 1〜8 は外部 API 非依存。`ExternalReservationGateway` interface + 契約テストでドロップイン差し替え。
`RESERVATION_AUTHORITY` で SoR を後から切替。両 API 不可なら予約は現行 Peak Manager を継続し、自作は決済/回数券/Membership/顧客/会計を担当。

## 20. 検証方法

`composer test`：整合性ロジック（残数定義・state machine・冪等化）は Unit、フローは Feature。
同時実行テスト必須（同一スロット並行予約 / 回数券残 1 で並行 2 予約 / 利用権当期残 1 で並行 2 予約 / 同一 Stripe event 二重送信）。
`npm run build` が CI で通る（TS 型エラー 0）。仮予約失効 / Null Gateway fail-fast / 台帳 `dedupe_key` 冪等 / manual capture 状態 / 補償 Saga（二重返金なし）/ `model:prune` で技術ログのみ削除、を確認。
WP 影響ゼロ：別リポジトリ・別 DB・別ドメイン。
