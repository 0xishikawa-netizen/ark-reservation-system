# DB_SCHEMA

MySQL（実バージョンは Phase 0 実測 → PHASE0_REPORT.md）。InnoDB / `utf8mb4`。
金額は整数（最小通貨単位、JPY は円）。日時は UTC 保存・表示は `Asia/Tokyo`。

## 0. 容量方針（ハード制約）

- バイナリ（画像 / 動画 / PDF）を DB に入れない。ファイルはストレージ、DB は**パス / URL / 外部 ID のみ**。
- Stripe / 外部 API のレスポンス全体を永続保存しない。**webhook payload は DB に保存しない**。
- `JSON` カラムを多用しない（例外は `settings.value` の短い文字列のみ）。
- `TEXT` / `LONGTEXT` を安易に使わない。備考は `VARCHAR(1000)` 上限。
- Index はクエリに必要なものだけ。
- 同一情報の重複保存をしない。**キャッシュカラムは 1 集計につき 1 個**（`ticket_wallets.balance` / `memberships.period_available`）。
  台帳追記と同一 transaction で更新し、`*:reconcile` コマンドで突合。

## 1. 業務データ（永続保持・prune しない）

`customers` `staff` `services` `membership_plans` `reservations`
`ticket_products` `ticket_wallets` `ticket_transactions`
`memberships` `membership_usage_transactions` `payments` `payment_refunds`

→ 会計・契約に関わるため長期保持。法定保存年数は OPEN_QUESTIONS #3。

## 2. 技術データ（保持期間つき・`model:prune` 対象）

| テーブル | 主な列 | 既定保持 |
|---|---|---|
| `webhook_events` | `stripe_event_id` UNIQUE, type, status, related_type/id, error VARCHAR(500), received_at, processed_at（**payload なし**） | 成功/無視 90 日 / 失敗 解決まで |
| `sync_logs` | provider, operation, reservation_id, status, error VARCHAR(500), attempts, created_at | 成功 30 日 / 失敗 180 日 |
| `audit_logs` | actor_user_id, action, entity_type, entity_id, summary VARCHAR(500), ip VARCHAR(45), created_at（**スナップショットなし**） | 金銭・PII 長期 / その他 365 日 |
| `failed_jobs` | Laravel 標準 | 解決まで |
| `reservation_resource_slots` | 下記 | 過去・無効分のみ prune |
| `db_size_snapshots` | captured_on DATE, total_mb INT, note VARCHAR(255) | 日次 1 行（軽量・保持） |
| `reservation_sync_events` (Phase 9) | 追記専用。provider, direction, operation, reservation_id, `external_reservation_id_masked`(32), correlation_id, idempotency_key(120), status, attempt, error_category(20), safe_error_code(80), started_at, completed_at, created_at（**raw payload / PII なし**） | succeeded/no_op/skipped 60 日 / failed 解決まで（`reservations:prune-sync-logs`） |
| `reservation_sync_outbox` (Phase 9) | provider, reservation_id, operation, `idempotency_key`(120) UNIQUE, payload_json（starts/ends/status/service_id/staff_id のみ・**自由記述なし**）, status, attempts, available_at, locked_at, locked_by(64), last_error_category(20), last_error_code(80), correlation_id, completed_at | succeeded/skipped 完了 30 日 / needs_attention は保持 |
| `reservation_sync_conflicts` (Phase 9) | provider, reservation_id, `external_reservation_id_masked`(32), `external_ref_hash`(64), conflict_type(32), ark_fingerprint(64), external_fingerprint(64), detected_at, status(open/resolved/ignored), resolution(32), resolved_at, resolved_by → users | resolved/ignored 180 日 / open は保持 |

## 3. テーブル定義

### 認証・人

**users**
| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| name | varchar(100) | |
| email | varchar(190) UNIQUE | |
| email_verified_at | timestamp null | |
| password | varchar(255) | argon2id |
| two_factor_secret | text null | スタッフのみ（Fortify） |
| two_factor_recovery_codes | text null | |
| two_factor_confirmed_at | timestamp null | |
| remember_token, timestamps | | |

- `type` カラムは持たない。顧客/スタッフ判別は spatie role（`customer` / `staff` / `manager` / `admin`）。

**customers**（users と 1:1、role=customer）
| user_id PK/FK | **member_no char(10) NOT NULL UNIQUE** | kana varchar(100) | phone **text** null `encrypted` | phone_hmac char(64) index | birthday **text** null `encrypted`(get で Carbon) | gender varchar(10) null | note varchar(1000) null | stripe_customer_id varchar(40) null index | created_via varchar(20) | timestamps |

- `member_no`：会員番号。`ARK` + 顧客ID（`user_id`）を6桁ゼロ詰めした形式（例：`ARK000164`）。
  DB内部ID（`user_id`）とは別に持たせた正式な業務項目で、`Customer` モデルの `creating` フックで
  一度だけ発番し、以後変更しない。`user_id` は既に一意性が保証された AUTO_INCREMENT 値から生成する
  ため、追加の採番テーブルなしに同時登録でも安全に一意。既存顧客は migration で一括 backfill 済み。

- `phone_hmac` = `hash_hmac('sha256', 正規化電話（数字のみ）, config('security.pii_lookup_key'))`。等価検索用。平文の検索コピーは持たない。
  HMAC キーは `APP_KEY` とは独立した `PII_LOOKUP_KEY` を使用する。キーのローテーション時は全 `customers` 行の
  `phone_hmac` 再計算が必要なため、再計算コマンドは Phase 2+ で用意する。
