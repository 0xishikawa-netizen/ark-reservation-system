# 詳細設計 10 — データモデル（DB 設計）

関連：基本設計 §8。列単位の経緯・設計判断は `docs/DB_SCHEMA.md` が正本。本書は **2026-10-07 時点の実 DB（89 テーブル）** を「業務領域別のテーブル目録」「主要テーブルの現行列」「状態遷移の一覧」で整理し、`DB_SCHEMA.md` に無い・食い違う箇所を補う。

## 1. 共通規約

| 項目 | 規約 |
|---|---|
| エンジン・文字コード | InnoDB / utf8mb4 |
| 金額 | 整数（円）。unsigned を基本。float を使わない |
| 日時 | `datetime` / `timestamp` は UTC。営業日は `date` 列（JST）を別に持つ（例 `visits.business_date`） |
| 列挙値 | `varchar` に保存し PHP の Enum で型付け（DB の ENUM 型は使わない） |
| 個人情報 | `text` ＋ `encrypted` cast。検索は `*_hmac char(64)` |
| 楽観ロック | `reservations.version` |
| 冪等 | `dedupe_key` / `*_operation_id` / `idempotency_key` / `operation_key` を UNIQUE |
| 追記専用 | 台帳（`ticket_transactions`, `membership_usage_transactions`）、`revenue_allocations`、`reservation_sync_events` は UPDATE / DELETE しない |
| 控え（snapshot） | 名称・税率・属性など「当時の値」を事実テーブルに複製し、マスタ変更を過去へ波及させない |
| 論理削除 | マスタ（メニュー・ブース・商品・スタッフ・回数券商品・月額プラン）は `deleted_at`。未使用のものだけ削除可（`MasterDeletionService`） |
| 書き込み経路 | Controller / Vue から直接更新しない。必ず Action / Domain Service |
| 容量 | バイナリ・外部応答全文・Webhook 本文を保存しない。JSON / TEXT を多用しない |

## 2. テーブル目録

「書き込み元」は、そのテーブルを更新してよい唯一（または主たる）クラス。

### 2.1 利用者・権限・認証

| テーブル | 用途 | 主な制約 | 書き込み元 |
|---|---|---|---|
| users | ログイン主体（顧客・スタッフ共通）。`is_active`、TOTP 列、`password` NULL 可 | email UNIQUE | Fortify Actions、`CreateStaff` 等 |
| customers | 顧客（users と 1:1）。会員番号、PII 暗号化、カルテ、Stripe 顧客 ID | PK=user_id、member_no UNIQUE | `UpdateCustomerProfile` / `UpdateCustomerKarte` / `CreateProvisionalCustomer` |
| staff | スタッフ（users と 1:1）。表示名・色・予約可・並び・電話（MFA） | PK=user_id、論理削除 | `CreateStaff` / `UpdateStaff` / `DeactivateStaff` |
| roles / permissions / model_has_* / role_has_permissions | spatie/laravel-permission | | Seeder、権限設定画面 |
| user_social_accounts | Google 等の外部 ID | UNIQUE(provider, provider_user_id) | `GoogleAuthController` |
| trusted_devices | MFA 信頼済み端末 | selector UNIQUE | `TrustedDeviceService` |
| mfa_sms_challenges | SMS OTP（ハッシュのみ） | 7 日で削除 | `SmsOtpService` |
| password_reset_tokens / sessions | Laravel 標準 | | |

### 2.2 マスタ

| テーブル | 用途 | 主な制約 |
|---|---|---|
| services | メニュー（所要時間・料金・分類・税区分・オンライン可・担当必須・色・有効） | 論理削除 |
| service_staff | メニュー × 担当可能スタッフ | 複合 PK |
| booths | ブース | 論理削除 |
| booth_service | メニューで使えるブース（無ければ全ブース） | UNIQUE |
| qualifications / qualification_staff / qualification_service | 資格、スタッフの保有資格、メニューの必要資格 | UNIQUE |
| products | 物販商品 | code UNIQUE（NULL 可）、論理削除 |
| ticket_products | 回数券商品 | 論理削除 |
| membership_plans | 月額プラン | 論理削除 |
| service_analysis_categories | 分析分類（M / T / A / M&T / A&T …） | code UNIQUE |
| tax_categories / tax_rates | 税区分、期間付き税率（basis point、`[from, to)`、重複禁止） | |
| payment_methods | 会計の決済方法 | code UNIQUE |
| employment_types / staff_employment_periods | 雇用形態、スタッフの雇用履歴（`[from, to)`） | |
| acquisition_channels / visit_purposes | カルテの選択肢（流入経路・来店目的） | code UNIQUE |
| store_calendar_days | 休業日・特別営業時間（通常日は行なし） | business_date UNIQUE |
| monthly_sales_targets / course_sales_targets | 月の売上目標、コース別目標 | 月 UNIQUE / (月, 種別, ID) UNIQUE |
| settings | キー・値（営業時間・スロット・HOLD・キャンセル規定・ポリシー・受付期間・通知など） | key PK |

