# Phase 11 / Task 11-1 — 現状調査・データ定義

作成日: 2026-09-24

状態: Task 11-1 完了（Task 11-2設計根拠）

対象: 現在のワークツリーに存在するコード、migration、route、権限、tests、設計文書

## 0. 調査方法・前提

- `docs/PLAN.md:6-12` と `docs/tasks/phase-11.md:6-25` がともに Task 11-1 を `CURRENT / APPROVED` としていることを確認した。
- migration、Model、Domain / Action / Query、Controller / Request、route、権限Seeder、PHP / TypeScriptテスト、依存関係、Excel / CSV / Google Sheets関連ファイルを静的に調査した。
- `./vendor/bin/sail ps` は `Docker or Podman is not running.` だったため、実DBの `migrate:status`、テーブル定義、データ分布は確認していない。以下の「現行」はリポジトリにあるmigrationとコードを正本にした結果である。
- Excel / CSV原本およびGoogleスプレッドシートには接続していない。リポジトリにも対象6シートの原本・セル仕様は存在しない。
- 本書では候補DB構造を定義するが、migrationやアプリケーション実装は行っていない。物理名と分割粒度は後続Taskの開始時に本書の承認を得て確定する。
- 現行 `config/app.php:68` はtimezoneを `UTC` に固定する一方、`.env.example:11` は `APP_TIMEZONE=Asia/Tokyo` である。以下は業務日付を **Asia/Tokyo** とする提案だが、この不一致の解消は実装前必須条件とする。

## 1. 関連既存テーブル一覧

### 1.1 顧客・マスタ・店舗設定

| テーブル | PK / 主なFK・主要カラム・索引 | 現在の責務 | Phase 11で再利用できる範囲 | 根拠 |
|---|---|---|---|---|
| `users` | `id`; name、email UNIQUE、password、is_active | 認証主体、氏名、メール | 顧客・スタッフの共通主体 | `database/migrations/0001_01_01_000000_create_users_table.php:14-22` |
| `customers` | `user_id` PK/FK; kana、暗号化phone/birthday、gender、note、created_via; `phone_hmac` index; `member_no` UNIQUE | 顧客プロフィール、性別、メモ、会員番号 | 顧客軸、性別、初診日時点年齢の元情報。生年月日は復号後に利用し、集計SQLへ直接依存させない | `database/migrations/2026_09_08_000004_create_customers_table.php:13-24`, `database/migrations/2026_09_13_000001_add_member_no_to_customers_table.php:23-42` |
| `staff` | `user_id` PK/FK; display_name、color、is_bookable、sort_order | 表示名、色、予約可否、表示順 | 担当者・勤務・稼働率の軸 | `database/migrations/2026_09_08_000005_create_staff_table.php:13-20` |
| `services` | `id`; name、duration_min、price、category、bookable/requires_staff/active、sort; `(is_active, sort_order)` | 1予約に紐づくメニュー名、標準所要時間、価格、自由文字のcategory | メニュー正本として拡張。現行 `category` は確定分類コードとしては制約不足 | `database/migrations/2026_09_08_000010_create_services_table.php:13-27` |
| `service_staff` | (`service_id`,`staff_id`) 複合PK/FK | メニューを担当可能なスタッフ | 予約可否判定に継続利用。実担当事実には使わない | `database/migrations/2026_09_08_000011_create_service_staff_table.php:13-18` |
| `booths` | `id`; name、sort_order、is_active | ブースマスタ | 予約可能時間判定のリソース | `database/migrations/2026_09_08_000012_create_booths_table.php` |
| `settings` | `key` PK; value、type | 短いtyped key/value設定 | 店舗既定営業時間、予約粒度、売上目標の店舗既定値候補。日別カレンダーや履歴の正本には不適 | `database/migrations/2026_09_08_000006_create_settings_table.php:13-18`, `app/Support/Settings/Settings.php:19-105` |
| `audit_logs` | `id`; actor FK、action、entity_type/id、summary、ip、created_at; action、actor、entity複合、created_at索引 | 操作監査 | マスタ・会計・取込・帳票出力操作の監査入口 | `database/migrations/2026_09_08_000007_create_audit_logs_table.php:13-30`, `database/migrations/2026_09_09_000031_add_created_at_index_to_audit_logs_table.php:18-25` |

### 1.2 予約・勤務予定

| テーブル | PK / 主なFK・主要カラム・索引 | 現在の責務 | Phase 11で再利用できる範囲 | 根拠 |
|---|---|---|---|---|
| `reservations` | `id`; customer/service/staff/booth FK; starts/ends、source、payment method/status、status、attended/canceled、external、final_amount/buffer; starts/status等index; external ID複合UNIQUE | 予約予定、単一メニュー・単一予定スタッフ、予約状態、決済状態、外部同期状態 | 「予約」正本および来店への入口。実来店・実施術・会計の正本にはしない | `database/migrations/2026_09_08_000014_create_reservations_table.php:13-46`, `app/Models/Reservation.php:24-51` |
| `reservation_resource_slots` | `id`; reservation FK; resource_type/id、slot_start; (`resource_type`,`resource_id`,`slot_start`) UNIQUE | スタッフ/ブースの予約枠排他 | 予約可能時間と重複防止。完了施術時間や稼働時間の正本にはしない | `database/migrations/2026_09_08_000015_create_reservation_resource_slots_table.php:13-25` |
| `staff_shifts` | `id`; staff FK; work_date、start_at、end_at、origin; `(staff_id, work_date)` index | 予定勤務区間 | 予約可能枠の予定値。実出退勤ではなく、確定済み稼働率の「勤務時間」正本にはしない | `database/migrations/2026_09_08_000013_create_staff_shifts_table.php:13-22` |
| `staff_shift_templates` | `id`; staff FK; weekday、start/end、is_active; `(staff_id, weekday)` index | 曜日別の勤務予定テンプレート | 将来分の勤務予定生成 | `database/migrations/2026_09_11_000001_create_staff_shift_templates_table.php:18-29` |
| `staff_shift_exceptions` | `id`; staff FK; exception_date、is_off、note; (`staff_id`,`exception_date`) UNIQUE | 休み・勤務予定例外 | 予定勤務の例外 | `database/migrations/2026_09_11_000002_create_staff_shift_exceptions_table.php:21-30` |
| `staff_schedule_blocks` | `id`; staff/booth/creator FK; work_date、start/end、type、title/note; staff/date、booth/date index | 休憩、会議、研修等の予約不可予定 | 予約可能時間分母から除く予定ブロック。実休憩の正本ではない | `database/migrations/2026_09_13_000003_create_staff_schedule_blocks_table.php:21-36` |

### 1.3 決済・回数券・月額

