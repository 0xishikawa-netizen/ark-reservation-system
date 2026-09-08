# Phase 3 — ローカル予約機能（中核）

設計正本：`docs/PLAN.md`（rev.5）。baseline：**Phase 2 baseline commit（作成後にハッシュを記入）**。
本ファイルは Phase 3 のタスク分割と進行ログ。

> Phase 2 baseline commit: `5f52239`（Phase 3 の git diff レビュー基準）

## 前提

- `RESERVATION_AUTHORITY=local` / `EXTERNAL_RESERVATION_GATEWAY=null`。
- **Stripe / Peak Manager / SALON BOARD とは接続しない。** `ExternalReservationGateway` は `NullExternalReservationGateway` のまま。
- Phase 3 の予約は「決済不要」経路：`status = confirmed` で直接作成（PLAN §7「local かつ決済不要は pending_payment を経ず直接 confirmed」）。
  `payment_method = onsite` / `payment_status = unpaid` 固定。**決済ロジックは書かない。**

## Phase 3 で実装するもの

reservations / reservation_resource_slots / Reservation State Machine / `SlotKey`（占有スロット計算の唯一の集約点）/
Availability（サーバー側 Query/Service）/ 予約 作成・変更・キャンセル / staff shift・service duration・二重予約防止の整合 /
顧客側予約 UI（スマホ）/ 管理側 予約 CRUD + 予約台帳（自作 Vue scheduler）/ 過去・terminal 予約の slot prune（housekeeping）/
audit / authorization / concurrency test / Phase 3 総合検証。

## Phase 3 で実装しないもの（厳守）

Ticket / ticket_transactions / ticket_wallets / Membership / membership_usage_transactions /
Stripe / PaymentIntent / Payment Element / Stripe webhook / Cashier subscription 業務 /
**pending_payment / payment_expires_at を使った仮予約・失効処理（← Phase 5）** /
Saga / Reporting / LINE / Peak Manager 実 API / SALON BOARD 実 API / WordPress 変更 / 本番 DB / 本番接続 /
multi-store / store_id / Phase 4 以降の先行実装。

`reservations` テーブルの enum には `pending_payment` / `pending_external_sync` / `expired` 等が含まれるが、
**Phase 3 では confirmed → {completed, canceled, no_show} のみを実際に遷移させる**。他は Phase 5/10 用の定義済みプレースホルダ。

## 共通ルール

- 実装は Codex（毎回 `-m gpt-5.6-sol`、CLI 自動アップデート禁止、最初に
  `AGENTS.md` / `docs/PLAN.md` / `docs/ARCHITECTURE.md` / `docs/DB_SCHEMA.md` / `docs/OPEN_QUESTIONS.md` / `docs/tasks/phase-03.md` を通読）。
- Claude Code：分割 / 指示 / migration・Docker・Sail / build・test / `git diff`（Phase 2 baseline 基準）/ DB 設計・concurrency・security レビュー。
- **予約の作成・変更・キャンセル・状態遷移は `ReservationService` / Action 経由に限定。Controller / Vue から直接 DB 更新しない。**
- `git add` / `commit` / `push` 禁止（Phase 2 baseline の上に未コミットで積む）。本番接続禁止。Stripe 禁止。
- 保護（変更しない）：`docs/PLAN.md`。他 docs は事実整合の追記のみ。
- この指示と PLAN に差異がある場合、安全性・データ整合性に関わる部分は **PLAN を優先**し差異を報告。

## HTTP ステータス規約（プロジェクト共通・Phase 3 で確定）

- **409 Conflict**：リソース競合（スロット重複＝二重予約、`reservations.version` 楽観ロック不一致）。
- **422 Unprocessable**：業務バリデーション（非 active マスタ、シフト外、duration 不整合、担当不可、過去時刻（顧客）、入力不正）。
- `QueryException`（UNIQUE 違反）を素の 500 にしない。必ずドメイン例外（`SlotUnavailableException` 等）→ 409 に変換。

## パーミッション追加（rev.5 §2 から導出）

| permission | 付与ロール | 用途 |
|---|---|---|
| `reservations.view` | admin / manager / staff | 予約台帳・予約一覧の閲覧（staff は「自分の予定」を含む店舗ビュー） |
| `reservations.manage` | admin / manager | 予約の作成・変更・キャンセル・完了/no_show（店舗側） |

- 顧客は permission 不要。`ReservationPolicy` で **own-record**（`reservation.customer_id === $user->customer->user_id`）。
- `Gate::before` 全権バイパスは作らない。`RolePermissionSeeder` を冪等更新。

---

## タスク一覧

### Task 3-1 — Reservation DB / Model / State Machine
- migration `create_reservations_table`（`docs/DB_SCHEMA.md` の reservations 定義に厳密準拠）：
  `customer_id` FK / `service_id` FK / `staff_id` FK null / `booth_id` FK null / `starts_at` datetime / `ends_at` datetime /
  `source` / `payment_method` / `payment_status` / `payment_expires_at` datetime null /
  `status` / `attended_at` null / `canceled_at` null / `cancel_reason` varchar(255) null /
  `external_provider` varchar(20) null / `external_reservation_id` varchar(64) null / `sync_status` default 'NOT_REQUIRED' /
  `synced_at` null / `sync_error` varchar(500) null / `version` int default 0 / `notes` varchar(1000) null /
  `created_by` FK users null / timestamps。
  index：`(starts_at)` `(staff_id, starts_at)` `(customer_id, starts_at)` `(status)` `(payment_status)` `(payment_expires_at)` /
  `(external_provider, external_reservation_id)` UNIQUE（NULL 許容）。
  enum は文字列カラム + PHP Enum で表現（`ReservationStatus` / `ReservationSource` / `PaymentMethod` / `PaymentStatus` / `SyncStatus`）。
