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
| user_id PK/FK | kana varchar(100) | phone **text** null `encrypted` | phone_hmac char(64) index | birthday **text** null `encrypted`(get で Carbon) | gender varchar(10) null | note varchar(1000) null | stripe_customer_id varchar(40) null index | created_via varchar(20) | timestamps |

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
| id | staff_id FK | work_date date | start_at time | end_at time | timestamps |
index: `(staff_id, work_date)`

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
- Laravel 基盤：`password_reset_tokens` / `sessions` / `jobs` / `job_batches` / `failed_jobs` / `cache` / `cache_locks`。

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