- **Phase 1 実装差異（rev.5 意図から決定）**：`phone` / `birthday` は当初案の `varchar(20)` / `date` では
  Laravel の `encrypted` cast の暗号文（長い base64）が収まらないため **`text`** に変更。
  `birthday` は `encrypted:date` が Laravel 13 で未対応のため `encrypted` cast + 復号後 `Carbon::parse` の get アクセサ。
  PII の at-rest 暗号化 + `phone_hmac` 等価検索という設計意図は不変。日付での DB 側フィルタが必要になった場合は Phase 2+ で再検討。

**staff**（users と 1:1、role in staff/manager/admin）
| user_id PK/FK | display_name varchar(50) | color varchar(7) | is_bookable bool | sort_order smallint | timestamps |

**staff_shifts**
| id | staff_id FK | work_date date | start_at time | end_at time | origin varchar(16) default `manual` | timestamps |
index: `(staff_id, work_date)`, `(work_date, origin)`
`origin`: `manual`（管理者が直接作成・例外日の枠。自動生成は絶対に触らない）/ `template`（基本シフトから自動生成）。既存行はすべて `manual` 既定なので、migration 適用だけで既存の枠・予約に影響なし。

**staff_shift_templates**（基本シフト = 曜日ごとの通常勤務時間。#11）
| id | staff_id FK | weekday tinyint（0=日〜6=土） | start_at time | end_at time | is_active bool | timestamps |
index: `(staff_id, weekday)`。同一曜日に複数行 = 複数時間帯。

**staff_shift_exceptions**（例外日。#11）
| id | staff_id FK | exception_date date | is_off bool | note varchar(200) | timestamps |
unique: `(staff_id, exception_date)`。
`is_off=true` = その日は休み（自動生成しない・生成済み template 枠は削除）。`is_off=false` = 時間変更（実枠は staff_shifts/origin=manual）。例外日がある (staff, date) は自動生成の対象外。

### メニュー・リソース

**services**
| id | name varchar(100) | duration_min smallint | price int | category varchar(50) | is_online_bookable bool | color varchar(7) | is_active bool | sort_order smallint | timestamps |

**service_staff**（pivot）: `service_id` `staff_id`（複合 PK）

**booths**
| id | name varchar(50) | sort_order smallint | is_active bool | timestamps |

### 利用権（ARK Membership。Stripe 課金とは別物）

**membership_plans**
| id | name varchar(100) | monthly_price int | included_sessions_per_month smallint | stripe_price_id varchar(40) | is_active bool | timestamps |

**memberships**
| id | customer_id FK | membership_plan_id FK | stripe_subscription_id varchar(40) index | status enum(active,paused,canceled) | current_period_start date | current_period_end date | period_available smallint（キャッシュ） | started_at | canceled_at null | timestamps |

**membership_usage_transactions**（追記のみ・UPDATE/DELETE しない）
| id | membership_id FK | period_start date | type enum(GRANT,RESERVE,RELEASE,CONSUME,ADJUST) | delta smallint | reservation_id FK null | staff_id FK null | reason varchar(255) null | dedupe_key varchar(100) **UNIQUE** | created_at |
index: `(membership_id, period_start)`, `(reservation_id)`
- 当期 available = 当該 `period_start` の `SUM(delta)`。held = 当期の未解消 RESERVE 本数。total = available + held。
- `dedupe_key` 例: `resv:{reservation_id}:RESERVE` / `resv:{reservation_id}:CONSUME` / `grant:{membership_id}:{period_start}`

### 回数券

**ticket_products**
| id | name varchar(100) | total_count smallint | price int | validity_days smallint | stripe_price_id varchar(40) | is_active bool | timestamps |

**ticket_wallets**
| id | customer_id FK | ticket_product_id FK | purchased_count smallint | balance smallint（キャッシュ）| expires_at date | payment_id FK null | status enum(active,exhausted,expired) | timestamps |
index: `(customer_id, status)`, `(expires_at)`

**ticket_transactions**（追記のみ）
| id | ticket_wallet_id FK | type enum(PURCHASE,RESERVE_HOLD,RESERVE_RELEASE,CONSUME,GRANT,REVOKE,EXPIRE,ADJUST) | delta smallint | reservation_id FK null | staff_id FK null | reason varchar(255) null | dedupe_key varchar(100) **UNIQUE** | created_at |
index: `(ticket_wallet_id, id)`, `(reservation_id)`
- available = `SUM(delta)`。消化元 wallet は **FEFO**（有効期限が近い順）。1 予約 = 1 wallet。
- `dedupe_key` 例: `resv:{reservation_id}:RESERVE_HOLD` / `resv:{reservation_id}:CONSUME` / `expire:{wallet_id}:{yyyymm}`

### 予約

**reservations**
| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| customer_id | FK | |
| service_id | FK | |
| staff_id | FK null | |
| **is_staff_requested** | **bool default false** | **顧客がそのスタッフを明示的に指名したか（§5）。staff_idの割当理由（指名／自動割当）をstaff_idだけでは区別できないため追加** |
| booth_id | FK null | |
| starts_at | datetime | |
| ends_at | datetime | |
| source | enum(HOTPEPPER,EPARK,ARK_WEB,PEAK_MANAGER,ADMIN) | |
| payment_method | enum(single,membership,ticket,onsite,unpaid) | |
| payment_status | enum(unpaid,pending_payment,authorized,paid,voided,refunded,partially_refunded,failed) | |
| payment_expires_at | datetime null | pending_payment のときのみ |
| status | enum(pending_payment,pending_external_sync,confirmed,completed,no_show,canceled,expired) | |
| attended_at | datetime null | |
| canceled_at | datetime null | |
| cancel_reason | varchar(255) null | |
| external_provider | varchar(20) null | |
| external_reservation_id | varchar(64) null | |
| sync_status | enum(PENDING,SYNCED,FAILED,NOT_REQUIRED) | |
| synced_at | datetime null | |
| sync_error | varchar(500) null | |
| version | int default 0 | 楽観ロック |
| notes | varchar(1000) null | |
| created_by | FK users null | |
| timestamps | | |