- `app/Models/Reservation.php`（`customer()` `service()` `staff()` `booth()` `creator()` `resourceSlots()` hasMany、
  `casts` で datetime / Enum、`scopeForDate` `scopeForStaff` `scopeActiveStatuses`）。
- `app/Enums/Reservation/{ReservationStatus,ReservationSource,PaymentMethod,PaymentStatus,SyncStatus}.php`（backed enum, string）。
- `app/Domain/Reservation/ReservationStateMachine.php`：Phase 1 の `app/Support/StateMachine` を継承。
  `transitions()` は **PLAN §7 の完全な map** を定義：
  `pending_payment => [pending_external_sync, confirmed, expired]`、
  `pending_external_sync => [confirmed, canceled, no_show]`、
  `confirmed => [completed, canceled, no_show]`、
  `completed => []`、`no_show => []`、`canceled => []`、`expired => []`。
  不正遷移は `InvalidStateTransitionException`。`apply()` で `StateTransitioned` イベント発火（Phase 1 の仕組み）。
  **Phase 3 が実際に呼ぶのは confirmed→{completed,canceled,no_show} のみ。**
- Unit テスト：状態遷移の許可/不許可、`from===to`・未知遷移で例外、イベント発火。
- migration・model・enum・SM のみ。Service/Controller/UI はまだ作らない。

### Task 3-2 — SlotKey + reservation_resource_slots + 二重予約防止（DB レベル）
- migration `create_reservation_resource_slots_table`（`docs/DB_SCHEMA.md` 準拠）：
  `id` / `resource_type` enum(staff,booth)（文字列 + Enum）/ `resource_id` unsignedBigInteger / `slot_start` datetime /
  `reservation_id` foreignId → `reservations.id` cascadeOnDelete / `created_at`（`updated_at` なし）。
  **`UNIQUE(resource_type, resource_id, slot_start)`** / index `(reservation_id)`。
  ※ `resource_id` は staff の場合 `staff.user_id`、booth の場合 `booths.id`。（polymorphic FK は張らず enum + id で持つ。）
- `app/Support/SlotKey.php`（**占有スロット計算の唯一の集約点**。ここ以外で floor/ceil を書かない）：
  - `__construct(int $slotMinutes)` + `public static function fromSettings(): self`（`Settings::get('reservation.slot_minutes', config('reservation.slot_minutes', 15))`）。
  - `isBoundary(CarbonInterface $t): bool` — 分が `slotMinutes` の倍数かつ秒=0。
  - `occupiedSlots(CarbonInterface $startsAt, CarbonInterface $endsAt, bool $adminFreeTime): array<CarbonImmutable>`：
    - `adminFreeTime=false`（顧客）：`startsAt` が boundary でなければ例外。`endsAt` は `startsAt + n*slot`。
      返すのは `startsAt, startsAt+slot, ..., endsAt-slot`（半開区間）。
    - `adminFreeTime=true`（管理者の任意時刻）：`from = floor(startsAt/slot)`、`to = ceil(endsAt/slot)`。
      `from` から `to-slot` までの各境界。端数スロットも占有。
  - `adminFreeTime` の既定は `config('reservation.allow_admin_free_time', false)`。
- `app/Enums/Reservation/ResourceType.php`（staff / booth）。
- Unit テスト（`SlotKey`）：
  - 10:00〜11:00 / slot=15 → `[10:00,10:15,10:30,10:45]`。
  - 顧客で 10:07 開始 → 例外（boundary でない）。
  - 管理者 free-time 10:07〜10:52 / slot=15 → floor=10:00, ceil=11:00 → `[10:00,10:15,10:30,10:45]`。
  - 管理者 free-time 10:15〜10:20 → `[10:15]`（ceil=10:30）。
  - 隣接 10:00〜11:00 と 11:00〜12:00 → スロット集合が交差しない。
  - 重なり 10:00〜11:00 と 10:30〜11:30 → 交差する（`[10:30,10:45]`）。
- **この Task ではまだ Reservation と slot を結ぶ書き込みはしない**（Service は Task 3-3）。UNIQUE 制約と SlotKey の数学だけ。

### Task 3-3 — ReservationService / Action（create / reschedule / cancel / complete / no_show）
- `app/Domain/Reservation/ReservationService.php`（予約の重要更新の唯一の集約点）。または `app/Actions/Reservation/*` に分割し Service が束ねる。
- **create（`CreateReservation`）**：入力 = customer, service, staff?, booth?, starts_at, source（ARK_WEB|ADMIN）, actor, notes?, adminFreeTime(bool)。
  1. 事前バリデーション（下記「整合ルール」）。`ends_at = starts_at + service.duration_min`。
  2. `SlotKey::occupiedSlots(...)` で占有スロット算出（staff があれば staff スロット、booth があれば booth スロット。**最低 1 リソース**必須）。
  3. `DB::transaction`（**短く**・外部 HTTP なし）：
     - `reservations` INSERT（`status=confirmed`、`payment_method=onsite`、`payment_status=unpaid`、`sync_status=NOT_REQUIRED`、`version=0`、`created_by=actor`）。
     - `reservation_resource_slots` を占有スロットぶん **bulk INSERT**（`resource_type` × `resource_id` × `slot_start` × `reservation_id`）。
     - commit。
  4. UNIQUE 違反（`QueryException`, integrity constraint）→ catch → rollback → `SlotUnavailableException`（409）。
  5. `AuditLogger::log('reservation.created', $reservation, "予約作成 #{id} {starts_at}", $actor)`。