### 2.3 予約・勤務

| テーブル | 用途 | 主な制約 | 書き込み元 |
|---|---|---|---|
| reservations | 予約（§3.1） | (external_provider, external_reservation_id) UNIQUE | `ReservationService` |
| reservation_resource_slots | 担当・ブースの占有スロット | **UNIQUE(resource_type, resource_id, slot_start)** | `ReservationService` |
| reservation_segments | 延長などの追加構成 | | `ReservationService::extend` |
| reservation_guest_tokens | ゲスト確認リンク（selector＋ハッシュ、期限） | selector UNIQUE | `GuestReservationTokenService` |
| reservation_notification_dismissals | 新着通知の既読（管理者ごと） | UNIQUE(user_id, reservation_id) | `ScheduleController` |
| staff_shifts | 勤務枠（origin=template / manual） | | `Actions\StaffShift\*` |
| staff_shift_templates / staff_shift_exceptions | 基本シフト、例外日 | 例外：UNIQUE(staff, date) | 同上 |
| staff_schedule_blocks | 予定ブロック | | `ScheduleBlockService` |
| staff_attendances / staff_attendance_breaks | 実勤怠・休憩（UTC 区間、JST 出勤日） | | `StaffAttendanceService` |

### 2.4 決済

| テーブル | 用途 | 主な制約 | 書き込み元 |
|---|---|---|---|
| payments | Stripe 取引（種別・金額・状態・PI ID・返金累計・要対応） | payment_operation_id UNIQUE、stripe_payment_intent_id UNIQUE | `PaymentService` / `ReservationCheckoutSaga` |
| payment_refunds | 返金（理由・操作者必須） | refund_operation_id UNIQUE | `PaymentService` |
| webhook_events | Webhook 受信記録（本文なし） | stripe_event_id UNIQUE | `StripeWebhookProcessor` |
| subscriptions / subscription_items | Cashier 標準（課金契約の記録のみ） | | Cashier |

### 2.5 回数券・月額

| テーブル | 用途 | 主な制約 | 書き込み元 |
|---|---|---|---|
| ticket_wallets | 回数券（残数キャッシュ・期限・状態） | | `TicketLedgerService` |
| ticket_transactions | 回数券台帳（追記のみ） | dedupe_key UNIQUE | 同上 |
| ticket_reservation_usages | 予約 ↔ 回数券 | reservation_id UNIQUE | `TicketReservationService` |
| memberships | 月額会員（§3.5） | stripe_subscription_id UNIQUE、membership_operation_id UNIQUE | `MembershipSubscriptionService` / `MembershipBillingService` |
| membership_usage_transactions | 月額台帳（追記のみ） | dedupe_key UNIQUE | `MembershipLedgerService` |
| membership_reservation_usages | 予約 ↔ 月額 | reservation_id UNIQUE | `MembershipReservationService` |

### 2.6 来店・会計・売上

| テーブル | 用途 | 主な制約 | 書き込み元 |
|---|---|---|---|
| visits | 来店実績（§3.6） | reservation_id UNIQUE、UNIQUE(customer_id, visit_sequence)、completion_operation_id UNIQUE | `VisitCompletionService` / `CheckoutEntryService` |
| visit_treatments / visit_treatment_staff | 施術実績・実担当 | 実担当：UNIQUE(treatment, staff) | 同上 |
| visit_staff_nominations | 指名（来店×スタッフ） | UNIQUE(visit, staff) | 同上 |
| visit_first_purposes | 初診時の来店目的の控え | | `VisitCompletionService` |
| customer_visit_purpose | 顧客の来店目的（複数選択） | | `UpdateCustomerKarte` |
| checkouts | 会計ヘッダ（来店あり／なし） | visit_id UNIQUE（NULL 可） | `CheckoutService` |
| checkout_lines | 会計明細（控え付き） | | 同上 |
| checkout_tenders | 支払内訳 | | 同上 |
| checkout_tender_allocations | 支払の施術等／物販への配分 | UNIQUE(tender, category)、CHECK | 同上 |
| staff_revenue_allocations | スタッフ売上配分 | | 同上 |
| revenue_recognition_contracts / revenue_allocations | 施術日基準の売上配賦 | 利用ごと・operation_key UNIQUE | `RevenueRecognitionService` |
| daily_business_notes | 日別営業記録 | business_date UNIQUE | `DailyBusinessNoteService` |