index: `(starts_at)`, `(staff_id, starts_at)`, `(customer_id, starts_at)`, `(status)`, `(payment_status)`, `(payment_expires_at)`,
`(external_provider, external_reservation_id)` **UNIQUE**（NULL 許容）

**reservation_resource_slots**（**DB レベルの二重予約防止**）
| id | resource_type enum(staff,booth) | resource_id bigint | slot_start datetime | reservation_id FK | created_at |
**UNIQUE(resource_type, resource_id, slot_start)** ／ index `(reservation_id)`

- スロット丸め（`app/Support/SlotKey`）：
  - 顧客予約：`starts_at` は `slot_minutes` の倍数のみ許可（境界限定）。
  - 管理者の任意時刻予約（`RESERVATION_ALLOW_ADMIN_FREE_TIME=true`）：占有スロットを
    `start = floor(starts_at / slot)`〜`end = ceil(ends_at / slot)` で生成（端数スロットも占有）。
- 予約作成/変更/キャンセル/失効時、対象リソース × 占有スロットの行を**同一 transaction で INSERT / DELETE / 付替え**。
- prune：`slot_start < now() - RETENTION_RESERVATION_SLOTS_PAST_DAYS` かつ予約が terminal 状態のもの。
- **容量**：1 店舗・稼働リソース 10・営業 12h・15 分・90 日先保持で数万行オーダー。
  1 行あたりの実バイト数（InnoDB 行 + セカンダリ index overhead 込み）は **Phase 3 で実測**し、
  実測値と将来予測（1 年後 / 3 年後）を本ファイルの「容量実測」節に追記する。固定バイト見積りは置かない。

**reservation_notification_dismissals**（予約台帳のオンライン予約通知・既読管理。管理者ごと）
| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| user_id | FK users | 既読にした管理者 |
| reservation_id | FK reservations | |
| dismissed_at | datetime | |

**UNIQUE(user_id, reservation_id)**（`rnd_user_reservation_unique` として明示命名。自動生成名は
MySQL の識別子長 64 文字制限を超えるため）。`timestamps` は持たない（`dismissed_at` のみ）。
`localStorage` ではなく DB で既読を持つことで、別ブラウザ・別端末からログインしても再表示されない。

**staff_schedule_blocks**（予約以外でスタッフ／ブースの時間を埋める「予定ブロック」。§27-46）
| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| staff_id | FK staff null | どちらか一方必須（両方指定も可） |
| booth_id | FK booths null | |
| work_date | date | |
| start_at | time | |
| end_at | time | |
| type | varchar(20) | enum(BREAK,MEETING,ADMIN,CLEANING,TRAINING,OUT,OTHER) |
| title | varchar(100) null | `type=OTHER` のときのみ入力必須（アプリ層で検証） |
| note | varchar(500) null | |
| created_by | FK users null | |
| timestamps | | |

index: `(staff_id, work_date)`, `(booth_id, work_date)`

- `Reservation` を顧客なしで無理やり流用せず、独立したテーブルとして管理する（§28）。
- staff_id / booth_id が「どちらか一方必須」であることは DB の CHECK 制約ではなく
  `ScheduleBlockService` のアプリ層バリデーションで担保する（DB 横断のポータビリティを優先）。
- 予約との二重予約防止は `reservation_resource_slots` のような unique 制約ベースの仕組みを
  流用せず、`ScheduleBlockService` が書き込み時に予約テーブル・他ブロックとの時間帯重複を
  明示的にクエリで検証する（既存の勤務時間・メニュー対応可否チェックと同じ「クエリ検証」方式）。
- `AvailabilityService::openStartTimes()` は、`reservation_resource_slots` による占有チェックに加えて
  同じ日付ぶんのブロックを1クエリでまとめて取得し、時間帯が重なる候補開始時刻を除外する（N+1禁止）。
- 顧客予約ではないため、作成・変更・削除は顧客へのメール・SMS・Stripe・回数券・月額プランを
  一切動かさない（§46）。作成・変更・削除は既存監査ログ（`AuditLogger`）に記録する。

### 決済（Stripe 課金）

**payments**
| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| customer_id | FK `customers.user_id` restrict | |
| reservation_id | FK `reservations` null restrict | Phase 5 は常に非 null |
| kind | varchar(20) | `single`（Phase 5 で使うのはこれのみ）/ `ticket_purchase` / `membership_invoice` は将来用 |
| provider | varchar(20) | 既定 `stripe` |
| payment_operation_id | char(36) **UNIQUE** | UUID。全 Idempotency-Key の安定な根（PLAN §7） |
| amount | int unsigned | JPY はゼロ十進通貨 |
| currency | char(3) | 既定 `jpy` |
| status | varchar(24) | `pending`/`authorized`/`succeeded`/**`voided`**/`failed`/`partially_refunded`/`refunded` |
| capture_method | varchar(10) | 既定 `manual` |
| stripe_payment_intent_id | varchar(40) null **UNIQUE** | |
| stripe_charge_id | varchar(40) null | capture 後 |
| authorized_at / paid_at / voided_at | datetime null | `paid_at` は capture 成功時のみ |
| refunded_amount | int unsigned | キャッシュ。正本は `payment_refunds` の SUM |
| failure_code | varchar(50) null | 要約コードのみ |
| failure_message | varchar(255) null | 生の Stripe メッセージを顧客へ出さない |
| needs_attention | bool | 孤立決済・曖昧結果の「要対応」 |
| last_synced_at | datetime null | reconcile / webhook sync の最終突合 |
| created_by | FK users null | |
| timestamps | | |