- **reschedule（`RescheduleReservation`）**：入力 = reservation, 新 starts_at, 新 staff?, 新 booth?, 期待 version, actor, adminFreeTime。
  1. バリデーション（同上）。
  2. `DB::transaction`：
     - `reservations` を **`WHERE id=? AND version=?`** で `lockForUpdate`（または楽観 `version` チェック）。不一致 → `StaleReservationException`（409）。
     - 当該 reservation の `reservation_resource_slots` を **DELETE**。
     - 新占有スロットを **INSERT**（UNIQUE 違反 → `SlotUnavailableException` 409、rollback で旧スロットも復元）。
     - `reservations` UPDATE（starts_at/ends_at/staff_id/booth_id、`version = version + 1`）。
     - commit。
   - **一時的に二重予約可能になる順序にしない**（delete→insert は同一 transaction 内なので他 tx から中間状態は見えない。他 tx が対象スロットを保持していれば INSERT が失敗して全体 rollback）。
   - `AuditLogger::log('reservation.rescheduled', ...)`。
- **cancel（`CancelReservation`）**：入力 = reservation, reason?, actor。
  `DB::transaction`：State Machine で `confirmed → canceled`（不正なら例外）、`canceled_at=now`、`cancel_reason`、
  `reservation_resource_slots` を DELETE（スロット解放）。commit。`AuditLogger::log('reservation.canceled', ...)`。
- **complete / no_show（`MarkReservationCompleted` / `MarkReservationNoShow`）**：State Machine 遷移 + `attended_at`（completed のみ）。
  スロットは解放しない（実績として保持。past prune は Task 3-7）。監査。
- すべて `ReservationStateMachine` 経由で状態変更（Controller で if 文にしない）。`StateTransitioned` イベントを購読して監査 or ログ（重複記録に注意、監査は Action 側で 1 回）。
- 整合ルール（**PLAN にある範囲のみ。勝手に複雑化しない**）：
  - `service.is_active = true`。顧客経路なら `service.is_online_bookable = true`。
  - `service.requires_staff = true` → `staff_id` 必須。`false` → 任意（指名なし可）。
  - `staff_id` があるなら `service_staff` に (`service_id`, `staff_id`) が存在（担当可能）。`staff.is_bookable = true`。
  - `staff_id` があるなら `starts_at`〜`ends_at` が当該 staff の `staff_shifts`（同一 `work_date`）の枠内に**完全に収まる**。
  - `booth_id` があるなら `booth.is_active = true`。
  - `ends_at - starts_at === service.duration_min`（分）。
  - 顧客経路：`starts_at > now`（過去予約不可）。**管理者経路：任意時刻可**（実績バックフィルのため。`adminFreeTime` とは別概念だが Phase 3 では「管理者は過去も可」とする）。
  - 最低 1 リソース（staff and/or booth）を占有すること。どちらも無い場合は 422。
- Feature テスト：create 正常（confirmed + slot 行）/ 非 active service → 422 / requires_staff で staff なし → 422 /
  service_staff 未登録 → 422 / staff シフト外 → 422 / duration 不整合 → 422 / 顧客の過去時刻 → 422 /
  cancel でスロット解放・再予約可能 / reschedule で旧スロット解放・新スロット確保・version+1 / stale version → 409。

### Task 3-4 — Availability Query（サーバー側）
- `app/Domain/Reservation/AvailabilityService.php`（or `app/Queries/AvailabilityQuery.php`）：
  `openSlots(int $serviceId, ?int $staffId, ?int $boothId, CarbonImmutable $date): array` を返す（顧客/管理どちらからも使う）。
  算出根拠：`service.duration_min` / 指定 staff（or `service_staff` の担当可能 staff 全員）/ その日の `staff_shifts` /
  既存 `reservation_resource_slots`（当日・当該リソース）/ `booth` 指定時は booth の空き。
  返す各候補は `starts_at`（boundary）で、`SlotKey::occupiedSlots(starts_at, starts_at+duration, false)` が
  すべて空いている（既存スロットと交差しない）もの。
- **availability は二重予約防止機構ではない**。UI 表示と事前チェック用。最終防衛は DB UNIQUE（Task 3-2/3-3）。
  「画面では空いていたが送信直前に埋まった」→ create 時に 409。
- テスト：シフト外は候補に出ない / 既存予約と重なる候補は出ない / 隣接は出る / duration 分の連続空きが無い時間帯は出ない /
  指名なし（staff 未指定）で担当可能 staff の少なくとも 1 人が空いていれば候補に出る。

### Task 3-5 — 顧客側 予約フロー（スマホファースト）
- ルート（`web,auth,verified` の customer グループ。`/mypage` 配下でよい）：
  `GET /mypage/reservations`（自分の予約一覧）/ `GET /mypage/reservations/{reservation}`（詳細・own-record）/
  `GET /reserve`（予約ウィザード：service → staff（任意）→ date → slot → confirm）/
  `POST /reserve`（`ReservationService::create` source=ARK_WEB, actor=顧客）/
  `DELETE /mypage/reservations/{reservation}`（`CancelReservation`・own-record）。
  （reschedule を顧客に出すかは PLAN §11「変更・キャンセル」に従い出す。`PUT /mypage/reservations/{reservation}`。）
- `app/Http/Controllers/Customer/ReservationController.php` + `ReserveController`（ウィザード）。**Service 経由のみ**。
- `app/Policies/ReservationPolicy.php`：`view` / `update` / `delete` = `reservation.customer_id === $user->customer?->user_id`
  （店舗側は別途 `reservations.view` / `reservations.manage`）。
- Inertia（`resources/js/Pages/Customer/`）：`Reserve/{Service,Staff,Date,Slot,Confirm}.vue`（または 1 ページのステッパー）、
  `Reservations/{Index,Show}.vue`。`CustomerLayout`。空き時間は Task 3-4 の API から取得。
  **決済画面は作らない。** 確定は local commit 完了後に「予約完了」表示。
- 予約作成 endpoint に rate limit（`throttle`。例 `reserve` limiter 10/min per user）。
- テスト：他人の予約 detail → 403 / 自分の予約 cancel → 200・slot 解放 / 予約ウィザード POST で confirmed 予約作成 /
  未認証 → login。