| テーブル | PK / 主なFK・主要カラム・索引 | 現在の責務 | Phase 11で再利用できる範囲 | 根拠 |
|---|---|---|---|---|
| `payments` | `id`; customer/reservation/creator FK; kind、provider、amount/currency/status、Stripe IDs、paid/refunded; operation UNIQUE、Stripe PI UNIQUE | Stripe課金試行・入金状態。単発、追加決済、月額invoiceを保持 | 外部決済トランザクション正本。会計ヘッダや現金/PayPay等の支払内訳マスタではない | `database/migrations/2026_09_08_000020_create_payments_table.php:13-38`, `app/Enums/Payment/PaymentKind.php:7-13` |
| `payment_refunds` | `id`; payment/creator FK; amount、reason、status、Stripe refund/error; `refund_operation_id` UNIQUE | Stripe返金処理と状態 | 決済日基準売上の返金控除・監査 | `database/migrations/2026_09_08_000021_create_payment_refunds_table.php:13-25` |
| `ticket_products` | `id`; name、total_count、price、validity_days、active/sort; active/sort index | 回数券商品、回数、価格、有効日数 | 契約商品元情報。購入時価格・税のスナップショットではない | `database/migrations/2026_09_08_000016_create_ticket_products_table.php:13-24` |
| `ticket_wallets` | `id`; customer/product FK; purchased_count、balance、expires/status; customer/status等index | 顧客の回数券残数キャッシュ | 契約・配賦元との関連先。現状は購入決済へのFKがない | `database/migrations/2026_09_08_000017_create_ticket_wallets_table.php:13-26` |
| `ticket_transactions` | `id`; wallet/reservation/staff FK; type、delta、reason、created_at; `dedupe_key` UNIQUE | 回数券の追記専用残数台帳 | 消化事実、冪等性パターン | `database/migrations/2026_09_08_000018_create_ticket_transactions_table.php:13-27` |
| `ticket_reservation_usages` | `id`; reservation/wallet FK; no-show policy、status、held/released/consumed; `reservation_id` UNIQUE | 予約ごとのHOLD / RELEASE / CONSUME | どの予約で回数券を使用したかの対応 | `database/migrations/2026_09_08_000019_create_ticket_reservation_usages_table.php:13-26` |
| `membership_plans` | `id`; name、price、usage_count、billing_interval、Stripe price、active/sort; active/sort index | 月額プラン、表示価格、期ごとの利用数 | 月額契約元情報。invoice時価格の正本ではない | `database/migrations/2026_09_09_000026_create_membership_plans_table.php:13-25` |
| `memberships` | `id`; customer/plan FK; status、period、available、cancel/grace/sync; operation/Stripe subscription UNIQUE; customer/status等index | 顧客の月額契約状態、期間、利用可能数キャッシュ | 契約軸 | `database/migrations/2026_09_09_000027_create_memberships_table.php:18-43` |
| `membership_usage_transactions` | `id`; membership/reservation/staff FK; period、type、delta、reason、created; `dedupe_key` UNIQUE | 期別利用権の追記専用台帳 | 月額利用事実、期間、冪等性パターン | `database/migrations/2026_09_09_000028_create_membership_usage_transactions_table.php:18-33` |
| `membership_reservation_usages` | `id`; reservation/membership FK; period、policy、status、reserved/released/consumed; `reservation_id` UNIQUE | 予約ごとの月額利用予約・消化 | どの予約・期間で利用したかの対応 | `database/migrations/2026_09_09_000029_create_membership_reservation_usages_table.php:18-32` |

### 1.4 未実装を確認した領域

- `appointment` / 来店 / 施術明細テーブルは存在しない。現行は `reservations.status=completed` と `attended_at` のみである。
- 会計ヘッダ、会計明細、商品マスタ、税区分・適用期間税率、支払方法マスタ、複数支払明細、スタッフ実担当・売上配分は存在しない。
- 実出退勤、実休憩、店舗日別カレンダー、月別売上目標、Reportingモジュール、集計API / route、集計専用permissionは存在しない。
- Excel / CSV生成・読込実装、対象6シート原本、セルマッピング、PhpSpreadsheet等の依存は存在しない。Google Sheets API実装・資格情報・同期routeも存在しない。
- 過去データのimport batch / staging / validation / provenanceテーブルは存在しない。
- メール基盤と予約通知は存在するが、Phase 11帳票のメール自動送信は存在せず、対象外とする。

### 1.5 Service / UseCase・UI・外部連携の現状

- 予約作成・変更・キャンセル・完了・no-showは `ReservationService` の単一入口に集約される（`app/Domain/Reservation/ReservationService.php:57,145,231,391,416`）。予定スタッフは0..1人であり、実担当複数人は表現できない。
- 会計UseCaseは未実装。Stripeの事前決済・追加決済・返金は存在するが、現金等を含む業務会計ではない。管理UIも `Admin/Payments/Index.vue` / `Show.vue` は外部決済閲覧・返金用で、会計画面はない。
- 回数券・月額の予約時HOLDと完了時消化は既存Serviceにある。回数券購入の本番UseCaseは確認できず、管理GRANTは購入会計ではない。月額invoice paymentはStripe webhookから記録される。
- 顧客はWeb/guest登録、管理側仮登録（`app/Actions/Customer/CreateProvisionalCustomer.php`）、プロフィール/管理更新がある。現行更新対象は氏名、かな、電話、生年月日、性別、メモで、来店目的等はない（`app/Http/Controllers/Admin/CustomerController.php:117-170`）。
- staff割当は予約の単一 `staff_id` と `service_staff` 適格性を検証する（`app/Domain/Reservation/ReservationService.php:456-489`）。staff CRUD、勤務予定CRUD、template/exception/generate、schedule block UIがある。
- 管理画面には予約一覧/台帳、顧客、payment、staff、shift、service、ticket、membership、設定、権限、監査がある。会計、report、勤怠実績、店舗カレンダー、売上目標、Excel/import UIはない（`resources/js/pages/Admin` 配下一覧、`routes/web.php:188-460`）。
- メールは `app/Notifications/GuestReservationConfirmed.php` の予約確認がある。帳票送信はない。
- 外部予約連携はprovider境界、outbox、status UI、mockおよびPeak/Salon provider骨格があるが、Phase 11集計やGoogle Sheetsとは無関係であり、実API接続・同期拡張は対象外とする（`app/Domain/Integration`, `routes/web.php:429-435`）。

### 1.6 migration inventory

全migrationを列挙確認した。Phase 11に直接関係する作成/拡張migrationは §1.1〜1.3 のほか、次のグループである。

- 認証・権限・顧客: users、permission、TOTP、customer Cashier列、staff phone、MFA SMS、passkey作成後drop、social account、nullable password、active user、trusted device、guest token。
- 予約: reservation本体、resource slots、provider mapping / outbox / events / conflicts / sync state、adjustment fields、notification dismissal、staff requested、buffer、inflow channel。
- 課金・契約: payment/refund/webhook、Cashier subscriptions/items、ticket 4表、membership 4表、payment operation ID拡張。
- 勤務・店舗: staff shifts、shift templates/exceptions/origin、schedule blocks、settings、booths、services/service_staff。
- 技術: cache、jobs、audit、DB size snapshot。

正確なファイル一覧は `database/migrations/` の60ファイルを確認した。実DB適用状態はDocker停止のため未確認であり、後続migration着手前に `sail artisan migrate:status` で照合する。

## 2. 各概念の責務境界

1. `reservations` は「予定」を表す。来店後にメニュー・担当・金額が変わり得るため、実績を予約行へ上書きしない。
2. `visits`（候補）は顧客が実際に来店した1回を表す。同一来店のメニュー数・担当者数に関係なく1行とする。
3. `treatment_items`（候補）は来店内で実施した施術を表し、実施時間を保持する。予約時の `service_id` は初期値としてのみ利用する。
4. `checkouts` / `checkout_lines`（候補）は請求・売上の業務事実と税スナップショットを表す。
5. `payment_tenders`（候補）は会計に充当した現金、PayPay、Stripe等の支払内訳を表す。`payments` は引き続きStripe等の外部課金トランザクションを表す。
6. 回数券・月額台帳は「権利残数」、契約売上・配賦は「金額」を表し、相互を置換しない。
7. 集計Query / Serviceは上記事実を読み取る派生物とし、日報・月計表そのものを正本として保存しない。
8. 明細を復元できない過去集計値だけは、現行事実と混ぜず `historical_metric_values`（候補）へ出典付きで保持する。