index: `(reservation_id)`, `(status)`, `(needs_attention)`

- **旧 rev.5 案の `idempotency_key varchar(100) UNIQUE` は採用しない。** 1 カラムでは create/capture/cancel の 3 種を保持できないため、
  `payment_operation_id` から都度導出する（PLAN §7）。
- カード PAN / CVC / `client_secret` / Stripe レスポンス全文を保存しない。
index: `(reservation_id)`, `(stripe_payment_intent_id)`

**payment_refunds**
| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| payment_id | FK `payments` restrict | |
| refund_operation_id | char(36) **UNIQUE** | UUID。返金は payment とは別の論理操作 |
| amount | int unsigned | |
| reason | varchar(255) **NOT NULL** | 機微操作のため必須（PLAN §12） |
| status | varchar(16) | `pending` / `succeeded` / `failed` |
| stripe_refund_id | varchar(40) null | |
| failure_code / failure_message | varchar null | |
| created_by | FK users **NOT NULL** | |
| timestamps | | |

index: `(payment_id)`

- `SUM(succeeded の amount) + pending <= payments.amount` を transaction 内で `FOR UPDATE` 検査し、二重・過剰返金を防ぐ。

- Cashier 標準テーブル（`subscriptions` / `subscription_items`）は**課金契約の記録専用**。
  「月何回」は置かない。`memberships.stripe_subscription_id` で参照。

### 認証・MFA（Phase 5.5 / Phase 9.6 で更新）

> **Phase 9.6**: `passkeys` テーブルは撤去（migration `2026_09_10_000008_drop_passkeys_table`）。
> MFA は 6 桁 TOTP + SMS フォールバック + Recovery Code のみ。

**users**（Phase 9.6）
| password varchar **NULL 許容**（Google のみで登録したユーザーはパスワード未設定） |

**user_social_accounts**（Phase 9.6 / Google ログイン）
| id | user_id FK users cascade | provider varchar(32) | provider_user_id varchar(191) | provider_email varchar null | timestamps |

- **`UNIQUE(provider, provider_user_id)`**。email は identity key にしない。
- OAuth の access/refresh token は保存しない（ログイン用途のみ）。
- 将来 Apple / LINE を足しても行を増やすだけ（users に provider 列を増やさない）。

**staff**（Phase 5.5 で追加）
| phone **text** null `encrypted` | phone_hmac char(64) index | phone_verified_at datetime null |

- `customers.phone` と同じ PII 方針。等価検索は `PII_LOOKUP_KEY` による keyed HMAC（`APP_KEY` に依存しない）。

**mfa_sms_challenges**（技術データ・保持 7 日で `model:prune`）
| id | user_id FK cascade | purpose varchar(20)（`login` / `phone_verification`）| phone_hmac char(64) | code_hash varchar(255) | expires_at datetime | attempts tinyint | used_at datetime null | sent_at datetime | ip varchar(45) null | timestamps |

- **OTP の平文を保存しない**（`code_hash` のみ）。**平文電話番号も保存しない**（`phone_hmac` で照合）。
- 一回使用で無効（`used_at`）。試行上限で失効。TTL・上限値はすべて `config/mfa.php`。

### 基盤・技術

- `webhook_events` / `sync_logs` / `audit_logs` / `db_size_snapshots`：§2 の表のとおり。
- `settings`：`key varchar(80) PK` / `value varchar(255)` / `type varchar(20)`（営業時間・スロット粒度・キャンセル規定・仮予約 HOLD 時間）。
  - 予約受付（#11・キー未設定なら「制限なし」＝後方互換）：
    `booking.horizon_mode`（`none`|`monthly`|`rolling`）/ `booking.release_day_of_month`（1–28、推奨 20）/
    `booking.horizon_days`（rolling 用）/ `booking.min_lead_minutes`（直前締切）。旧 `booking.closed_dates` は Phase 11 migration が `store_calendar_days` へ取り込み、以後は新テーブルが正本。
    正本は `App\Domain\Reservation\BookingWindow`。`SettingsSeeder` にはあえて含めず、管理画面「勤務枠 › 予約受付」で保存した時点から有効化される。
- Laravel 基盤：`password_reset_tokens` / `sessions` / `jobs` / `job_batches` / `failed_jobs` / `cache` / `cache_locks`。

### Phase 11 Task 11-2 業務マスタ

- `service_analysis_categories`: 追加可能な `code` UNIQUE / name / active / sort。初期値は M, T, A, M&T, A&T。
- `tax_categories` / `tax_rates`: 税区分と basis point の期間付き税率。期間は `[effective_from, effective_to)`、重複はtransaction内ロックで禁止。税額計算と丸めは Task 11-3 以降で確定。
- `products`: 物販商品。code nullable UNIQUE / name / integer price / nullable tax category / active / sort。在庫や購入事実は持たない。
- `payment_methods`: 会計で選択する決済方法マスタ。code UNIQUE / name / enabled / order / external provider。既存 `payments` と Stripe 状態機械は変更しない。
- `store_calendar_days`: 通常日は行なし。business_date UNIQUE の休業/特別営業時間だけを保持。
- `monthly_sales_targets`: 月初日 UNIQUE / integer target amount。店舗デフォルトは `settings` の `sales.target.default_amount` で、月別行を優先。
- `employment_types` / `staff_employment_periods`: 雇用形態マスタと `[effective_from, effective_to)` のスタッフ履歴。
- `services.analysis_category_id` / `services.tax_category_id` / `ticket_products.tax_category_id` / `membership_plans.tax_category_id` はすべて nullable FK。既存行を推測backfillしない。`services.duration_min` を標準時間として再利用し、同義列は追加しない。
- 単一店舗前提のため `stores` / `store_id` は追加しない。営業日判定は `Asia/Tokyo`、既存のUTC datetime保存方針は変更しない。