### Task 3-6 — 管理側 予約 CRUD + 予約台帳（自作 Vue scheduler）
- ルート（`admin.` グループ、`can:reservations.view` / `can:reservations.manage`）：
  `GET /admin/reservations`（一覧：日付・staff・状態フィルタ、`ReservationListQuery`）/
  `GET /admin/schedule`（予約台帳：`?date=` `?staff_id=`。横軸=時間、縦軸=staff（＋booth 切替は任意）、予約カード）/
  `GET/POST /admin/reservations`（新規：`ReservationService::create` source=ADMIN, actor=admin/manager, adminFreeTime=config）/
  `GET/PUT /admin/reservations/{reservation}`（編集＝`RescheduleReservation` or フィールド更新。version 楽観ロック）/
  `PATCH /admin/reservations/{reservation}/cancel` / `.../complete` / `.../no-show`。
- `app/Http/Controllers/Admin/{ReservationController,ScheduleController}.php`。**Service 経由のみ・`DB::` 直呼びなし。**
- `app/Queries/ReservationListQuery.php` / `ScheduleQuery.php`（台帳データ：指定日の staff 行 × 予約カード（顧客名・service・時間・status・source 色））。
- Inertia（`resources/js/Pages/Admin/`）：
  `Reservations/{Index,Create,Edit}.vue`、`Schedule/Index.vue`（**自作の軽量 scheduler**：素の div + CSS grid/absolute。
  大規模カレンダーライブラリを追加しない）。予約カードは実 DB と連動（モックにしない）。
  D&D は Phase 3 では**任意**（時間変更は編集フォームで可）。入れる場合も `RescheduleReservation` + version 楽観ロック + 409 ハンドリング。
- `AdminLayout` ナビに「予約」「予約台帳」。`auth.can.reservationsView` / `reservationsManage`。
- `RolePermissionSeeder`：`reservations.view`（admin/manager/staff）・`reservations.manage`（admin/manager）。authz テスト更新。
- テスト：staff（view のみ）→ 台帳 200・新規予約 403 / manager → 新規・編集・cancel・complete 成功・監査 /
  非 active master 選択 → 422 / version 不一致 → 409 / inactive staff/booth → 422。

### Task 3-7 — slot housekeeping（過去・terminal 予約の slot prune）
- **仮予約・支払い失効（pending_payment / payment_expires_at）は Phase 3 では実装しない**（Phase 5）。
  Phase 3 の予約は `confirmed` のみ生成されるため hold/expiration の概念は無い。この方針を本ログに明記。
- `app/Console/Commands/PruneReservationSlots.php`（`reservations:prune-slots`）：
  `slot_start < now() - config('retention.prune.reservation_resource_slots.past_days', 14)` かつ
  対応 `reservation.status ∈ {completed, canceled, no_show}` の `reservation_resource_slots` を削除（冪等・バッチ）。
  未来スロット・active 予約のスロットは削除しない。
- `routes/console.php` or scheduler に日次登録（`->daily()`）。
- テスト：過去 terminal 予約のスロットが prune される / 未来・active はされる / 冪等（2 回実行で同結果）。

### Task 3-8 — concurrency / boundary / authorization tests（MySQL 必須）
- `tests/Feature/Reservation/ConcurrencyTest.php`（**MySQL 前提。sqlite なら markTestSkipped + 理由**）。
  2 本の DB 接続（`DB::connection()` を複製し手動 `beginTransaction`）で以下を検証：
  1. 同一 staff・同一時間帯に 2 予約を（ほぼ）同時作成 → **1 件だけ成功**、他方は `SlotUnavailableException`（409）。
  2. 同一 booth・同一時間帯 → 二重確保不可。
  3. 開始時刻が違うが時間帯が重なる（A 10:00-11:00 / B 10:30-11:30・同一 staff）→ 片方のみ。
  4. 隣接（A 10:00-11:00 / B 11:00-12:00・同一 staff）→ **両方成功**。
  5. キャンセル後 → 同一枠を再予約可能。
  6. reschedule → 旧スロット解放・新スロット確保（version+1）。
- `tests/Feature/Reservation/GuardIsDbUniqueTest.php`：availability 事前チェックを**意図的にバイパス**（or stub して "空き" を返させる）→
  `ReservationService::create` が **DB UNIQUE 違反を捕捉してドメイン例外（409）**に変換する（500 にならない）ことを確認。
- boundary：顧客の非境界開始 → 422 / 管理者 free-time の floor/ceil / duration 端数。
- authorization：顧客 own-record（他人の予約 view/update/delete → 403）/ staff は `reservations.manage` 無しで 403 /
  manager/admin は 200 / `Gate::before` バイパスが無いこと。
- CSRF：予約 POST にトークン無し → 419（既存 `CsrfProtectionTest` パターン）。

### Task 3-9 — Phase 3 総合検証 / build / CI / security review
- `sail artisan migrate:fresh --seed` / `sail npm run build` / `sail artisan test` すべて green。
- CI（`.github/workflows/ci.yml`）：新 migration / test が走ることを確認、必要なら最小修正。
- `composer audit` / `npm audit` 脆弱性 0。secrets scan / production connection scan クリーン。
- Phase 4+ 先行実装なし（Ticket/Membership/Stripe/Reporting の痕跡が無いこと）を静的確認。
- **MySQL 上で `reservation_resource_slots` の UNIQUE が実際に競合防止として機能する**ことを確認（Task 3-8 が green）。
- `localhost`：顧客側予約ウィザード（`/reserve`）、顧客予約一覧（`/mypage/reservations`）、管理予約台帳（`/admin/schedule`）の応答。
  デモデータで **実際に 1 件予約を作成**し、管理台帳と顧客一覧の双方に反映されることを確認。
- 本ログに最終結果 + Phase 3 完了サマリ。

---

## 実行ログ

（Task ごとに：日時 / Codex 変更ファイル / Claude Code 検証 / 是正 / 結果）