## 3. 再利用可能な構造

- 顧客、スタッフ、メニュー、予約、予定勤務、予約不可ブロック、Stripe決済、返金、回数券・月額の権利台帳、監査ログは既存構造を継続利用する。
- `ReservationService::create()` はメニュー標準時間とbufferを使い、予約・枠・回数券/月額HOLD・Outboxを1 transactionで作成する（`app/Domain/Reservation/ReservationService.php:57-124`）。この原子性とロック規約を後続Taskでも踏襲する。
- `ReservationService::markCompleted()` は予約ロック、completed遷移、`attended_at`、回数券/月額消化を1 transactionで行う（同:391-403）。Task 11-4ではこの入口を分岐させず、会計完了Actionへ統合する。
- 回数券・月額は予約ごとのusage UNIQUEとdedupe key付き追記台帳を持つ（`app/Domain/Ticket/TicketReservationService.php:119-165`, `app/Domain/Membership/MembershipReservationService.php:148-184`）。二重消化防止の設計見本とする。
- `ReservationAdjustmentService::netReceived()` は成功決済額から返金額を引く（`app/Domain/Payment/ReservationAdjustmentService.php:33-42`）。既存Stripe入金突合に再利用できるが、会計・税・支払方法別集計を代替しない。
- `Settings` は短い店舗既定値に限り再利用する（`app/Support/Settings/Settings.php:19-105`）。`booking.closed_dates` はJSON日付配列（`app/Domain/Reservation/BookingWindow.php:75-98`）であり、Task 11-2で店舗カレンダー正本へ段階移行する。
- `AuditLogger`、Role / Permission、`/admin` middleware群、PHPUnit Feature / Unit / ContractとVitest構成を後続Taskで再利用する。

## 4. 不足している構造

- 予約と独立した来店ID、顧客ごとの完了来店順序、初診の一意性。
- 1来店複数メニュー、実施分数、主担当、複数実担当と実担当分数。
- 会計ヘッダ、商品販売、税区分と期間別税率、会計時の税率・税額スナップショット。
- 会計合計と複数支払内訳の一致、Stripe決済との1対1または明示的な関連。
- 回数券購入額・月額invoice額と各利用への施術日基準配賦。現行月額invoice `Payment` は `membership_id` を保存していない（`app/Domain/Membership/MembershipBillingService.php:77-132`）。
- 次回予約有無、初診時プロフィール、年齢帯等の完了時スナップショット。
- 実出退勤・実休憩。`staff_shifts` とschedule blockは予定であり実績ではない。
- 店舗営業日・特別営業時間の正規化テーブル、月別売上目標、商品・支払方法マスタ。
- 共通の集計契約、SQL集計、索引、Excelマッピング、過去取込・照合基盤。

## 5. 新規テーブル候補

### 5.1 共通規約

- 業務テーブルは原則永続保持し、物理削除しない。有効/無効、取消、voidで履歴を残す。法定保存年数は未確定事項 R-10 とする。
- 金額はJPY整数。率は浮動小数点を避けbasis point等の整数。日時はDB保存形式を現行に合わせつつ、業務日への変換は承認後の `Asia/Tokyo` で統一する。
- FKの削除は、業務事実からマスタへは `restrictOnDelete`、操作者は `nullOnDelete` を基本とする。
- NULL可否は各表で `nullable` と明記した列、またはdraft中の未確定列だけに許す。active / closed / completed状態で必須になる列は状態に応じたCHECKとService検証でNULLを拒否する。
- CHECKで行内条件、UNIQUEで冪等性・一意性、transaction内ロックで複数行合計を保証する。MySQL CHECKの実DB対応バージョンはmigration実装時に確認する。
- 事実の集計用indexは先頭を期間列だけに固定せず、実クエリの `EXPLAIN` で確定する。下記は候補である。

### 5.2 マスタ・店舗運用候補（Task 11-2）

| 候補 | PK / 関係 | 主要列・制約 | index / 保持 / 既存移行 |
|---|---|---|---|
| `menu_categories` | `id`; `code` UNIQUE | code=`M`,`T`,`A`,`M&T`,`A&T`、name、active、sort。コード追加可 | active/sort。永続。`services.category` を照合し、曖昧値は自動変換せず要確認 |
| `tax_categories` | `id`; `code` UNIQUE | name、active | 永続。商品種別とは独立 |
| `tax_rates` | `id`; tax_category FK | rate_bps、effective_from、effective_to nullable; rate 0..10000、from<to | (`tax_category_id`,`effective_from`) UNIQUE/index。期間重複はService内ロックで禁止。既存会計がないため過去推定しない |
| `products` | `id`; tax_category FK | sku nullable UNIQUE、name、price、active、sort | active/sort。永続。現行テーブルなし |
| `payment_methods` | `id`; `code` UNIQUE | name、type、active、sort、requires_external_payment | active/sort。`reservations.payment_method` の既存値とは別責務。Stripeを初期行へ対応付けるが既存値は書換えない |
| `store_calendar_days` | `business_date` PK | status(open/closed/special)、open_at/end_at nullable、reason、updated_by | status/date。永続。`booking.closed_dates` を検証後に移し、読取を新正本へ切替後のみ旧設定を廃止 |
| `monthly_sales_targets` | `target_month` PK（月初日CHECK） | target_amount>=0、updated_by | 月indexはPK。永続。店舗既定は `settings` の新キー、月行が上書き |

### 5.3 来店・会計・配賦候補（Task 11-3 / 11-4）

| 候補 | PK / 関係 | 主要列・制約 | index / 保持 / 既存移行 |
|---|---|---|---|
| `visits` | `id`; customer FK; reservation FK nullable UNIQUE; primary_staff FK nullable | status(open/completed/void)、occurred_at、completed_at、`visit_sequence` nullable、next_reservation_snapshot nullable、snapshot_at、completion_operation_id UNIQUE。completed時はsequence/snapshot必須 | (`customer_id`,`visit_sequence`) UNIQUE、(status,occurred_at)、(primary_staff_id,occurred_at)。永続。既存completed予約は再実行可能なbackfillで1行化し、未知値はNULL |
| `treatment_items` | `id`; visit FK; service FK nullable | service_name/category/priceの実績snapshot、actual_started_at/end_at、actual_minutes、sort。完了時は時刻・分数必須、end>start、minutes>=0 | (visit_id,sort) UNIQUE、(service/category, actual_started_at)。永続。既存は予約serviceを1明細として仮移行できるが実時間は推定せずNULL/要確認 |
| `treatment_staff_assignments` | `id`; treatment FK; staff FK | actual_started_at/end_at、actual_minutes>=0、allocation_weight nullable。時間帯分割可能な区間を保持 | (`treatment_item_id`,`staff_id`) UNIQUE、(staff_id,actual_started_at)。永続。現行予約staffはprimary候補としてのみ移行 |
| `checkouts` | `id`; visit FK UNIQUE; customer FK | status(draft/closed/void)、closed_at、subtotal/tax/total、currency、operation_id UNIQUE、created_by。非負と `subtotal+tax=total` CHECK | (status,closed_at)、customer/closed_at。永続。`final_amount` は照合材料で、会計明細へ推定分解しない |
| `checkout_lines` | `id`; checkout FK; service/product/ticket/membership参照は種類に応じ1つ以下 | description、qty、unit_amount、net、tax、gross、tax_category/rate snapshot、service_date、line_operation_key UNIQUE。`net+tax=gross` | checkout/id、service_date、tax snapshot。永続。既存単発予約は根拠が揃う範囲だけ移行、税は推定しない |
| `payment_tenders` | `id`; checkout FK; payment_method FK; existing `payments.id` nullable UNIQUE | amount>0、refunded_amount>=0かつ<=amount、received_at、reference nullable、status(received/partially_refunded/refunded/void)、operation_key UNIQUE | (checkout,status)、(method,received_at)。永続。Stripeは既存Paymentへ関連、現金等は外部Paymentを要求しない |
| `staff_revenue_allocations` | `id`; checkout_line FK; staff FK; assignment FK nullable | allocated_minutes、allocated_amount>=0、remainder_rank | line/staff UNIQUE、staff/date index。永続。合計一致はtransaction検証 |
| `revenue_contracts` | `id`; customer FK; checkout_lineまたはpayment FK; ticket_wallet / membershipの明示FK | kind(ticket/membership)、contract_amount、units、period、rounding_rule snapshot。起点FKの組合せCHECK、operation_key UNIQUE | wallet UNIQUE、(membership,period) UNIQUE、purchase_date。永続。過去契約額を商品現価から推定しない |
| `revenue_allocations` | `id`; contract FK; visit/treatment/usageの明示FK | allocation_no、recognized_on、amount>=0、is_remainder | (`contract_id`,`allocation_no`) UNIQUE、recognized_on、visit。永続。合計は最終利用または契約終了時に必ず契約額へ収束 |
| `customer_intake_snapshots` | `visit_id` PK/FK | purpose、motivation、referrer_customer_id/text、prefecture、city、manual_age_band、captured_at | 各統計列とvisit。永続。初診時入力がない値はNULLのまま |