### Phase 11 Task 11-3 事実データ

Task 11-3は既存行を推測backfillせず、以下の追加テーブルだけを作る。`reservations`は予定、`visits`は実績、
`payments`はStripe等のprovider transaction、`checkout_tenders`は会計の支払方法別内訳として責務を分離する。

**visits**（1件の来店事実）

| 主な列 | 意味 |
|---|---|
| customer_id FK | 必須。顧客ごとの完了来店履歴の正本 |
| reservation_id FK null UNIQUE | 予約あり／予約なしの両方を許容し、1予約の二重来店化を防止 |
| business_date date | `Asia/Tokyo`で判定する日計・月計の営業日。UTC timestampとは分離 |
| status | `draft` / `completed` / `voided` |
| started_at / completed_at | 実績timestamp（nullable） |
| primary_staff_id FK null / name snapshot | 主担当。実担当とは別責務 |
| visit_sequence unsigned int null | Task 11-4で完了時に採番する来店順snapshot。過去不明値はNULL |
| first_visit_gender_snapshot string null / first_visit_age_years_snapshot unsigned smallint null | Task 11-7。初診完了時だけ性別と営業日時点の満年齢を固定。既存行は推測backfillしない |
| future_reservation_exists_at_checkout bool null / snapshot_at | `true`=あり、`false`=なし、`null`=未確定／不明。値の確定はTask 11-4 |
| completion_operation_id char(36) null UNIQUE | Task 11-4の完了操作を冪等化する格納先 |

index: `(business_date,status)`, `(customer_id,business_date)`, `(primary_staff_id,business_date)`, `completed_at`。
`UNIQUE(customer_id,visit_sequence)`で顧客内の確定来店順重複を防ぐ。

**visit_treatments**（来店内の複数施術実績）

| 主な列 | 意味 |
|---|---|
| visit_id FK | 1来店に複数行 |
| service_id / analysis_category_id FK null | 現在マスタへの追跡用。過去不明値を許容 |
| service_name / category code / category name snapshot | マスタ変更後も過去帳票を再現する最小snapshot |
| actual_started_at / actual_ended_at / actual_minutes | 実績。標準時間`services.duration_min`とは分離 |
| status / sort_order / operation_key | `draft` / `completed` / `voided`、表示順、任意の冪等キー |

ロングは保存せず、完了施術の `SUM(actual_minutes) > 60` を来店単位で算出する。

**visit_treatment_staff**（施術の複数実担当）

`visit_treatment_id` / `staff_id null` / staff name snapshot / 実績開始終了 / `actual_minutes` / sort order。
`UNIQUE(visit_treatment_id,staff_id)`。完了境界で担当分数合計と施術分数の一致をServiceがtransaction内検証する。

**checkouts** / **checkout_lines**（会計ヘッダ・明細）

- `checkouts`: `visit_id UNIQUE`、`draft` / `finalized` / `voided`、整数円のsubtotal/tax/total、currency、確定・取消日時、取消理由、operation ID。
- `checkout_lines`: item type、施術／サービス／商品／回数券商品／月額プランへのnullable FK、名称・税区分code/name・適用税率basis point・数量・単価・税抜・税額・税込のsnapshot、スタッフ配分対象フラグ。
- 税端数方式は未確定のため自動計算せず、計算済み `net + tax = gross` を保存・検証する。
- マスタ変更はsnapshotへ遡及しない。確定後のヘッダは取消遷移だけ、明細は追加・更新・削除を禁止する。
- 確定時にヘッダ＝明細合計を行ロック下で検証する。金額はすべてunsigned integerでfloatを使わない。

**checkout_tenders**（会計支払内訳）

`checkout_id` / `payment_method_id` / amount / status / received_at / external reference / `payment_id null`。
現金5,000円＋PayPay6,000円等の複数支払を表現する。`payment_id`は既存provider transactionへの任意リンクであり、
既存`payments`の状態機械・返金責務は変更しない。確定時にreceived行の合計＝会計totalを検証する。

**staff_revenue_allocations**（スタッフ別売上snapshot）

`checkout_line_id` / `staff_id null` / `visit_treatment_staff_id null` / staff name snapshot /
`basis_minutes null` / 整数 `allocated_amount` / operation key。配分対象明細だけを対象とし、確定時に配分額合計＝明細税込額を検証する。
実担当時間を根拠として残しつつ、端数調整後の最終配分額そのものを正本にする。

**revenue_recognition_contracts** / **revenue_allocations**（施術日基準売上）

- 権利の正本を重複作成しない。contractは既存`ticket_wallets`または`memberships`（月額は対象期間付き）へのリンクと、購入時の元契約金額snapshotを持つ。
- 決済日基準はsource checkout line / 既存paymentへのnullableリンクから追跡し、施術日基準はallocationの`recognized_on`と整数amountから集計する。
- allocationはvisit / treatment / 既存ticket usage / membership usageへnullableリンクし、契約内連番、端数行フラグ、operation keyを持つ。
- usage FKはそれぞれUNIQUE、operation keyもUNIQUE。allocationは追記専用。契約行をロックして過剰配賦を防ぎ、close時だけ配賦合計＝元契約金額を要求する。
- 未消化、途中解約、返金、有効期限切れの帰属規則は未確定のためTask 11-3では固定しない。既存`payment_refunds`も変更しない。