---

### 2026-09-08 — Task 3-1（Reservation DB / Model / State Machine）: ✅ 完了（無修正）
- migration `2026_09_08_000014_create_reservations_table`（DB_SCHEMA.md 準拠：`customer_id→customers.user_id` restrict / `service_id→services.id` restrict / `staff_id→staff.user_id` nullOnDelete / `booth_id→booths.id` nullOnDelete / `created_by→users.id` nullOnDelete、`starts_at`/`ends_at`、`source`/`payment_method`/`payment_status(default unpaid)`/`payment_expires_at`/`status`/`attended_at`/`canceled_at`/`cancel_reason`/`external_provider`/`external_reservation_id`/`sync_status(default NOT_REQUIRED)`/`synced_at`/`sync_error`/`version(default 0)`/`notes`、index (starts_at)(staff_id,starts_at)(customer_id,starts_at)(status)(payment_status)(payment_expires_at)、unique(external_provider, external_reservation_id)）。
- Enum（backed string）：`ReservationStatus`（isTerminal()）/ `ReservationSource` / `PaymentMethod` / `PaymentStatus` / `SyncStatus`。
- `app/Models/Reservation.php`（casts で datetime + Enum、customer()/service()/staff()/booth()/creator()、scopeForDate/scopeForStaff/scopeActive）。
- `app/Domain/Reservation/ReservationStateMachine.php`：Phase 1 の `StateMachine` 継承、**PLAN §7 の完全遷移 map**（pending_payment/pending_external_sync/confirmed/… → …、terminal は空）。不正/from===to/未知で `InvalidStateTransitionException`、`StateTransitioned` 発火。**Phase 3 実運用は confirmed→{completed,canceled,no_show} のみ**。
- 共有 `app/Support/StateMachine/StateMachine.php` に最小修正：`apply()` が enum-cast カラム（`BackedEnum`）の場合 `->value` を from に使う（string の場合は従来どおり）。Phase 1 の DummyFlow テスト非退行を確認。
- factory：`ReservationFactory` + 不足していた `CustomerFactory`/`StaffFactory`/`ServiceFactory`/`BoothFactory`。
- Unit：`ReservationStateMachineTest`（許可遷移・from===to・terminal から・未知・イベント発火）/ `ReservationEnumTest`。
- **Service/Controller/Vue/reservation_resource_slots は未作成**（Task 3-2 以降）。
- **検証**：migrate clean ／ artisan test **127 passed / 812 assertions**（+8）。Phase 4+ 痕跡・秘密情報・本番接続なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-2（SlotKey + reservation_resource_slots + 二重予約防止 DB レベル）: ✅ 完了（無修正）
- migration `2026_09_08_000015_create_reservation_resource_slots_table`：`resource_type` varchar(8)(staff/booth) / `resource_id` unsignedBigInteger（staff.user_id or booths.id、**polymorphic FK なし**）/ `slot_start` datetime / `reservation_id`→`reservations.id` cascadeOnDelete / `created_at` のみ。**`unique(resource_type, resource_id, slot_start)`**（名 `reservation_resource_slot_unique`）+ index `(reservation_id)`。
- `app/Support/SlotKey.php`（**占有スロット計算の唯一の集約点**）：`__construct(slotMinutes>=1)` / `fromSettings()`（Settings `reservation.slot_minutes` 既定15）/ `isBoundary()` / `occupiedSlots(startsAt, endsAt, adminFreeTime=false)` → `list<CarbonImmutable>`（半開 [start,end)）。
  - 顧客（adminFreeTime=false）：`startsAt` 非境界で `NonBoundaryStartException`（`\DomainException`）。区間内の全境界（部分スロットも占有）。
  - 管理者 free-time：`from=floor(startsAt)`, `to=ceil(endsAt)`、端数スロットも占有。
  - floor/ceil は `startOfHour()` + `intdiv(minute/slot)*slot`。秒・マイクロ秒を 0 化。
- `app/Enums/Reservation/ResourceType.php`（staff/booth）／`app/Models/ReservationResourceSlot.php`（`UPDATED_AT=null`、`reservation()`）／`Reservation::resourceSlots()` 追加。
- `app/Exceptions/NonBoundaryStartException.php`。
- Unit `SlotKeyTest`（10:00-11:00→[00,15,30,45]、10:00-10:50→部分スロット占有、10:07 非境界例外、管理者 free-time floor/ceil、隣接非交差、重なり交差、`endsAt<=startsAt` 例外、`fromSettings`）。
- Feature `ResourceSlotUniqueTest`（**MySQL**：同一 (resource_type,resource_id,slot_start) の 2 件目 INSERT が `QueryException`、別スロット/別 resource_type は可、reservation delete で cascade）。
- **Service/Controller/Vue は未作成**（Task 3-3 以降）。
- **検証**：migrate clean ／ 対象テスト 14 passed ／ artisan test **141 passed / 834 assertions**（+14）。floor/ceil は SlotKey 内のみ。秘密情報・Phase4+痕跡なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-3（ReservationService / Action：create / reschedule / cancel / complete / no_show）: ✅ 完了（無修正）
- `app/Domain/Reservation/ReservationService.php`（予約の重要更新の唯一の集約点。`AuditLogger` DI）：
  - **create**：整合検証（service.is_active / 顧客は is_online_bookable / requires_staff→staff / service_staff 担当可 / staff.is_bookable / staff shift 枠内に完全に収まる / booth.is_active / duration=service.duration_min / 顧客の過去時刻不可・管理者は可 / 最低 1 リソース）→ `SlotKey::fromSettings()->occupiedSlots()` → **短い `DB::transaction`**（reservation INSERT（confirmed/onsite/unpaid/NOT_REQUIRED/version=0）+ `ReservationResourceSlot::insert()` bulk）→ `QueryException('23000')` を catch → `SlotUnavailableException`（rollback）。
  - **reschedule**：`DB::transaction` 内で `lockForUpdate` + `version !== expectedVersion` → `StaleReservationException` / confirmed 以外は 422 / 整合再検証 / 旧 slot DELETE → 新 slot INSERT（UNIQUE→`SlotUnavailableException`・rollback で旧 slot 復元）→ `update(..., version+1)`。**delete→insert は同一 transaction・一時的二重予約なし**。
  - **cancel**：`ReservationStateMachine::apply(status→canceled)`（不正遷移は 422）→ `canceled_at`/`cancel_reason` → slot DELETE（再予約可能）。
  - **markCompleted / markNoShow**：状態遷移 + `attended_at`（completed のみ）。**slot は解放しない**（実績保持）。
  - 業務バリデーション・不正遷移 → `Illuminate\Validation\ValidationException`（422）に統一。