`checkout_lines` の複数nullable参照は汎用polymorphicではなく、許可種類を明示したFK + CHECKとする。詳細な分割が複雑になり過ぎる場合はservice/product/contract lineへ物理分割し、Task 11-3で比較して決定する。

### 5.4 勤怠・取込・照合候補（Task 11-8 / 11-12 / 11-13）

| 候補 | PK / 関係 | 主要列・制約 | index / 保持 / 既存移行 |
|---|---|---|---|
| `staff_attendances` | `id`; staff FK; (`staff_id`,`work_date`) UNIQUE | clock_in/out、approved_at/by、status、note。out>in | work_date/status。永続。`staff_shifts` は予定として残す |
| `staff_attendance_breaks` | `id`; attendance FK | start/end、kind、note。end>start | attendance/start。永続。schedule blockから自動実績化しない |
| `staff_employment_periods` | `id`; staff FK | employment_type(employee/part_time等)、effective_from/to nullable、from<to | staff/from UNIQUE/index。期間重複をServiceで禁止し永続保持。現在のstaffには区分がなく、過去を現行区分で推定しない |
| `import_batches` | `id`; source sha256 UNIQUE; actor FK | source_file_name、source_storage_key、kind、status、started/completed/imported_at、row counts | status/created_at。原本hashと監査を永続保持。ファイルコピーの保持期間は別途決定 |
| `import_staging_rows` | `id`; batch FK | sheet_name、row_no、source_identifier、raw_values、normalized status/error、matched_customer nullable | (`batch_id`,`sheet_name`,`row_no`) UNIQUE、status。検証完了後もprovenance保持。raw値のPII暗号化/削除期限はR-10 |
| `import_staging_cells` | `id`; row FK | cell_ref、header、raw_value、normalized_value | (`row_id`,`cell_ref`) UNIQUE。セル出典追跡。式は実行せず文字列として扱う |
| `historical_metric_values` | `id`; batch/row/cell FK | period、metric_code、dimension_code/value、numeric_value、status | metric/period/dimension UNIQUE候補。永続。架空のvisit/checkoutへ変換しない |
| `report_reconciliations` | `id`; period、metric、dimension | legacy_value、ark_value、difference、reason/status、reviewed_by/at | period/metric/status。照合履歴を永続保持 |

## 6. 既存テーブル拡張候補

既存表は破壊的変更を避け、最初はnullable追加、backfill検証、read切替、必要ならNOT NULL化の順に進める。既存PK/FK/delete方針は維持し、税・価格・分類等の過去値は各事実snapshotへ保存する。索引は新FKと実クエリに必要な複合indexだけを追加する。

| 既存テーブル | 追加候補 | 制約・索引・移行方針 |
|---|---|---|
| `services` | `menu_category_id` FK、`tax_category_id` FK | nullable追加→既存値を検証付きmapping→管理画面で未分類解消→将来NOT NULL。現行 `category` は直ちに削除/意味変更しない |
| `ticket_products` | `tax_category_id` FK | nullable段階追加。購入時税はcheckout line snapshotが正本 |
| `membership_plans` | `tax_category_id` FK | 同上。表示priceを過去invoice税へ遡及適用しない |
| `customers` | `prefecture_code`、`city`（現プロフィールとして必要な場合） | nullable、未入力を維持。統計上の初診時値は `customer_intake_snapshots` を優先。暗号化・検索要件はTask 11-7前に決定 |
| `payments` | `membership_id` nullable FK、invoice period / external paid timestampの不足情報 | 既存invoiceからStripe再取得せず、ローカルに根拠がある行だけbackfill。`payment_operation_id` の冪等性維持 |
| `ticket_wallets` | `revenue_contract_id`または購入checkout lineへの関連 | 管理GRANTはNULL許容。商品現価から購入額を推定しない |
| `memberships` | 直接金額を持たせず、期ごとの `revenue_contracts` へ関連 | current plan priceを過去期へ適用しない |

`reservations.service_id` / `staff_id` は予約時の主メニュー・予定担当として保持する。複数実績を載せるために意味を変更したり、先に削除したりしない。`reservations.final_amount` も互換表示・移行照合用に保持し、Phase 11会計の正本はclosed checkoutへ切り替える。

## 7. 予約 → 来店 → 施術 → 会計 → 決済 → 集計のデータフロー

### 7.1 現行

1. 予約: `ReservationService::create()` が1 service / 0..1 staff / 0..1 boothの予約とresource slotsを作る（`app/Domain/Reservation/ReservationService.php:57-124`）。
2. 決済: cardは `ReservationCheckoutSaga` がservice現価で1 Stripe Paymentを作り、capture成功で予約をconfirmedへ進める（`app/Domain/Payment/ReservationCheckoutSaga.php:46-105,150-185`）。onsite / unpaidは会計内訳を持たない。
3. 来店・施術: 専用事実はなく、`markCompleted()` がreservationをcompletedにし `attended_at=now()`、回数券/月額を消化するだけである。
4. 集計: 予約台帳の日次summaryは予約開始日、completed件数、`final_amount ?? services.price` をアプリ側loopで集計する（`app/Queries/ScheduleQuery.php:226-303`）。pending/no-showを初回来店候補に含めるため、Phase 11の「新規」定義とは一致しない（同:114-130）。
5. Excel / Reporting / 過去取込は未実装。

### 7.2 目標

1. 予約は予定として既存処理を維持する。
2. 来店開始時にreservationからvisitを冪等作成し、実施した各メニュー・実時間・各担当時間をtreatmentへ記録する。予約なし来店はreservation NULLを許す。
3. checkout draftへ商品/施術/契約購入のlineを積み、適用日から税率を解決してlineへ税率・税抜・税額・税込を保存する。
4. payment tenderへ現金、PayPay、Stripe等を複数登録する。Stripe tenderのみ既存Paymentと関連する。
5. 「来店・会計完了」は、`SUM(line.gross)=checkout.total=SUM(received tender)`、税、スタッフ配分を検証し、visit完了、sequence付与、次回予約snapshot、回数券/月額消化を **短い同一DB transaction** で確定する。外部HTTPはtransaction外とする。
6. 完了後は事実を上書きせず、修正は取消・返金・訂正行で追跡する。
7. 共通Reporting Query / Serviceが事実から日次を計算し、月次は日次、年間は月次と同じ定義を合成する。画面・Excel・将来の外部アダプタは同じread modelを利用する。