重要操作（施術確定、会計確定／取消、支払内訳、スタッフ配分、契約配賦）は既存`AuditLogger`へ記録する。
複数行合計はDB CHECKでは表現せず、FK・UNIQUE・unsigned型とDomain Serviceのtransaction／`FOR UPDATE`を組み合わせて保証する。

### Phase 11 Task 11-4 予約完了transaction境界

既存の管理画面・routeは`ReservationService::markCompleted()`を唯一の入口として維持し、内部を
`VisitCompletionService::completeReservation()`へ委譲する。旧status更新・権利消化処理を並走させない。

同一の短いDB transaction内で、次の順序により確定する。

1. 対象`reservations`行を`FOR UPDATE`。
2. 対象`customers`行を`FOR UPDATE`し、同一顧客の来店順採番を直列化。
3. `reservation_id UNIQUE`を持つ`visits`を作成または既存draftとして再利用。
4. 事前入力済み施術・実担当があれば検証して確定。無い場合だけ、現行予約枠からbufferを除いた時間と予約スタッフを初期実績にする。既存completed予約のbackfillには使用しない。
5. 既存draft checkoutがある場合だけ`CheckoutService`で確定。checkoutが無ければ会計未確定として来店完了を許容し、税・支払を推測生成しない。
6. 既存`TicketReservationService` / `MembershipReservationService`で権利消化。既存台帳dedupe keyを再利用し、新しい減算経路を作らない。
7. 顧客行ロック下で`MAX(visit_sequence) + 1`を確定。既存過去予約は採番しない。
8. 同一の完了時刻を基準に、`reservations.active()`かつ`starts_at > snapshot_at`、対象予約以外を`EXISTS`で判定し、次回予約booleanを保存。
9. visitをcompleted、reservationを既存state machine経由でcompletedにし、`attended_at`と監査ログを保存してcommit。

`visits.reservation_id UNIQUE`、顧客内`visit_sequence UNIQUE`、予約行／顧客行ロック、予約単位の決定的な
`completion_operation_id`により、ダブルクリック・再送・同時workerを1件へ収束させる。正常完了済みretryは
保存済みsnapshotを再計算せず既存結果を返す。取消・no-show・legacy completed（visitなし）は新規完了しない。
Stripe API・外部予約APIはtransaction内で呼ばず、既存`payments`・`payment_refunds`の意味も変更しない。

### Phase 11 Task 11-5 日次集計read model

`DailyReportQuery`は`Asia/Tokyo`の指定営業日について、集計粒度ごとに独立したSQLを実行する。
Treatment、Staff、Checkout Line、Tenderを同じJOINへ連結せず、明細数の掛け算による二重計上を構造的に避ける。
`DailyReportService`が結果を`DailyBusinessSummary`へまとめ、月計・年間・Excel・管理APIの共通入口とする。

| 指標 | 日付・正本 | 除外・NULL |
|---|---|---|
| 来店／初診／次回予約 | `visits.business_date`、`status=completed`、`visit_sequence`、次回予約snapshot | draft/voided除外。snapshot NULLはunknown件数へ保持 |
| ロング | completed `visit_treatments.actual_minutes`のVisit内合計 | `>60`のみ。施術なし／分数NULLはfalseにせずunknown |
| 分析分類 | `analysis_category_*_snapshot`のVisit内distinct | 現行Service masterを参照しない。NULLはunknown。M&Tを分解しない |
| 決済日基準売上／支払方法 | finalized checkoutのreceived tender、`received_at`のJST営業日 | draft/voided checkout、voided tender除外。CheckoutなしVisitを推測しない |
| 税区分／税率 | finalized checkoutの`finalized_at`営業日に属するline snapshot | net/tax/grossを個別SUMし再計算しない。NULL snapshotはnullable bucket |
| 施術日基準売上 | 完了Visitの実施明細に直結する確定会計line + `revenue_allocations.recognized_on` | 契約購入lineを施術売上へ入れず、allocation未保存分を推測しない |

日時列はJST日の`[00:00, 翌00:00)`をUTCへ変換した半開区間で検索し、DATE関数を索引列へ適用しない。
既存`visits(business_date,status)`、`checkouts(status,finalized_at)`、lineのcheckout／treatment FK索引、
`revenue_allocations(recognized_on,contract)`を利用する。全支払方法の日計向けに
`checkout_tenders(status,received_at,checkout_id)`だけを追加し、支払方法別既存索引とは検索責務を分ける。
返金日／元決済日のどちらへ帰属させるかは未確定のため、Task 11-5では`payment_refunds`を自動控除しない。

### Phase 11 Task 11-6 月計read model

Task 11-6では集計テーブルやmigrationを追加せず、Task 11-5の事実データから都度集計する。
`DailyReportQuery::fetchRange()`は月範囲を集計粒度別SQLで`GROUP BY business_date`し、単日`fetch()`も同じ経路を使う。
月の日数にかかわらず集約SQLは8本で固定し、1日ごとのN+1を発生させない。timestamp列の期間抽出は従来どおり
UTC半開区間で索引を利用し、JST日付への変換はSELECT/GROUP BYだけで行う。