- `ReservationInput` / `RescheduleInput`（readonly DTO）。`app/Exceptions/Reservation/{SlotUnavailableException,StaleReservationException}`。
- `bootstrap/app.php` `withExceptions`：`SlotUnavailableException` / `StaleReservationException` → JSON **409**（`errors.reservation`）/ Inertia は `back()->withErrors(...,'reservation')`（既存フォーム規約と一貫）。`shouldRenderJsonWhen` 非破壊。
- 監査 `reservation.{created,rescheduled,canceled,completed,no_show}`（summary は予約 ID・日時のみ・PII なし）。
- **Controller / Vue は未作成**（Task 3-4 以降）。
- **検証**：`ReservationServiceTest` **21 passed / 70 assertions** ／ artisan test **162 passed / 904 assertions**（+21）。秘密情報・Phase4+痕跡なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-4（Availability：サーバー側の空き枠算出）: ✅ 完了（無修正）
- ※ Codex は最終サマリ生成時に一過性の usage-limit に当たったが、実装ファイルは書き込み済み。Claude Code が実装を直接レビューし検証。
- `app/Domain/Reservation/AvailabilityService.php`：`openStartTimes(serviceId, staffId?, boothId?, CarbonImmutable $date): list<array{starts_at,ends_at,available_staff_ids}>`。
  - `SlotKey::fromSettings()` + `occupiedSlots()` を使用（floor/ceil 再実装なし）。営業時間は `Settings::get('business_hours.open|close')`（既定 10:00-22:00）+ config フォールバック。
  - `staffPool`：staffId 指定＝その 1 名（service_staff + is_bookable 条件）／null + requires_staff＝担当可能かつ bookable な全員／null + !requires_staff＝空。
  - `shiftsByStaff`（1 クエリ）／`occupiedByResource`（1 クエリ：`reservation_resource_slots` ⨝ `reservations`、status ∈ {pending_payment, pending_external_sync, confirmed}、当日、関係リソース）。N+1 回避。
  - 候補＝slot 境界・`startsAt + duration <= close`。各候補で「shift に完全に収まる」かつ「占有スロットと非交差」の staff を `available_staff_ids` に。requires_staff で 0 なら候補外。staffId 指定時は `== [$staffId]` のみ候補。booth 指定時は booth 非交差必須。
  - 隣接は候補・重なりは非候補（半開区間）。`available_staff_ids` は staffId 指定 or !requires_staff で `[]`。
