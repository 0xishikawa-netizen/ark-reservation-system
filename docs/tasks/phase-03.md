# Phase 3 — ローカル予約機能（中核）

設計正本：`docs/PLAN.md`（rev.5）。baseline：**Phase 2 baseline commit（作成後にハッシュを記入）**。
本ファイルは Phase 3 のタスク分割と進行ログ。

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