### 7.3 完了境界と次回予約スナップショット

- 完了可能条件: visitがopen、checkoutがclosed可能、会計と支払合計一致、施術明細と主担当が確定、必要な権利消化が可能であること。単に予約をcompletedへ変えることは完了ではない。
- snapshot時刻は上記完了transaction内で採った `completion_at` 1値とし、`visits.completed_at` と `next_reservation_snapshot_at` に同じ値を保存する。
- 「未来」は `reservations.starts_at > completion_at`。同日でも時刻が後なら含め、同時刻以前は含めない。現在のvisitに対応するreservation自身は除外する。
- 「有効予約」は snapshot時点で `status=confirmed` の予約とする。pending payment / pending external sync / canceled / expired / completed / no-showは除外する。外部正本モードではconfirmedになるまで予約完了ではないという現行規則（`docs/PLAN.md:75-86`）と一致する。
- 顧客行を `FOR UPDATE` し、予約create/cancelと完了snapshotも同じ顧客ロック順に統一して並行実行を直列化する。`visit_sequence = max+1` を同transactionで採番し、(`customer_id`,`visit_sequence`) UNIQUEで初診重複を防ぐ。
- snapshotは0件=false、1件以上=true。後日の予約作成・キャンセルでは更新しない。訂正再完了時の扱いはR-5として承認が必要。

## 8. 全集計指標の定義

### 8.1 共通集計規則

- 期間は特記なければAsia/Tokyoの半開区間 `[period_start, period_end)`。日=0:00から翌日0:00、月=月初から翌月初、年=1月1日から翌年1月1日。
- 件数はvisitを正本とし、`status=completed` のみ含む。cancel/no-show/void/openと未完了予約は除外する。
- 金額は整数円。率の表示丸め桁は既存原本未提供のため未確定。内部は分子・分母を保持し、0除算はNULL（未算出）とする。
- 金額按分は `floor(total * weight / total_weight)` を基礎額とし、余り1円ずつを `remainder_rank`（実施日時、明細ID、スタッフIDの昇順）へ配る最大剰余法を候補とする。税端数規則は原本・会計方針確認前には確定しない。
- 現行コードで確認できた `floor` はキャンセル返金率計算（`app/Domain/Reservation/CancellationPolicy.php:42`）であり、税計算規則ではない。税の丸め実装は存在しないため未確定とする。
- 集計結果はNULLを暗黙に0へ変換しない。「該当0件」と「値未入力」を分離する。

### 8.2 来店・予約・メニュー

| 指標 | 正本・対象期間 | 分子 / 分母 | 除外・snapshot・NULL / 丸め |
|---|---|---|---|
| 来店数 | completed visitの`completed_at` | 分子=distinct visit.id、分母なし | 複数施術/スタッフでも1。取消等除外。整数 |
| 過去「施術数」 | `historical_metric_values`のみ | 原本値 | 新主要指標へ変換せず残す。現行visitと合算しない |
| ロング | 対象completed visit | 分子=`SUM(non-null actual_minutes)>60` のvisit数 | 60=false、61=true、30+30=false、30+45=true、75=true。時間NULLを0扱いせず判定不能として別件数。1 visit最大1 |
| メニュー分類件数 | treatment category snapshot | 分子=来店内category集合を正規化したvisit数 | M/T/A/M&T/A&T。その他組合せ/NULLは「未分類」で追跡し勝手に既定5区分へ入れない。複数同分類もvisit内1 |
| 次回予約人数 | visitの完了snapshot | 分子=snapshot=trueのvisit数 | 0/1固定。後日変更なし。NULLは未backfill/未完了として別件数 |
| 次回予約率 | 同上 | 次回予約人数 / completed visit数 | 分母0=NULL。表示丸め未確定 |
| 初診 | `visit_sequence=1` | 分子=初診visit数 | 顧客ごと1。キャンセル等除外 |
| 初診予約 | 初診visit snapshot | 分子=sequence=1かつsnapshot=true | 1 visit 0/1 |
| 初診予約率 | 同上 | 初診予約数 / 初診数 | 分母0=NULL |

### 8.3 顧客推移・継続

| 指標 | 正本・対象期間 | 分子 / 分母 | 除外・snapshot・NULL / 丸め |
|---|---|---|---|
| 新規 | 対象月に`visit_sequence=1`のcustomer | 分子=distinct customer | 完了来店のみ。対象月前に完了visitがあれば除外 |
| 離反 | 判定月Mに対しM-2のcompleted visits | 分子=M-2来店あり、M-1来店なしのdistinct customer | M月来店有無は条件にしない。年跨ぎも同じ月境界 |
| 再診 | 対象月Mと全過去completed visits | 分子=M来店あり、M-1なし、M-2以前ありのdistinct customer | sequence=1の新規は除外 |
| N回目到達フラグ | cutoff時点の`visit_sequence` | 分子候補=sequence>=Nに到達した初診customer | N=2/6/10、完了visitのみ。個人フラグは決定可能 |
| N回目到達率 | 初診cohort | 分子=cohort内N到達、分母=同cohort初診customer | **Task 11-7で解決**: 対象月初診cohortを指定`as_of_date`まで追跡。分母0=NULL。詳細は`docs/CUSTOMER_ANALYTICS.md` |

新規統計の内訳は初診visitに紐づくsnapshotを正本にする。コース=初診treatment分類、目的/動機/紹介者/都道府県/市区町村=`customer_intake_snapshots`、性別/生年月日由来年代=初診時customer snapshot、初回担当=visit.primary_staff、次回予約=visit snapshot。生年月日があれば初診日時点の満年齢から年代を算出し、手入力年代は生年月日NULL時だけ候補とする。ただし手入力年代を採用するかはR-3。全dimensionでNULLを「未入力」として母数に含め、「その他」と分ける。

現行顧客モデルからの取得可否は次のとおり。コースは顧客属性ではなく初診の予約serviceから暫定参照できるが、実施コースではない。性別・暗号化生年月日は存在する。年代は保存されておらず生年月日から算出可能。来店目的、来店動機、紹介者、都道府県、市区町村は未実装。初回担当はcompleted reservationのstaffから暫定参照できるが、実主担当の正本ではない。よってTask 11-3/7では初診visit snapshotを新設し、現行値から根拠のないbackfillを行わない。

### 8.4 売上・税・決済

| 指標 | 正本・対象期間 | 分子 / 分母 | 除外・snapshot・NULL / 丸め |
|---|---|---|---|
| 決済日基準売上 | received `payment_tenders.received_at` と成功`payments.paid_at` | 期間内受領額 - 期間内成功返金額 | 回数券/月額は全額。pending/failed/void除外。返金を元決済日へ遡及するか当日控除かはR-6 |
| 施術日基準売上 | checkout service lines + `revenue_allocations.recognized_on` | 当日施術売上 + 契約利用配賦額 | 契約購入時は二重計上しない。配賦合計=契約額。不使用残の認識時点はR-7 |
| 税抜/税/税込 | checkout line snapshot | 各lineのnet/tax/gross合計 | `net+tax=gross`。税率NULLのclosed line禁止。税計算単位と端数はR-2 |
| 支払方法別売上 | received tender + method | method別受領 - refund/void | 1会計複数method可。method合計=checkout total |
| 会計客単価 | closed checkout | checkout売上 / completed visit数 | 0除算=NULL。未会計visitは完了不可 |
| 目標進捗 | monthly targetと決済日基準月売上（既存帳票既定） | 実績/目標、差=実績-目標 | 目標0は率NULL。施術日基準表示は明示切替 |