### 2.7 過去データ

| テーブル | 用途 |
|---|---|
| historical_import_batches | 取込単位（原本の保護コピー、SHA-256、状態、作成者、取込・無効化日時） |
| historical_import_rows / historical_import_cells | シート・行・セル（暗号化原文＋HMAC、検証結果） |
| historical_metric_values | 出典行に一意に紐づく過去集計値（`dimension` 付き） |
| historical_metric_reviews | 旧値と ARK 値の差異分類・確認記録 |

### 2.8 外部連携・技術

| テーブル | 用途 |
|---|---|
| reservation_provider_mappings / reservation_sync_outbox / reservation_sync_events / reservation_sync_conflicts / reservation_provider_sync_state | 外部予約連携（[09_external_integration.md](09_external_integration.md) §5） |
| audit_logs | 監査ログ（要約のみ） |
| db_size_snapshots | DB 使用量の日次記録 |
| jobs / job_batches / failed_jobs / cache / cache_locks / migrations | Laravel 標準 |

## 3. 主要テーブルの現行列

`DB_SCHEMA.md` に未反映の列を含む実 DB の定義。

### 3.1 reservations

| 列 | 型 | 説明 |
|---|---|---|
| id | bigint PK | |
| customer_id | bigint FK | |
| service_id | bigint FK | |
| staff_id | bigint FK NULL | 担当 |
| is_staff_requested | bool | 指名 |
| staff_gender_preference | varchar(10) NULL | 担当性別の希望 |
| booth_id | bigint FK NULL | |
| starts_at / ends_at | datetime | `ends_at` は施術＋延長＋インターバルを含む |
| buffer_min | smallint unsigned | 終了後インターバル（分） |
| source | varchar(20) | `HOTPEPPER` / `EPARK` / `ARK_WEB` / `PEAK_MANAGER` / `SALON_BOARD` / `EXTERNAL` / `ADMIN` |
| inflow_channel | varchar(20) NULL | `google` / `website` / `instagram` / `direct` |
| payment_method | varchar(20) | `single` / `membership` / `ticket` / `onsite` / `unpaid` |
| payment_status | varchar(20) | 03_payment §2.2 |
| final_amount | int unsigned NULL | 料金調整後の最終金額 |
| payment_expires_at | datetime NULL | 仮予約の期限 |
| status | varchar(24) | 02_reservation §2.1 |
| attended_at / canceled_at | datetime NULL | |
| cancel_reason | varchar(255) NULL | |
| external_provider / external_reservation_id | varchar NULL | |
| sync_status | varchar(16) | `PENDING` / `SYNCED` / `FAILED` / `NOT_REQUIRED` |
| synced_at / sync_error | | |
| version | int | 楽観ロック |
| notes | varchar(1000) NULL | 予約備考（顧客メモ `customers.note` とは別） |
| created_by | bigint FK NULL | |

### 3.2 services

`id, name(100), duration_min, price, category(50) NULL, analysis_category_id NULL, tax_category_id NULL, is_online_bookable, requires_staff, color(7), is_active, sort_order, timestamps, deleted_at`

### 3.3 customers

`user_id PK, member_no char(10) UNIQUE, kana(100), phone text NULL(暗号化), phone_hmac char(64) NULL, birthday text NULL(暗号化), gender(10) NULL, note(1000) NULL, acquisition_channel_id NULL, acquisition_note(100) NULL, visit_purpose_note(255) NULL, referrer_customer_id NULL(自己参照), referrer_name(100) NULL, prefecture(10) NULL, city(50) NULL, stripe_customer_id(40) NULL, created_via(20), timestamps, pm_type / pm_last_four / trial_ends_at（Cashier）`

### 3.4 membership_plans

`id, name(100), price, usage_count_per_period, billing_interval(10)='month', stripe_price_id(40), tax_category_id NULL, is_active, sort_order, timestamps, deleted_at`

### 3.5 memberships

`id, customer_id, membership_plan_id, stripe_subscription_id(40) NULL UNIQUE, membership_operation_id char(36) UNIQUE, pending_operation(20) NULL, status(16), current_period_start / end date NULL, cancel_at_period_end, grace_until datetime NULL, period_available smallint, started_at / canceled_at / last_synced_at NULL, needs_attention, timestamps`

### 3.6 visits

`id, customer_id, reservation_id NULL UNIQUE, business_date, status(16), started_at / completed_at, primary_staff_id / primary_staff_name_snapshot, visit_sequence, future_reservation_exists_at_checkout / future_reservation_snapshot_at, staff_requested_at_checkout / requested_staff_id_at_checkout, nominations_recorded_at, completion_operation_id char(36), checkout_exemption_reason(32), first_visit_gender_snapshot / first_visit_age_years_snapshot, first_visit_karte_snapshot_at / first_visit_acquisition_channel_id / first_visit_referred / first_visit_prefecture / first_visit_city, timestamps`