- **availability は二重予約防止機構ではない**（UI/事前チェック用。最終防衛は DB UNIQUE）。
- `AvailabilityServiceTest`：**12 passed / 30 assertions**（シフト無し→0、既存予約で重なり候補消滅、隣接候補あり、指名なしで空き staff 列挙、booth 埋まりで候補消滅、duration 連続空き不足で 0 等）。
- **Controller / Vue は未作成**（Task 3-5 以降）。
- **検証**：artisan test **174 passed / 934 assertions**（+12）。秘密情報・Phase4+痕跡なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-5（顧客側 予約フロー・スマホファースト）: ✅ 完了（無修正）
- **設計決定**：Phase 3 顧客フローは `is_active && is_online_bookable && requires_staff=true` サービスのみ。担当は任意（指名なし可）で、指名なしは確定時に `AvailabilityService` の `available_staff_ids` 先頭を自動割当（単純割当）。顧客は booth を選ばない（staff スロットのみ占有）。
- Policy `ReservationPolicy`（view/update/delete = `reservation.customer_id === $user->customer?->user_id`。店舗権限は Task 3-6）。`AuthServiceProvider` に `Gate::policy(Reservation::class, ...)` 登録。
- ルート（`auth,verified`）：`GET /reserve`（ウィザード）/ `GET /reserve/availability`（JSON）/ `POST /reserve`（`throttle:reserve`）/ `GET /mypage/reservations`（一覧）/ `GET /mypage/reservations/{r}`（詳細・own）/ `PUT`（reschedule・`throttle:reserve`）/ `DELETE`（cancel）。
- `Reserve\ReserveController`（create=`OnlineBookableServiceQuery`／availability=`AvailabilityService`／store=指名なし自動割当→`ReservationService::create` source=ArkWeb, adminContext=false）。`Customer\ReservationController`（index=`CustomerReservationListQuery`／show=own／update=`ReservationService::reschedule`（version）／destroy=`ReservationService::cancel`）。**Controller に `DB::` 直呼びなし**。
- `StoreReservationRequest`（service is_active/online/requires_staff、staff は service_staff+is_bookable、starts_at future+`SlotKey::isBoundary`、customer レコード無し=403）／`RescheduleReservationRequest`（future+boundary、version required）。
- `reserve` limiter：`FortifyServiceProvider::configureRateLimiting()` に `perMinute(10)->by(user id/ip)`。
- Inertia `Customer/Reserve/Index.vue`（ステッパー：service→担当→date→空き時間→確認→確定。**決済 UI なし**）／`Customer/Reservations/{Index,Show}.vue`（reschedule/cancel は confirmed かつ未来のみ）。`CustomerLayout` ナビに「予約する」「予約一覧」。
- テスト：`ReservationBookingTest`（ウィザード、availability JSON、staff 指定/指名なし作成、過去/非境界/非 online で 422、`throttle` 429、staff ロール 403）／`MyReservationsTest`（own 一覧・詳細、他人 403、cancel で slot 解放・再予約可、reschedule version+1・不一致 409、未認証 redirect）。
- **管理 UI は未作成**（Task 3-6）。
- **検証**：`npm run build` 型エラー0 ／ artisan test **192 passed / 1059 assertions**（+18）。決済/Phase4+痕跡・秘密情報なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-6（管理側 予約 CRUD + 予約台帳（自作 Vue scheduler））: ✅ 完了（無修正）
- `Admin\ReservationController`（index=`ReservationListQuery`／customerSearch=`CustomerLookupQuery`（氏名/カナ LIKE + 電話 `phone_hmac` 等価）／availability=`AvailabilityService` JSON／create=`ReservationFormOptionsQuery`／store=`ReservationService::create` source=Admin, adminContext=true／edit／update=`ReservationService::reschedule`（notes 同時更新は同一 transaction）／cancel/complete/noShow=`ReservationService::*`）。**`DB::` 直呼びなし**。
- `Admin\ScheduleController`（`ScheduleQuery`：staff（色/sort）× shifts（非稼働シェーディング用）× その日の予約 × business_hours）。
- `RescheduleInput` に `updateNotes` / `notes`（任意・既定 false/null＝後方互換）。`Actions\Reservation\UpdateReservationNotes`。
- ルート：`admin.` グループに `reservations.*` + `schedule.index`。固定 3 本（`customer-search` / `availability` / `create`）を `{reservation}` より前に定義。全 action `can:reservations.view` / `can:reservations.manage`。
- FormRequest：`StoreAdminReservationRequest`（customer_id/service/staff/booth/starts_at、requires_staff→staff、service_staff+is_bookable、booth is_active、非境界は 422、**過去時刻は許可＝管理者**）／`UpdateAdminReservationRequest`（starts_at/staff/booth/version/notes）。
- `RolePermissionSeeder`：`reservations.view`（admin/manager/staff）・`reservations.manage`（admin/manager）。確認：admin=Y/Y, manager=Y/Y, staff=Y/n。authz テスト更新。
- Inertia：`Admin/Reservations/{Index,Create,Edit}.vue`（顧客は `v-autocomplete`→customer-search、409 で再読込導線）／`Admin/Schedule/Index.vue`（**自作の軽量台帳**：スタッフ列 × 時間軸、カードを `top`/`height` 算出で `position:absolute`、非稼働帯シェーディング、日付ナビ、実 DB `ScheduleQuery` 連動・モックなし、外部ライブラリなし）。`AdminLayout` に「予約」「予約台帳」ナビ + `auth.can.reservationsView/reservationsManage` + `inertia.d.ts`。
- テスト：`AdminReservationManagementTest`（staff は台帳 200・create 403、manager/admin CRUD + 状態変更 + 監査、非 active master 422、過去時刻 OK、非境界 422、version 不一致 409）／`ReservationListQueryTest` / `ScheduleQueryTest` / `CustomerLookupQueryTest`。
- **検証**：`seed` 冪等 ／ `npm run build` 型エラー0 ／ artisan test **205 passed / 1177 assertions**（+13）。決済UI/Phase4+痕跡・schedulerライブラリ・秘密情報なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-7（過去・terminal 予約の slot housekeeping prune）: ✅ 完了（無修正）
- `app/Console/Commands/PruneReservationSlots.php`（`reservations:prune-slots {--days=} {--dry-run}`）：`slot_start < now()->subDays($days)`（既定 `config('retention.prune.reservation_resource_slots.past_days', 14)`）**かつ** 紐づく `reservation.status ∈ {completed, no_show, canceled, expired}` の slot のみ、2,000 件単位 `chunkById` で削除。**active（pending_payment/pending_external_sync/confirmed）・未来・保持期間内は削除しない**。冪等（再実行で削除 0）。`--dry-run` は件数のみ。
- `routes/console.php`：`Schedule::command('reservations:prune-slots')->dailyAt('03:30')->withoutOverlapping()`（`schedule:list` に表示確認）。
- **仮予約・pending_payment・payment_expires_at のロジックは追加していない**（Phase 5）。`expired` は enum 上の terminal として prune allowlist に含めただけ。
- テスト `PruneReservationSlotsTest`（completed/no_show 過去→prune、confirmed 過去→残る、保持期間内→残る、`--dry-run`、冪等、`--days` 指定）：**9 passed / 30 assertions**。
- **検証**：`reservations:prune-slots --dry-run` 動作（件数 0）／`schedule:list` に日次登録／artisan test **214 passed / 1207 assertions**（+9）。仮予約ロジックなし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-8（concurrency / boundary / authorization テスト、MySQL 必須）: ✅ 完了（無修正・テストのみ）
- ※ `ReservationService` 等アプリ本体の改修は**不要**（staff 指名経路 + slot 直 INSERT で availability をバイパス）。
- `GuardIsDbUniqueTest`（**2 passed / 13 assertions**）：availability 事前チェックをバイパスしても、`reservation_resource_slots` の直 INSERT による UNIQUE 違反 → `SlotUnavailableException` → **HTTP 409**、`reservations` 行は rollback、**500 にならない**。
- `ReservationConcurrencyTest`（**6 passed / 47 assertions**・MySQL 2 コネクション `mysql_second`・接続 B は `innodb_lock_wait_timeout=1`・`DatabaseMigrations`）：
  1. 同一 staff・同一時間帯 → 1 件のみ成功（未コミット中は errno 1205、A commit 後は 1062 → ドメイン例外）
  2. 同一 booth・同一時間帯 → 二重確保不可
  3. 開始違い・時間帯重なり（10:00-11:00 / 10:30-11:30）→ 片方のみ
  4. 隣接（10:00-11:00 / 11:00-12:00）→ **両方成功**
  5. キャンセル後 → 同一枠を再予約可能
  6. reschedule → 旧 slot 解放・新 slot 確保・version+1、旧枠に別予約可能
  → **MySQL 実接続で `UNIQUE(resource_type, resource_id, slot_start)` が競合防止として実際に機能することを確認**（availability だけに依存していない）。
  sqlite では `markTestSkipped`。