### 8.5 スタッフ・時間帯・曜日

| 指標 | 正本・対象期間 | 分子 / 分母 | 除外・snapshot・NULL / 丸め |
|---|---|---|---|
| スタッフ稼働分数 | treatment assignment | completed treatmentとの区間重複分の合計 | 同時施術の重複加算方針はR-8。duration NULLは未入力 |
| 既存互換稼働率 | assignment / `staff_attendances`実績 | 稼働分 / （実出退勤区間−実休憩） | 実勤怠未入力ならNULL。予定shiftを暗黙代用せず、必要なら「予定勤務基準」を別表示。分母0=NULL |
| 予約可能時間稼働率 | assignment / 実勤務区間−休業−schedule blocks−予約不可 | 稼働分 / 実予約可能分 | block重複は区間union後1回だけ控除。実勤怠未入力/分母0はNULL。将来予定の見込率とは分離 |
| スタッフ売上 | `staff_revenue_allocations` | allocated_amount合計 | 時間按分、時間合計0時の規則はR-9。全staff合計=元line |
| スタッフ帰属の来店/ロング/次回予約 | visit.primary_staff | distinct visit / snapshot | 複数担当へ重複させない。主担当NULLは未入力 |
| 時間帯別稼働率 | treatment / bookable intervals | 各帯との区間intersection分 / 各帯の予約可能分 | `[10,12)`,`[12,15)`,`[15,18)`,`[18,21)`。11:30-12:30は30/30。範囲外は別区分、分母0=NULL |
| 平日/土日平均 | 日次共通集計 | 該当営業日の指標合計 / 該当営業日数 | 月-金=平日、土日=土日、祝日は曜日どおり。closed日除外。営業日0=NULL |
| 月計 | 日次共通集計 | 月内日次の合計/営業日平均 | 28/29/30/31日対応。独自再計算式を持たない |
| 年間 | 月次共通集計 | 年内月次の合計/比較 | 年度か暦年かはR-11。月別との一致を検証 |

## 9. NULL / 未入力方針

1. 業務上の0、false、該当なし、未入力、算出不能を別状態として扱う。DB NULLを画面都合で0や「その他」へ変換しない。
2. 完了時必須の値（visit sequence、会計合計、税snapshot、支払合計、snapshot時刻）はclosed/completed状態ではNULL禁止とし、draft中のみ許す。
3. 過去backfillで根拠がない実施時間、税、支払方法、プロフィールはNULLにする。現価・予約時間・名前から推測しない。
4. 統計dimensionのNULLは「未入力」bucketとして分母に含める。「その他」は入力済みの値である。
5. ロング等、必須元データ欠損で真偽を決められない指標はfalseにせず `unknown_count` を併記し、最終照合まで追跡する。
6. 0除算はNULL（未算出）。Excelでは空欄または承認済み表示文字に変換し、0%を出さない。
7. 初診時年代は生年月日snapshotを優先する。生年月日がNULLなら手入力年代採用可否の承認までは「未入力」とする。

## 10. 二重計上防止方針

- reservation→visitをUNIQUE、visit→checkoutをUNIQUE、reservation→ticket/membership usageを既存どおりUNIQUEにする。
- 完了操作は `completion_operation_id` UNIQUEと状態遷移で冪等化し、顧客→visit→checkout→契約台帳の固定順で `FOR UPDATE` する。
- 顧客ロックと (`customer_id`,`visit_sequence`) UNIQUEにより初診・来店順の競合を防ぐ。
- checkout line / tender / contract / allocationは外部または業務operation keyをUNIQUEにし、リトライで同じ行へ収束させる。
- `SUM(checkout_lines.gross)=checkouts.total=SUM(received tenders)`、`SUM(staff allocation)=line amount`、`SUM(revenue allocation)=contract amount` はclose/完了transactionで検証する。複数行SUMはDB CHECKだけに依存しない。
- 店舗来店指標はvisit IDでdistinct、患者指標はprimary staffだけへ帰属、スタッフ金額だけallocation行を合算する。
- 時間帯跨ぎは半開区間のintersectionで分割し、境界分を両帯へ重複させない。block控除は区間union後に行う。
- 集計cacheを後で導入しても事実を正本とし、期間再計算とreconcileを提供する。

### 10.1 不変条件と保証レイヤ

| 不変条件 | DBで保証 | Service / reconcileで保証 |
|---|---|---|
| 1予約につき来店0..1、1来店につき会計0..1 | reservation_id / visit_id UNIQUE、FK | 冪等operation、状態遷移、rollback |
| 会計合計 = 会計明細合計 = 受領決済明細合計 | 行内金額CHECK、FK、operation UNIQUE | close時に対象行をlockしてSUM検証 |
| 税込 = 税抜 + 税額 | checkout line CHECK | 税率解決・整数丸め・全line再検算 |
| スタッフ売上配分合計 = 元売上 | line/staff UNIQUE、非負CHECK | allocation行lock + SUM、決定的余り配分 |
| スタッフ時間配分合計 = 元施術時間 | treatment/staff UNIQUE、非負CHECK | 完了時SUM。担当重複区間規則も検証 |
| 配賦売上合計 = 契約額 | contract/allocation FK、allocation_no UNIQUE | 利用/期限/解約処理で累計と残額をlock検証 |
| 日次売上合計 = 月計合計 | 単独のDB制約では不可 | 同じ共通集計を合成しreconcile test |
| 月次来店数 = 月内completed visit件数 | visit status/required completed_at CHECK候補 | distinct visit集計とreconcile test |
| 初診予約人数 <= 初診人数 | sequence/snapshot列の行内条件候補 | 同じ初診集合から分子をfilter |
| 次回予約人数 <= 来店数 | completed時snapshot必須CHECK候補 | 同じcompleted visit集合から分子をfilter |
| 顧客の初診は最大1 | (`customer_id`,`visit_sequence`) UNIQUE | 顧客lock下でsequence採番 |

複数行合計、期間合計、外部決済状態はCHECK制約だけでは保証できないため、短いtransactionと定期reconcileの両方を必須とする。

## 11. 過去データ互換方針

1. 原本は読取専用とし、コピーのhashをbatchへ記録する。原本→staging→validation→本登録の状態遷移とdry-runを必須にする。
2. ファイル、sheet、row、cell、取込日時、batch IDを追跡する。式は実行せず、CSV formula injectionも無害化する。
3. 同一hash / row keyの再取込は冪等。部分失敗はbatch状態と行エラーで見え、承認済み行だけをtransaction単位で登録する。
4. 顧客照合はARK customer ID/member_no、正規化電話HMAC等の強い識別子、管理者確認の順。名前だけの自動統合は禁止する。
5. 明細がある行だけvisit/checkoutへ変換する。集計値だけなら `historical_metric_values` へ格納し、架空明細を作らない。
6. 現行completed reservationのbackfillも同じくoperation key付きで再実行可能にする。予約時間を実施時間、service現価を過去売上、現行categoryを分類と推測しない。
7. 過去の「施術数」は保持するが新しい来店数へ変換しない。比較時は旧定義/新定義をラベル表示する。