### 3.7 その他（DB_SCHEMA 未記載）

| テーブル | 列 |
|---|---|
| reservation_guest_tokens | reservation_id、selector(40) UNIQUE、token_hash、last_used_at、expires_at |
| trusted_devices | user_id、selector(40) UNIQUE、token_hash、user_agent(255)、last_used_at、expires_at |
| ticket_reservation_usages | reservation_id UNIQUE、ticket_wallet_id、no_show_policy、status（held / released / consumed）、held_at / released_at / consumed_at |
| membership_reservation_usages | reservation_id UNIQUE、membership_id、period_start、no_show_policy、status（reserved / released / consumed）、reserved_at / released_at / consumed_at |
| reservation_segments | reservation_id、service_id NULL、minutes、kind（extension）、sort_order、created_by |

## 4. 状態遷移の一覧

すべて `Support\StateMachine` の派生クラスで定義し、それ以外の経路で状態列を書き換えない。

| 対象 | クラス | 状態 |
|---|---|---|
| reservations.status | `ReservationStateMachine` | pending_payment / pending_external_sync / confirmed / completed / no_show / canceled / expired |
| reservations.payment_status | `ReservationPaymentStateMachine` | unpaid / pending_payment / authorized / paid / voided / refunded / partially_refunded / failed |
| payments.status | `PaymentStateMachine` | pending / authorized / succeeded / voided / failed / partially_refunded / refunded |
| memberships.status | `MembershipStateMachine` | pending / active / grace / canceling / paused / canceled |
| ticket_wallets.status | （台帳サービス内） | active / exhausted / expired |
| visits.status | （`VisitCompletionService`） | draft / completed / voided |
| checkouts.status | （`CheckoutService`） | draft / finalized / voided |

遷移図は各詳細設計（02 / 03 / 04 / 05）。

## 5. 設定キー（`settings` テーブル、`Support\Settings\Settings`）

| キー | 型 | 既定（`SettingsSeeder`） | 用途 | 変更画面 |
|---|---|---|---|---|
| business_hours.open / close | string | 10:00 / 22:00 | 通常の営業時間（店舗カレンダーで日ごとに上書き） | 業務マスタ |
| reservation.slot_minutes | int | 5 | スロット幅 | — |
| reservation.hold_minutes | int | 10 | カード払い仮予約の HOLD 分 | 予約規定 |
| reservation.cancellation_tiers | json | 48h:100% / 24h:50% / 0h:0% | キャンセル返金率 | 予約規定 |
| reservation.no_show_refund_percent | int | 0 | 無断キャンセルの返金率 | 予約規定 |
| ticket.no_show_policy | string | restore | 回数券の無断キャンセル時 | 回数券規定 |
| ticket.expiration_hold_policy | string | preserve_hold | 期限切れ時の押さえ分 | 回数券規定 |
| membership.no_show_policy | string | consume | 月額の無断キャンセル時 | — |
| booking.horizon_mode / release_day_of_month / horizon_days / min_lead_minutes | string / int | **未設定（＝無制限）** | 予約受付期間・直前締切。Seeder に入れない（既存環境の後方互換） | 勤務枠 › 予約受付 |
| sales.target.default_amount | int | 未設定 | 月の売上目標の既定 | 業務マスタ |
| 通知設定（新着予約の通知音など） | — | — | `Domain\Notification\NotificationSettings` | 通知 |

## 6. 主要な一意制約（二重処理の防止）

| 防ぎたいこと | 制約 |
|---|---|
| 二重予約 | `reservation_resource_slots(resource_type, resource_id, slot_start)` |
| 1 予約の二重来店 | `visits.reservation_id` |
| 来店順の重複 | `visits(customer_id, visit_sequence)` |
| 完了処理の二重実行 | `visits.completion_operation_id` |
| 1 来店 2 会計 | `checkouts.visit_id` |
| 台帳の二重追記 | `ticket_transactions.dedupe_key`、`membership_usage_transactions.dedupe_key` |
| 予約 1 件で権利を二重に使う | `ticket_reservation_usages.reservation_id`、`membership_reservation_usages.reservation_id` |
| 二重 PaymentIntent・二重返金 | `payments.payment_operation_id`、`payments.stripe_payment_intent_id`、`payment_refunds.refund_operation_id` |
| Webhook の二重処理 | `webhook_events.stripe_event_id` |
| 外部予約の二重取込 | `reservation_provider_mappings(provider, external_reservation_id)` |
| Outbox の二重送信 | `reservation_sync_outbox.idempotency_key` |