- `ReservationBoundaryTest`：顧客非境界 422 ／ 管理者 `allow_admin_free_time=false` 非境界 422 ／ `=true` で 10:07-10:52 が floor 10:00〜ceil 11:00 の 4 スロット占有（`SlotKey` 経由）。
- `ReservationAuthorizationTest`：顧客 own-record（他人の予約 view/update/delete → 403）／ staff は `reservations.manage` 無しで admin 予約作成 403 ／ manager/admin 可 ／ `Gate::before` バイパスなし。
- `ReservationCsrfTest`：`/reserve`・`/admin/reservations` の POST がトークン無しで 419。
- **検証**：対象 5 ファイル green（MySQL）／artisan test **全体 green**。秘密情報・Phase4+痕跡なし。baseline `5f52239` 不変。

### 2026-09-08 — Task 3-9（Phase 3 総合検証）: ✅ 完了（Claude Code 主導）
- `migrate:fresh --seed`（16 migration + RolePermission/Settings seeder）クリーン。`DemoMasterSeeder`（local）でデモデータ投入。
- `npm run build`：`vue-tsc --noEmit` 型エラー 0、vite build 成功、>500kB 警告なし。
- `artisan test`：**232 passed / 1324 assertions / 0 failed**（MySQL 上。`phpunit.xml` は `DB_CONNECTION` を上書きせず＝既定 mysql、`DB_DATABASE=testing`）。
- `composer audit` / `npm audit`：脆弱性 0。
- 全ツリー秘密情報（実キー形式）・本番/外部実 API 文字列（`ark-conditioning.com` / `64ssq_ark_db` / Peak Manager / SALON BOARD / Hot Pepper）：**なし**。
- Phase 4+ 実装痕跡（`class *Ticket|Membership|Stripe|Cashier|Reporting|PaymentIntent`、`use Laravel\Cashier`、`Stripe\Stripe`）：**なし**。`ExternalReservationGateway` は `NullExternalReservationGateway` のまま。
- CI ガード ローカル擬似実行：誤検知なし。
- localhost：`/` `/login` → 200 ／ `/reserve` `/mypage/reservations` `/admin/reservations` `/admin/schedule` → 302（未認証）。
- **エンドツーエンド確認**：デモデータで `ReservationService::create`（顧客#4・サービス#1・スタッフ#2・シフト内 11:00-12:00）→ `#1 status=confirmed slots=4`。
  `CustomerReservationListQuery`（顧客ビュー）に #1 が **YES**、`ScheduleQuery`（管理台帳）に #1 が **YES**。
  シフト外の日付（翌週）で作成 → `ValidationException「指定時間はスタッフの勤務時間外です」`（整合検証が機能）。
- **MySQL 実接続で `reservation_resource_slots` の UNIQUE が競合防止として機能**（Task 3-8 の Concurrency/Guard テストで確認）。

---

## ✅ Phase 3 完了 — 2026-09-08

### 実装（Task 3-1〜3-9）
reservations / reservation_resource_slots / Reservation State Machine（PLAN §7 完全 map・Phase 3 実運用は confirmed→terminal）/
SlotKey（占有スロット計算の唯一の集約点）/ AvailabilityService（サーバー側空き枠）/
ReservationService（create/reschedule/cancel/complete/no_show・短い transaction・楽観ロック・UNIQUE→409）/
顧客予約ウィザード（決済なし）/ 管理予約 CRUD + 自作の軽量予約台帳（実 DB 連動）/
slot housekeeping prune（日次）/ concurrency・boundary・authorization・CSRF テスト（MySQL）。

### 最終数値
- migration：Phase 3 で +2（`reservations`、`reservation_resource_slots`）／累計 16。
- テスト：**232 passed / 1324 assertions**（Phase 2 完了時 119 → +113）。
- build：型エラー 0。audit：脆弱性 0。

### 残課題（Phase 4 以降・非ブロッカー）
1. お名前.com サーバー実測・共有 vs VPS 判断（Phase 0 残・`OPEN_QUESTIONS` #1-3）。
2. CI は git remote 未接続のため実走なし（ローカル擬似実行で確認）。
3. Phase 3 顧客フローは `requires_staff=true` サービスのみ（`requires_staff=false` は管理側のみ）。指名なしは先頭割当（負荷分散なし）。
4. 予約台帳の D&D は未実装（時間変更は編集フォーム）。顧客詳細サイドパネル・会計フローは Phase 8。
5. 仮予約（pending_payment / payment_expires_at）・失効ジョブは Phase 5（Stripe 単発決済）。
6. キャンセル期限・料金は業務ルール未確定（`OPEN_QUESTIONS` #12）。Phase 3 は confirmed かつ未来ならキャンセル可。
7. `reservations` の `pending_payment` / `pending_external_sync` / `expired` は enum 上の定義のみ（Phase 5/10 用プレースホルダ）。

### Phase 4 開始条件
ローカル予約機能が完成し、`migrate:fresh --seed` / build / 232 テスト / concurrency（MySQL）すべて green。
Phase 4（回数券：ticket_products / ticket_wallets / ticket_transactions（追記型・dedupe_key）/ FEFO / HOLD/RELEASE/CONSUME/EXPIRE）に着手可能。
**ユーザーの明示許可を待つ。**