## 12. Excelとの対応方針

| シート | ARK側で必要なread model | 現時点のブロッカー |
|---|---|---|
| 数値 | 日次の決済日売上、支払/税、来店、ロング、次回予約、初診、分類 | 原本、項目名、セル、数式、書式未提供 |
| 日報 | 日付別明細/集計、担当、施術、会計、備考 | 行粒度、印刷範囲、備考の正本未確定 |
| 月計表 | 日次共通結果、合計、平均、目標、残営業日 | 目標規則、返金帰属、丸め、セル未確定 |
| 稼働率（社員） | staff属性、勤務/予約可能/稼働分、2種稼働率 | 社員/アルバイト区分がstaffに存在しない、原本未提供 |
| 稼働率（アルバイト） | 同上 | 同上 |
| 年間計画書（実数） | 月次共通結果、年間目標・実績 | 暦年/年度、計画入力単位、原本未提供 |

- Task 11-11では原本コピーに計算済み値だけを書き、原本を変更しない。テンプレートは版、hash、対応ARK仕様版を管理する。
- まず原本の保護コピーでsheet名、named range、結合セル、式、入力セル、印刷設定、非表示行列をinventoryし、`template_version + sheet + cell/range + metric_code + dimension + value_type + format` のmapping仕様をレビューする。
- レイアウトアダプタとReporting read modelを分離し、Excel内数式を集計正本にしない。未提供のセル位置は推測しない。
- 原本が提供され、利用権限・期待サンプル出力・既存値の基準月が揃うまでTask 11-11は開始不可とする。
- Google Sheetsは将来 `ReportDataset` を受け取る外部adapterにできる境界だけを維持する。今回は読取・書込・認証・ボタン・空実装を一切行わない。

## 13. Task 11-2以降へ引き渡すDB変更案

| Task | 引き渡し内容 | 開始前ゲート |
|---|---|---|
| 11-2 | category/tax/product/payment method/calendar/targetマスタ、services等の段階拡張 | 税端数、timezone、staff雇用区分、calendar移行承認 |
| 11-3 | visit/treatment/assignment/checkout/line/tender/contract/allocation | テーブル分割、会計訂正、過去backfill可能範囲承認 |
| 11-4 | 完了Action、顧客ロック、sequence、next reservation snapshot | 完了/再完了/取消条件と有効予約定義承認 |
| 11-5 | 共通日次Query / Serviceと必要index | metric matrix、返金帰属、`EXPLAIN`基準承認 |
| 11-6 | 月次合成、営業日、目標 | store calendarと目標既定/上書き確定 |
| 11-7 | 顧客cohort、intake snapshot、2/6/10率 | cohort観察期間、年代fallback、紹介者表現確定 |
| 11-8 | attendance / breaks、2種稼働率 | 予定対実績、予定外勤務、同時施術規則確定 |
| 11-9 | 時間帯intersection集計 | 営業時間外bucket、同時施術規則確定 |
| 11-10 | 月→年合成 | 暦年/年度、年間目標粒度確定 |
| 11-11 | template registry / cell mapping / Excel writer | 原本6シートと期待出力・利用権限提供 |
| 11-12 | batch/staging/cell provenance/historical metrics | 原本サンプル、PII保持期限、顧客確認UI確定 |
| 11-13 | reconciliationと全不変条件検証 | 比較基準月、差異承認フロー確定 |

索引は各Taskで実データ想定のSQLを作って `EXPLAIN` し、重複indexを避ける。単一店舗前提なので `stores` / `store_id` は追加しない（`docs/PLAN.md:101-112`）。

### 13.1 Performance / index引き渡し

- 現行はreservationの starts_at、staff+starts_at、customer+starts_at、status/payment status、shiftのstaff+date、schedule blockのstaff/booth+date、payment status等を持つ（§1）。これらは予定検索向けで、visit/accounting集計を代替しない。
- 主クエリごとの候補は、来店日=`visits(status,completed_at)`、顧客履歴=`visits(customer_id,completed_at/sequence)`、会計日=`checkouts(status,closed_at)`、担当期間=`assignments(staff_id,treatment_id)` + treatment日時、menu期間=`treatment_items(service/category,started_at)`、支払方法期間=`payment_tenders(payment_method_id,received_at,status)` とする。
- 単一店舗なので店舗indexは作らない。将来の複数店舗要件だけを理由に `store_id` を全表へ追加しない。
- 低選択性status単独indexや似た複合indexを無条件追加しない。Taskごとに代表SQL、想定件数、`EXPLAIN`、書込コストを確認して最小構成を確定する。

## 14. リスク・未確定事項

| ID | リスク / 未確定事項 | 後続Taskの開始条件 |
|---|---|---|
| R-1 | app timezoneがUTC固定、env例はAsia/Tokyoで不一致 | 既存日時の保存/表示実態を検証し、業務日zoneと移行影響を承認 |
| R-2 | 税の内税/外税、line/会計単位、端数処理、税率変更日の境界が未提供 | 会計方針と期待例を承認。商品種別で税率をハードコードしない |
| R-3 | 生年月日欠損時に手入力年代を採用するか未確定 | 入力主体、snapshot時点、優先順位を承認 |
| R-4 | **Task 11-7で解決**: 対象月の初診cohortを分母とし、指定`as_of_date`までの到達を観察。最近cohortも除外しない | `docs/CUSTOMER_ANALYTICS.md`を正本として参照 |
| R-5 | 完了取消・再完了時にnext reservation snapshotを再取得するか未確定 | 訂正履歴と原snapshot保持規則を承認 |
| R-6 | 返金を返金日に控除するか元決済日に遡及するか未確定 | 既存帳票互換と経営分析の表示方針を承認 |
| R-7 | 回数券/月額の未使用・期限切れ・返金時の残額認識規則が未確定 | 契約種別ごとの配賦・収益認識表を承認 |
| R-8 | 同一スタッフの重複施術時間を稼働分へ足すか区間unionにするか未確定 | 実運用可否を確認し、原則unionか加算か承認 |
| R-9 | 担当時間合計0/NULL時の売上配分規則、主担当選択規則が未確定 | 完了入力必須条件またはfallbackを承認 |
| R-10 | 会計/顧客/取込原文の法定・業務保持年数、PII削除要件が未確定 | 法務・運用方針を承認。業務事実を先にpruneしない |
| R-11 | 年間が暦年か事業年度か、年度開始月が未確定 | Excel原本と運用者確認で確定 |
| R-12 | Excel原本、セル仕様、期待値、社員/アルバイト区分が未提供 | Task 11-11前に保護コピーと仕様を提供 |
| R-13 | 現行 `ScheduleQuery::dailySummary()` の売上/新規定義がPhase 11と不一致 | Task 11-5で共通集計へ切替。ただし既存台帳表示regressionを用意 |
| R-14 | 現行completed予約に実施時間・税・実支払内訳がない | 不明値をNULLのまま扱う移行・比較方針を承認 |
| R-15 | 月額invoice Paymentにmembership FKがなく、回数券購入Paymentの実装も確認できない | Task 11-3でローカル根拠だけを関連付け、外部再取得や推定をしない |
| R-16 | 会計締め後の訂正、取消、日跨ぎ施術、予約なし来店が詳細未確定 | 状態遷移・監査・期間帰属を承認 |

## 15. route・権限・テストとregression基準

### 15.1 現状