`MonthlyBusinessSummary`は全暦日の日次行、2売上基準、動的な支払方法／税snapshot bucket、来店系合計、unknown、
月率、1〜15日／16日〜月末、目標進捗、営業日数、平日／土日平均を保持する。月予約率と初診予約率は
日別率の平均ではなく、月合計の分子÷月合計の分母とする。分母0はNULLであり0%へ変換しない。

- 売上基準の既定は決済日。施術日基準は、完了施術に直結する確定lineと保存済み配賦だけを加算する。配賦契約の元line自体が施術直結する異常／互換データはdirect側だけに数え、二重加算しない。
- 支払方法は`is_enabled=false`でも期間内の受取事実を表示する。税はline snapshotのcode/name/rate/net/tax/grossを合計し、現行masterから再計算しない。
- `as_of_date`まで（当日を含む）を実績、翌日以降を残営業日とする。過去月は月末、現在月はJST今日、未来月は月初前日が既定。
- 通常休業日は現時点で設けず、`store_calendar_days.status=closed`だけを営業日・平均分母・残営業日から除外する。休業日の保存済み実績は月合計から除外しない。
- 平日=月〜金、土日=土・日。祝日判定はしない。日平均、平日平均、土日平均の分母は`as_of_date`までの非休業日で、未来日は含めない。
- 売上目標は`monthly_sales_targets`の月別値を優先し、無ければ`settings`の店舗既定値。差額=`実績-目標`、残必要売上=`max(目標-実績,0)`、残営業日平均=`残必要売上/残営業日`。目標なし・分母0はNULLを保持する。

返金帰属は引き続き未確定であり、月計でも自動控除しない。

### Phase 11 Task 11-7 顧客統計read model

`CustomerAnalyticsQuery`は`visits.status=completed`・JSTの`business_date`を正本に、対象月初診cohortを`visit_sequence=1`から抽出する。再診と離反は顧客単位の`EXISTS / NOT EXISTS`、2/6/10回到達は`as_of_date`までの最大sequenceで判定する。初診人数は月計の`first_visit_count`と同じ定義で、来店件数と顧客人数を混同しない。

初診時の性別・満年齢は`visits.first_visit_*_snapshot`、初回担当・次回予約は既存Visit snapshot、コースは初診の完了`visit_treatments`分析分類snapshotを参照する。年齢は`AgeDecadeBucket`の承認済み区分へ変換する。属性NULLはunknown人数、保存元のない来店目的・動機・紹介者・地域は`not_captured`とし、現在の顧客プロフィールや予約、自由入力を過去属性として推測しない。既存Visitの新列はNULLのまま。詳細は`docs/CUSTOMER_ANALYTICS.md`。

### Phase 11 Task 11-8 スタッフ勤怠・稼働率read model

- `staff_attendances`: `staff_id` FK、JST出勤日`business_date`、UTCの`clock_in_at` / `clock_out_at` nullable、`status`、`note`、timestamps。`(staff_id,business_date)`は非uniqueで分割勤務を許容する。
- `staff_attendance_breaks`: `staff_attendance_id` FK、UTCの`start_at` / `end_at`、`type`、`note`、timestamps。勤怠更新時は明示された休憩一覧へ置換し、auditは親の作成・修正に残す。
- `visits.staff_requested_at_checkout` nullable boolean、`requested_staff_id_at_checkout` nullable bigint: 明示的指名事実と指名先IDの完了時snapshot。旧VisitはNULLのまま。IDは削除後も事実として保持するためFKを張らない。
- `StaffUtilizationService`: 来店指標は主担当Visit、稼働分は完了施術の実担当分数を別GROUP BY。勤務予定は`staff_shifts`、実勤怠は新表。予約可能分は店舗カレンダー、予定シフト、staff block、実休憩のinterval演算。社員/アルバイトは`staff_employment_periods`の対象日履歴から分類する。詳細は`docs/STAFF_UTILIZATION.md`。

### Phase 11 Task 11-9 時間帯別稼働率read model

`TimeBandUtilizationService`は新テーブルを作らず、Task 11-8と同じ勤務・予約可能区間および`MinuteIntervals`を4つのJST時間帯へ交差分割する。実担当の時刻が不明または分数と不整合の場合は時間帯を推測せずNULL/unknownを保持する。詳細は`docs/TIME_BAND_UTILIZATION.md`。

### Phase 11 Task 11-10 年間read model

新しい集計テーブルやmigrationは追加しない。`AnnualReportService`は月計・顧客cohort・スタッフ/時間帯稼働率を12か月合成し、年率は分子/分母合計で算出する。詳細は`docs/ANNUAL_REPORTING.md`。

### Phase 11 Task 11-11 Excel出力

`MonthlyBusinessSummary.actual_totals/actual_ratios`と`AnnualReportService.as_of_totals`は保存済み日次事実からの基準日時点read model。6シートの原本固定cell mappingは`docs/EXCEL_EXPORT.md`。

### Phase 11 Task 11-16 日別営業記録

`daily_business_notes`: `id`、`business_date` date UNIQUE、`business_condition` nullable TEXT（営業の様子）、`reflection` nullable TEXT（振り返り）、`created_by` / `updated_by` nullable users FK（ユーザー削除時はNULL）、timestamps。同一日1行で、未入力はNULL。単一店舗のためstore列は設けない。文章はmanual narrative dataであり、売上・来店等のReporting factsへ混ぜない。更新履歴は既存`audit_logs`で追跡する。原本Excelの日報D/H列へ書くが、原本からの推測backfillはしない。

### Phase 11 Task 11-19 来店・会計入力

- `checkouts.visit_id` をnullableへ変更（UNIQUEは維持。来店会計は従来どおり1来店1会計）。来店なし会計（物販のみ・回数券/月額購入のみ等）は `visit_id NULL`、`customer_id`（nullable、匿名物販はNULL）と `sale_date` を持つ。架空Visitは作らない。
- `visit_staff_nominations`: `visit_id` / `staff_id`（nullOnDelete）/ `staff_name_snapshot`、UNIQUE(`visit_id`,`staff_id`)。来店単位・スタッフ単位の指名snapshotで、完了済み来店では変更不可（model guard）。
- `visits.nominations_recorded_at`: NULL=指名未記録（旧Visit。推測backfillしない）、値あり=`visit_staff_nominations`が正本。既存 `staff_requested_at_checkout` / `requested_staff_id_at_checkout` は「主担当が指名されたか」を表し、Task 11-8の指名率の意味を変えない。
- 会計明細の `item_type` は `service` / `product` / `ticket` / `membership` / `other`（`CheckoutLineItemType`）。税額は明細単位・内税・1円未満切り捨て（`TaxAmountCalculator`）でsnapshotし、集計時に再計算しない。
- 回数券購入明細は会計確定時に既存 `TicketLedgerService::grant` で数量分付与（dedupe `grant:checkout-line:{line}:{n}`）。月額明細は売上記録のみで利用権は既存月額管理が正本。

### 外部予約連携（Phase 9・`app/Domain/Integration`）

- `reservation_provider_mappings`：`provider`(32) / `reservation_id` FK cascade / `external_reservation_id`(191) / `external_customer_id`(191) / `fingerprint`(64) / `external_updated_at` / `external_version`(64) / `last_synced_at` / `last_seen_at` / `sync_status`(16 default `in_sync`)。
  **UNIQUE(provider, external_reservation_id)** ＋ **UNIQUE(provider, reservation_id)**（1 予約 = 1 provider 1 external）＋ index `(provider, sync_status)`。
- `reservation_sync_outbox` / `reservation_sync_events` / `reservation_sync_conflicts`：§2 の表。events は **追記専用**（Model が `updating` / `deleting` で例外）。
- `reservation_provider_sync_state`：`provider`(32) UNIQUE / `last_inbound_at` / `last_inbound_cursor`(191) / `last_outbound_at` / `last_reconcile_at`。
- 保持設定は `config/retention.php` の `prune.reservation_sync`。`reservations:prune-sync-logs` が succeeded/no_op/skipped event・完了 outbox・resolved/ignored conflict を保持日数で削除（open / needs_attention は残す）。

## 4. 作らないもの（YAGNI）

- `stores` / 各テーブルの `store_id`（単一店舗前提。多店舗確定時にマイグレーション）。
- `external_links`（polymorphic 汎用）→ `reservations` の専用カラムで足りる。
- `sync_jobs` テーブル → Laravel Queue + `sync_logs`。
- Reporting 集計テーブル → Phase 11 で必要になってから。

## 5. State Machine（`app/Support/StateMachine` 経由でのみ遷移）

- `reservations.status`:
  `pending_payment → (決済成功) → pending_external_sync | confirmed`、
  `pending_payment → (失敗/期限切れ) → expired`、
  `pending_external_sync → (Gateway 成功) → confirmed / (失敗) → sync_failed 扱い（status は pending_external_sync のまま sync_status=FAILED）`、
  `confirmed → completed`、`confirmed|pending_external_sync → canceled|no_show`
- `reservations.payment_status`:
  `unpaid → pending_payment → authorized → paid`、
  `pending_payment|authorized → failed → unpaid`、`authorized → (補償で取消) → voided`、`paid → refunded|partially_refunded`
- `payments.status`:
  `pending → authorized → succeeded(=capture 済み) → partially_refunded|refunded`、`partially_refunded → refunded`、
  `authorized → voided`、`pending → voided`、`pending|authorized → failed`
  - **後退遷移は定義しない。** 到着が遅れた古い Stripe イベントで状態が巻き戻ることを構造的に防ぐ。
  - 中間状態を観測できなかった場合は `StateMachine::pathTo()` が定義済み遷移だけを辿って追いつく。
  - authorize 成功＝`authorized`（顧客表示「予約確保中」）。**capture 成功後のみ `paid`/`succeeded`、顧客へ「決済完了」**。
- `ticket_wallets.status`: `active → exhausted`(balance=0) / `active → expired`
- `memberships.status`: `active → paused`(invoice 失敗) → `active`(invoice.paid) / `active|paused → canceled`

## 6. 容量実測（Phase 3 で追記）

> Phase 3 で `reservation_resource_slots` / `ticket_transactions` / `membership_usage_transactions` /
> `audit_logs` の実バイト/行を `information_schema.tables` と `SHOW TABLE STATUS` で計測し、
> ここに実測値・1 年後予測・3 年後予測・5GB に対する余裕を記録する。
# Phase 11 過去データ移行（Task 11-12）

`historical_import_batches`は原本の保護コピー、SHA-256、状態、作成者、取込/無効化日時を保持する。`historical_import_rows`と`historical_import_cells`はsheet/row/cellと暗号化原文・HMAC・validationを保持し、明細の推測backfillを行わない。`historical_metric_values`は出典行を一意参照する過去集計値で、Visit/CheckoutとはFKも集計経路も分離する。詳細は`docs/HISTORICAL_IMPORT.md`。

Task 11-13の`historical_metric_reviews`は過去集計値1件につき差異分類・確認状態・理由・確認者・確認日時を1行保持する。元値とARK値は上書きせず、照合時に再計算する。詳細は`docs/PHASE11_RECONCILIATION.md`。