- 管理routeはauth / verified / admin access / active / idle timeout / MFAで保護される（`routes/web.php:188-196`）。予約閲覧/更新、勤務、マスタ、payment、設定は個別permissionを使う（同:209-279,322-418,436-460）。report/export/import routeはない。
- permissionは `reservations.*`, `customers.*`, `staff/services/shifts/settings.manage`, refund, ticket, membership, integration等で、report/sales/export/attendance/target/importはない（`database/seeders/RolePermissionSeeder.php:15-37`）。
- `phpunit.xml:7-16` にUnit / Feature / Contract suiteがあり、`package.json:6-11` にtypecheckを含むbuildとVitestがある。
- 完了、回数券/月額の冪等性、決済、予約競合、勤務、role permission、schedule daily summaryの既存テストがある。例: `tests/Feature/Reservation/ReservationServiceTest.php:451-489`, `tests/Feature/Ticket/ReservationTicketIntegrationTest.php:166-180`, `tests/Feature/Membership/MembershipIdempotencyConsolidatedTest.php:137-149`, `tests/Feature/Admin/Reservations/ScheduleDailySummaryAndBothAxisTest.php:48-51`。
- fixtureは `database/factories/` のUser/Customer/Reservation/Service/Staff/Payment/Ticket/Membership等のFactoryとSeederを使い、多くのDBテストが `RefreshDatabase` を採用する。時刻境界は既存どおり `Carbon::setTestNow` / `travelTo` で固定する。

後続権限は既存web guardへ、少なくとも `sales.view`（売上閲覧）、`reports.view`（非金額を含むレポート閲覧）、`reports.export`（Excel出力）、`attendance.manage`、`sales_targets.manage`、`store_calendar.manage` を追加候補とする。importは `historical_data.import`、照合は `reports.reconcile` と分離する。adminは全権、managerへの初期付与は業務承認、staff/customerは明示承認なしに付与しない。売上閲覧を既存 `reservations.view` へ便乗させない。

### 15.2 後続Taskの最低regression

- 既存の予約作成/変更/取消/完了/no-show、resource slot競合、カード決済/返金、回数券/月額HOLD・消化・冪等性、顧客/スタッフ/勤務/設定の全Featureテストを維持する。
- 新permissionは閲覧、会計更新、マスタ更新、export、import、照合を最小権限で分離し、staff/manager/admin/customerの403/成功をテストする。export/import/会計確定は監査対象とする。
- 金額合計、税、配賦、並行完了、operation key再送、rollback、60/61分、月年境界、NULL、0除算、時間帯跨ぎ、営業時間block unionを自動テストする。
- 日次=月次日別合計、月次=年間月別合計、会計=明細=決済、配分/配賦=元額をreconcileテストする。
- DB集計は期間indexを使うSQLと `EXPLAIN` を確認し、全履歴のPHP loopを新規採用しない。
- 各実装Taskで `./vendor/bin/sail artisan test`、`./vendor/bin/sail npm run test`、`./vendor/bin/sail npm run build`、必要なformat/static checkを実行する。

新規テストfixtureには、通常単発売上、回数券、月額、複数税率、11,000円の複数決済、全額/一部返金、60/61/30+30/30+45/75分、複数メニュー、初診と同時完了、次回予約0/1/2件と後日変更、新規/離反/再診、2/6/10回、45:15の複数スタッフ、2種稼働率、11:30-12:30の時間帯跨ぎ、月/年跨ぎ、NULL、0除算、28/29/30/31日を最低限用意する。各fixtureは期待する分子・分母・金額・日付を明示し、画面snapshotだけに依存しない。

## 16. Task 11-5 実装確定事項

- 日次read modelは`DailyReportService` / `DailyReportQuery` / `DailyBusinessSummary`へ集約し、管理画面、月計、年間、Excelで再利用する。
- 来店系の日付は保存済み`visits.business_date`、決済日基準はfinalized checkoutに属するreceived tenderの`received_at`、税内訳はcheckout `finalized_at`、配賦売上は`recognized_on`を正本とする。
- 施術日基準の通常施術売上は、同一完了Visitのcompleted treatmentに直接結び付くfinalized checkout lineのgrossを用いる。回数券／月額は保存済みallocationだけを加え、価格から配賦を推測しない。
- 返金帰属R-6は未確定のまま維持し、日次売上から自動控除しない。API利用者は返金未反映であることを仕様として扱う。
- 率は分子・分母とnullable値を返し、分母0を0%へ変換しない。施術時間、分類、次回予約、税snapshotのNULLは専用unknown件数またはnullable bucketで保持する。
- `reports.view`と`sales.view`を分離し、売上を含む日次APIは両権限を要求する。admin以外への初期付与は行わない。

### 16.1 Task 11-6 実装確定事項

- 月計は`DailyReportQuery::fetchRange()` / `DailyReportService::forRange()`を再利用し、8本の月範囲集約SQLで全日次行を作る。日次APIを日数分呼ばない。
- 月予約率・初診予約率は月の分子合計÷分母合計であり、日別率の単純平均は禁止する。
- `as_of_date`までを実績期間、翌日以降を残営業日とする。既定は現在月=JST今日、過去月=月末、未来月=月初前日。
- 平日=月〜金、土日=土・日で、祝日は曜日どおり。臨時休業は営業日・平均分母・残営業日から除外するが、保存済み事実は月合計と曜日別分子へ残す。
- 日平均の分母（既存帳票の「入力日数」に相当）は`as_of_date`までの非休業日数とした。未来日や臨時休業日は入れない。
- 目標進捗は選択中の売上基準を使い、差額と残必要売上を分離する。残営業日0、目標なし、率の分母0はNULLとし、0%／0円へ暗黙変換しない。
- 1〜15日と16日〜月末を別集計として保持する。画面/APIは`reports.view`と`sales.view`の両方を要求する。

## 16. 対象外と責務分離

- Google Sheets実書込・読取、既存スプレッドシート変更、SALON BOARD連携、Peak Manager実API、外部予約サイト同期、帳票メール自動送信はPhase 11の対象外。
- Reporting coreは外部サービスを知らない `ReportDataset` を返し、Excel writerと将来のGoogle Sheets adapterは出力境界の別責務とする。ただしadapterや空interfaceを今回は実装しない。
- 本番WordPress、本番DB、Stripe Live、外部API、既存Excel/Google Sheets原本には接続しない。

## 17. Task 11-1 Acceptance Criteria自己確認

- [x] 必須14項目を既存コード・migration・設計文書の参照付きで記録した。
- [x] 指定された調査領域を確認し、appointment/accounting/product/tax/reporting/Excel/CSV/Google Sheets/import等の未実装も明記した。
- [x] 予約、来店、施術、会計、外部決済、権利台帳、集計の責務を分離した。
- [x] 確定済み全指標の正本、期間、分子/分母、除外、snapshot、丸め、NULL、0除算を定義または未確定としてゲート化した。
- [x] 2売上基準、税snapshot、複数決済、複数スタッフの合計不変条件を定義した。
- [x] 新規候補と拡張候補にキー、関係、制約、索引、保持、移行方針を記載した。
- [x] 完了境界、snapshot時点、未来、有効予約、同日、並行実行を一意に定義した。
- [x] Excel 6シートの必要データ、template/mapping方法、未提供blockerを整理した。
- [x] route、permission、testsとregression基準を記録した。
- [x] 対象外の外部連携・実書込・メール送信を含めていない。
- [x] migration、アプリケーションコード、route、依存関係を変更していない。
- [x] `git status` / `git diff` と、新規未追跡成果物に対する `git diff --no-index --check` を確認し、今回追加が本成果物だけであることを確認した。
- [x] 未確定事項をR-1〜R-16として推測せず、後続Taskの開始条件にした。
