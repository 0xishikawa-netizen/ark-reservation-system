# Phase 9 — 外部予約連携基盤（Provider 非依存 Integration Foundation）

設計正本: `docs/PLAN.md` §3 / §8 / §7、`docs/ARCHITECTURE.md`。本書は Phase 9 のタスク計画 + 実行ログ。
差異が生じたら **PLAN 優先**。安全性上 PLAN を上書きする項目は §「PLAN からの逸脱」に明記する。

## baseline

- 開始 baseline commit: `ebd6dcd`「Polish per-screen UX」。
- Phase 9 の `git diff` レビュー基準は `ebd6dcd`。全 Task green まで commit しない。
- Phase 0〜8.5 実装済み。Visual QA は後回し（Phase 9 のブロッカーにしない）。

## Purpose

ARK 独自システムと外部予約サービス（将来: Peak Manager / SALON BOARD / Hot Pepper Beauty 経由）を、
**安全・冪等・再試行可能・競合検知可能** な形で接続する Provider 非依存の Integration Foundation を完成させる。
Phase 10 で実 API 仕様が確定したら `PeakManagerReservationProvider` / `SalonBoardReservationProvider` を
差し込むだけで、既存の予約・回数券・決済・Membership ロジックを壊さず外部連携を開始できる状態にする。

## Non Goals（Phase 9 でやらない）

- Peak Manager / SALON BOARD / Hot Pepper Beauty の **実 API 実装・推測実装**（Phase 10）。
- 実 credential / 実 endpoint / 実 JSON shape / OAuth 方式の想定。
- multi-store / `store_id` / LINE / loyalty / analytics platform / recommendation。
- Conflict の UI からの解決フロー（read-only status + `needs_attention` まで。理由は §Conflict Detection）。
- production 接続 / Stripe Live / remote 追加 / push。

## Source of Truth

- **ARK DB が業務上の Source of Truth。** 外部 Provider に ARK の予約状態を直接支配させない。
- 外部から取得した予約は必ず Integration Boundary（Normalize → Validation → Dedupe → Conflict Check → 反映）を通す。
- 反映は必ず既存 `ReservationService`（create / reschedule / cancel / markCompleted / markNoShow）経由。
  外部 import でも既存 business invariant（slot UNIQUE / version 楽観ロック / entitlement）を迂回しない。

## Integration Boundary

```
[Inbound]  Provider.fetchReservations(window)
  → Normalize（ExternalReservationData）
  → Validation（必須項目 / status mapping）
  → Mapping lookup（provider + external_reservation_id）
  → Dedupe（fingerprint / external_updated_at）
  → 決定: NO-OP / CREATE / UPDATE / CANCEL / CONFLICT
  → 既存 ReservationService
  → mapping 更新 + sync event 記録

[Outbound] ReservationService.create/reschedule/cancel
  → [同一 DB transaction] outbox 行を作成（stable idempotency key）
  → commit
  → Queue: DispatchReservationOutboxJob
  → [transaction 外] Provider.create/update/cancel
  → mapping 更新 + sync event 記録
```

## Provider Contract（Task 9-1）

`App\Domain\Integration\Contracts\ReservationProvider`

- `key(): string`
- `capabilities(): ProviderCapabilities`
- `healthCheck(): ProviderHealth`
- `fetchReservations(FetchWindow $window): iterable<ExternalReservationData>`
- `createReservation(OutboundReservationCommand $cmd): ProviderRef`
- `updateReservation(string $externalId, OutboundReservationCommand $cmd): ProviderRef`
- `cancelReservation(string $externalId, ?string $reason): void`

抽象基底 `AbstractReservationProvider` が **capability を先に検査**し、未対応なら
`UnsupportedProviderOperationException` を投げてから `doXxx()` へ委譲する。
raw provider exception を frontend / audit / log へ流さない（`IntegrationException` へ写す）。

## Provider Capability（Task 9-1）

`App\Domain\Integration\Enum\ProviderCapability`（enum）:
`ReadReservations` / `CreateReservations` / `UpdateReservations` / `CancelReservations` /
`ReadAvailability` / `ReadCustomers` / `Webhook` / `Polling`

`ProviderCapabilities`（VO）= `ProviderCapability` の集合。`has()` / `assert()` / `toArray()`。

## Provider Resolver / Configuration（Task 9-2）

- `config/reservation_integration.php`: `active_provider`（env `RESERVATION_INTEGRATION_PROVIDER`、
  既定 `mock`）、`providers`（key => class map）、`inbound`（enabled / window_minutes / poll_cron）、
  `outbox`（max_attempts / backoff_seconds / batch）、`reconcile`（cron / window_days）。
- `ProviderRegistry`（key → class）、`ProviderResolver`（`resolve(?key)` / `active()`）。
  unknown key は `ProviderConfigurationException` で **fail-closed**（silent fallback しない）。
- `IntegrationServiceProvider` が Resolver と各 Provider を bind。
- Phase 9 実動は **Mock のみ**。`peak_manager` / `salon_board` は safe skeleton（未設定で安全停止）。

## Normalized DTO（Task 9-3）

`App\Domain\Integration\Dto\ExternalReservationData`（readonly）:
`provider` / `externalReservationId` / `externalCustomerId?` / `startsAt`(CarbonImmutable) /
`endsAt`(CarbonImmutable) / `externalStatus`(生文字列) / `serviceRef?` / `staffRef?` /
`customerName?` / `customerContactMasked?` / `externalUpdatedAt?` / `rawVersion?` /
`isCanceled`(bool) / `fingerprint()`（provider を含まない正規化ハッシュ）。

- raw payload を DB へ丸ごと保存しない。PII は必要最小限。contact は保存前に mask。
- 外部 status → ARK `ReservationStatus` は `ExternalStatusMapper` で明示 mapping。
  未知 status は正常扱いせず `null`（→ CONFLICT `UNSUPPORTED_STATE`）。

## External Mapping（Task 9-4）

`reservation_provider_mappings`:

| 列 | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| provider | varchar(32) | |
| reservation_id | FK reservations cascadeOnDelete | |
| external_reservation_id | varchar(191) | |
| external_customer_id | varchar(191) null | |
| fingerprint | varchar(64) null | 最後に反映した外部 fingerprint |
| external_updated_at | datetime null | provider が返した更新時刻 |
| last_synced_at | datetime null | 最後に反映した時刻 |
| last_seen_at | datetime null | 最後に fetch で観測した時刻 |
| sync_status | varchar(16) | in_sync / drift / conflict / stale |
| timestamps | | |

UNIQUE `(provider, external_reservation_id)` / UNIQUE `(provider, reservation_id)`（1 provider = 1 mapping）。
index `(reservation_id)` / `(provider, sync_status)`。
同一 external が同時 import されても UNIQUE で **reservation 1 / mapping 1 に収束**。
UNIQUE violation を 500 で終わらせず既存 mapping へ収束。

## Sync Event / Operation History（Task 9-5）

`reservation_sync_events`（append-only・`updated_at` なし・Model で updating/deleting を拒否）:
`provider` / `direction`(inbound|outbound) / `operation` / `reservation_id`(null nullOnDelete) /
`external_reservation_id_masked`(null) / `correlation_id` / `idempotency_key`(null) /
`status`(started|succeeded|no_op|skipped|failed|conflict) / `attempt` / `error_category`(null) /
`safe_error_code`(null) / `started_at` / `completed_at`(null) / `created_at`。

**禁止**: credential / access token / raw payload / full PII / secret / card 情報。
外部 ID は最後 4 桁のみ残す mask。index `(provider, created_at)` / `(reservation_id)` / `(status)`。

## Integration Outbox（Task 9-6）

`reservation_sync_outbox`:
`id` / `provider` / `reservation_id` FK cascade / `operation`(create|update|cancel) /
`idempotency_key` varchar(120) **UNIQUE** / `payload_json`（最小正規化 snapshot・PII なし） /
`status`(pending|processing|succeeded|failed|needs_attention|skipped) / `attempts` /
`available_at` datetime / `locked_at` datetime null / `locked_by` varchar(64) null /
`last_error_category` null / `last_error_code` null / `correlation_id` / `completed_at` null / timestamps。

- ARK 予約更新 → **同一 transaction で outbox 作成** → commit → Queue → Provider → mapping → event。
- idempotency key = `rsv-out:{operation}:{reservation_id}:{op_seq}`（op_seq = その予約の outbox 連番）。
  retry で外部 create が二重にならない。mapping が既にあれば create→update へ切替。
- 取り出しは `UPDATE ... SET status=processing, locked_at, locked_by WHERE id=? AND status IN (pending) AND available_at<=now`
  の atomic claim（並列 worker が二重送信しにくい）。
- permanent failure → `needs_attention`。transient → `attempts++` / `available_at = now + backoff(attempts)` /
  `max_attempts` 超で `needs_attention`。
- index `(status, available_at)` / `(provider)` / `(reservation_id)`。

## Inbound Sync（Task 9-8）

`InboundReservationSync::apply(ExternalReservationData $data): InboundOutcome`
- Provider が `ReadReservations` capability を持たなければ呼ばれない。
- mapping なし + 既存 ARK 予約に該当なし → CREATE（`ReservationService::create` / source=EXTERNAL / actor=システム）。
- mapping あり + 外部 fingerprint 一致 → NO-OP（`last_seen_at` のみ更新）。
- mapping あり + 外部 `external_updated_at` が保存済みより古い → **stale**（巻き戻さない・skipped）。
- mapping あり + 外部が cancel かつ ARK が active → CANCEL（`ReservationService::cancel`）。
- mapping あり + 時刻/担当が変化 & ARK も baseline から変化 → CONFLICT（overwrite しない）。
- mapping あり + 外部のみ変化 → UPDATE（`ReservationService::reschedule`）。
- 未知 external status → CONFLICT `UNSUPPORTED_STATE`。
- 同時 import は mapping UNIQUE + `firstOrCreateForExternal` の 23000 catch で収束。

## Outbound Sync（Task 9-7）

`DispatchReservationOutboxJob`（1 行 = 1 論理操作・冪等・retry safe）
- Provider が対応 capability を持たなければ outbox 行を `skipped`。
- Retryable: timeout / temporary network / 429 相当 / 5xx 相当。
- Non-Retryable: unsupported / invalid normalized request / mapping impossible / configuration error。
- 応答喪失（ambiguous）→ 確定失敗にしない。同一 idempotency key で retry、または reconcile で収束。

## Fingerprint / Stale Update 防止（Task 9-9）

- ARK 側 fingerprint = sha256(starts_at|ends_at|status|service_id|staff_id)。
- 外部側 fingerprint = `ExternalReservationData::fingerprint()`（starts/ends/status/refs の正規化）。
- 順序保証なし前提。`external_updated_at` があればそれで新旧判定。無ければ fingerprint 差分のみで判定し、
  **古いデータで ARK を巻き戻さない**（version / last_seen state を併用）。仕様の無い Provider に架空 version を仮定しない。

## Conflict Detection（Task 9-10）

`reservation_sync_conflicts`:
`provider` / `reservation_id` FK cascade / `external_reservation_id_masked` null /
`conflict_type` / `ark_fingerprint` null / `external_fingerprint` null / `detected_at` /
`status`(open|resolved|ignored) / `resolution` null / `resolved_at` null / `resolved_by` FK users nullOnDelete / timestamps。

Conflict Type: `TIME_CHANGED_BOTH` / `STATUS_CHANGED_BOTH` / `CANCEL_VS_UPDATE` /
`MISSING_MAPPING` / `DUPLICATE_EXTERNAL` / `UNSUPPORTED_STATE`。

- conflict 時は ARK 予約も外部も **silent overwrite しない**。`needs_attention` として Admin から確認可能。
- PII snapshot 丸保存禁止（fingerprint のみ）。
- **Phase 9 では UI からの解決は実装しない**（read-only status + `needs_attention`）。理由:
  Conflict 解決は業務データ変更のため、実 Provider の write 仕様が確定してから設計する方が安全。
  暫定は `open` 一覧表示 + 手動 `retry`（outbox）のみ。

## Reconciliation（Task 9-11）

`reservations:reconcile-providers`（read-mostly / 非 zero exit で運用監視）
- 検出: ARK only / External only / Mapped but missing / Status mismatch / Time mismatch /
  Canceled mismatch / Fingerprint mismatch / Duplicate mapping / Stale mapping。
- Safe self-heal（`last_seen_at` 更新 / `sync_status` の派生更新のみ）と Needs attention（conflict 行作成）を明確に分離。
- 大量予約を勝手に上書きしない。chunk 処理。

## Queue / Retry / Backoff（Task 9-12）

- `PollExternalReservationsJob`（inbound・provider ごと・`withoutOverlapping`・unique）。
- `ProcessExternalReservationJob`（1 external = 1 job）。
- `DispatchReservationOutboxJob`（1 outbox 行 = 1 job・atomic claim）。
- `ReconcileReservationProviderJob`。
- `tries` / `backoff`（指数）/ `timeout` / `WithoutOverlapping` / `failed()` で `needs_attention` 化。
- 巨大単一 Job で全予約処理し 1 件失敗で全 retry、は禁止。

## Scheduler（Task 9-14）

- `reservations:poll-external` — `config('reservation_integration.inbound.poll_cron')`（既定 15 分）・`withoutOverlapping`。
- `reservations:dispatch-outbox` — 毎分・`withoutOverlapping`。
- `reservations:reconcile-providers` — `config(... reconcile.cron)`（既定 05:00）・`withoutOverlapping`。
- Provider 実仕様が無いため rate limit / 推奨間隔を推測しない（config 化のみ）。
- Scheduler 停止時も Admin から最終 sync 時刻を確認できる（`reservation_provider_sync_state`）。

## Admin Integration Status（Task 9-13）

`GET /admin/integrations/reservations`（permission `integrations.view`）
- Provider（Mock / Peak Manager / SALON BOARD）ごとに Status（正常 / 要確認 / 停止 / 未設定）。
- Metrics: 最終 Inbound / Outbound / Reconcile / Pending Outbox / Failed / Conflict / Needs Attention。
- 最近の安全な Sync History（masked）。
- **禁止**: raw payload / credential / access token / customer PII 全文 / provider secret。
- `POST /admin/integrations/reservations/outbox/{outbox}/retry`（permission `integrations.manage` +
  `password.confirm` + audit）— failed / needs_attention の outbox を再投入。

## Authorization

- 新 permission: `integrations.view`（admin + manager）、`integrations.manage`（admin）。
- Customer は Integration 管理へアクセス不可。Staff は閲覧不可（`integrations.view` を持たない）。
- IDOR 対策: outbox / conflict は route model binding + provider スコープ検証。

## Audit

- **人間操作のみ** audit（manual retry / 将来の conflict resolution / mapping repair）。
- 自動 Job の Telemetry は `reservation_sync_events` に閉じ、`audit_logs` へ大量投入しない。

## Security / PII

- Provider credential は `.env` / secure config のみ。git / DB 平文 / Vue props / frontend JS / log /
  exception / audit / test fixture へ入れない。Phase 9 では実 credential 不要。
- 外部 ID は URL へ不要露出しない・log では mask・`provider + external_reservation_id` 単位で扱う
  （`external_reservation_id` 単独を global unique にしない）。
- log 禁止: email / phone / address / notes / raw provider payload。
  correlation は internal reservation id / masked external id / correlation UUID。

## Failure Matrix（Task 9-10 / §29 の 42 項目）

`tests/Feature/Integration/*` と `tests/Feature/Integration/ReservationIntegrationConcurrencyTest`（MySQL 2 コネクション）で網羅。

## Phase 10 Extension Points

- `ReservationProvider` を実装した `PeakManagerReservationProvider` / `SalonBoardReservationProvider` を
  `config/reservation_integration.php` の `providers` に登録し `active_provider` を切替えるだけ。
- `AbstractReservationProvider::doFetch/doCreate/doUpdate/doCancel` を実装。`capabilities()` を実 API に合わせる。
- `ExternalStatusMapper` に provider 別の status 対応表を追加（Domain へは漏らさない）。
- Webhook 受信が必要なら Phase 5/6 の単一 webhook 入口に倣って `IntegrationWebhookController` を足す
  （Phase 9 では polling のみ）。

---

# Task 一覧

| Task | 内容 |
|---|---|
| 9-1 | Provider Contract / Capability / 例外階層 |
| 9-2 | Provider Resolver / Registry / config |
| 9-3 | Normalized DTO / ExternalStatusMapper / Fingerprint |
| 9-4 | Mapping / Sync Event / Outbox / Conflict / sync_state migration + Model + Enum |
| 9-5 | Outbound: OutboxWriter（ReservationService 連携）+ DispatchReservationOutboxJob |
| 9-6 | Inbound: InboundReservationSync + Poll/Process Job |
| 9-7 | Concurrency / Idempotency（mapping・outbox・inbound の収束） |
| 9-8 | Conflict Detection |
| 9-9 | Reconciliation command + Job |
| 9-10 | Mock Provider（scenario 注入）+ Failure Matrix テスト |
| 9-11 | Queue / Scheduler 登録 |
| 9-12 | Admin Integration Status 画面 + retry |
| 9-13 | Authorization / Audit / Security テスト |
| 9-14 | E2E / 非退行 |
| 9-15 | Codex Red Team Review + Sonnet 再判定 + 修正 |

# PLAN からの逸脱

- PLAN §8 の `ExternalReservationGateway`（Phase 1 scaffold）は残置し、Phase 9 の
  `ReservationProvider` を新設して Integration Layer を厚くする（Gateway は availability の薄い口として温存可）。
  理由: Phase 1 interface は snapshot 中心で、冪等・outbox・conflict・reconcile を表現しきれない。
- Conflict の UI 解決は Phase 9 では非実装（上記 §Conflict Detection の理由）。

# 実行ログ

（以下、Task ごとに追記）

## 実行ログ

### 2026-09-10 — Phase 9 実装（Sonnet 5 一括実装。Codex は最終 Red Team のみ）

- **Task 9-1 Contract/Capability**: `App\Domain\Integration\Contracts\ReservationProvider` +
  `AbstractReservationProvider`（capability 先行検査）+ `ProviderCapability` enum（8 種）+ `ProviderCapabilities` VO。
  例外階層 `IntegrationException`（category(): ErrorCategory）→ Unsupported / Config / Transient(retryable) /
  Permanent(non-retryable) / Ambiguous(応答喪失) / InvalidExternalReservation。
- **Task 9-2 Resolver**: `config/reservation_integration.php`（active_provider 既定 mock・env で null 可）+
  `ProviderRegistry`（key→class・unknown は fail-closed）+ `ProviderResolver`（activeKey / hasActive / resolve）。
  `IntegrationServiceProvider` を `bootstrap/providers.php` に登録。phpunit.xml で
  `RESERVATION_INTEGRATION_PROVIDER=null`（テストは opt-in）。
- **Task 9-3 DTO**: `ExternalReservationData`（provider を含まない fingerprint()・PII 最小・contact は mask 済み）/
  `OutboundReservationCommand` / `ProviderRef`（maskedId 末尾4桁）/ `FetchWindow` / `ProviderHealth` / `InboundDecision`。
  `ExternalStatusMapper`（共通語彙のみ写像・未知は null → CONFLICT UNSUPPORTED_STATE。provider 別表は Phase 10）。
  `ReservationFingerprint::forReservation`（starts/ends/canceled/service/staff）。
- **Task 9-4 Schema**（migration +5）:
  `reservation_provider_mappings`（UNIQUE(provider,external_reservation_id) / UNIQUE(provider,reservation_id)）/
  `reservation_sync_outbox`（idempotency_key UNIQUE・payload_json は PII なし・status/attempts/available_at/locked_*）/
  `reservation_sync_events`（追記専用・updating/deleting 拒否・外部 ID は mask のみ）/
  `reservation_sync_conflicts`（fingerprint のみ・PII snapshot なし）/ `reservation_provider_sync_state`（provider UNIQUE）。
- **Task 9-5/9-7 Outbound**: `ReservationOutboxRecorder`（`ReservationService::create/reschedule/cancel` の
  同一 transaction で 1 行 enqueue・有効 provider / capability が無ければ no-op・source=EXTERNAL は折返し送信しない）。
  `OutboxDispatcher`（`claimNext` = SELECT ... FOR UPDATE + status ガードの atomic claim / `process` = HTTP は
  transaction 外・retryable/ambiguous は backoff で pending・permanent/unsupported は needs_attention/skipped・
  mapping ありなら create→update 切替）。`DispatchReservationOutboxJob`（ShouldBeUnique + WithoutOverlapping）。
- **Task 9-6/9-8/9-9 Inbound**: `InboundReservationSync`（Normalize→Validate→Mapping→Dedupe→
  NO-OP/CREATE/UPDATE/CANCEL/CONFLICT。反映は必ず `ReservationService` 経由。`external_updated_at` があれば stale 判定・
  無ければ fingerprint のみ・古いデータで巻き戻さない。両側変化は CONFLICT・silent overwrite しない）。
  `ConflictRecorder`（同一 (provider,reservation,type) の open は detected_at 更新のみ・増殖させない）。
  `SyncEventRecorder`（PII/secret/raw payload を残さない・外部 ID は mask）。
- **Task 9-11 Reconcile**: `ProviderReconciler`（read-mostly・chunkById・safe self-heal（last_seen/sync_status）と
  needs_attention（conflict 行）を分離・大量上書きしない）+ `reservations:reconcile-providers`（差分で非 zero exit）。
- **Task 9-12 Jobs/Scheduler**: `PollExternalReservationsJob`（1 external = 1 `ProcessExternalReservationJob`）/
  `DispatchReservationOutboxJob` / commands `reservations:{poll-external,dispatch-outbox,reconcile-providers}` /
  scheduler（dispatch-outbox 毎分・poll は poll_cron・reconcile は reconcile.cron・すべて withoutOverlapping）。
- **Task 9-13 Admin**: `GET /admin/integrations/reservations`（`can:integrations.view`）+
  `POST .../outbox/{reservationSyncOutbox}/retry`（`can:integrations.manage` + `password.confirm` + audit）。
  `ReservationIntegrationStatusQuery`（provider 別 status/metrics + masked sync history。raw payload / credential /
  PII 全文を返さない）+ Vue `Admin/Integrations/Reservations.vue`（read-only・「正常/要確認/停止/未設定」表示）。
  permission `integrations.view`（admin + manager）/ `integrations.manage`（admin）。
- **Task 9-10 Mock Provider**: `MockReservationProvider`（全 capability）+ `MockReservationStore`（container singleton・
  test がシナリオ注入：fetch 結果 / transient / permanent / ambiguous(応答喪失) / duplicate / 順序逆転）。
  `PeakManagerReservationProvider` / `SalonBoardReservationProvider` は capability 0 の safe skeleton（推測実装なし）。
- **Task 9-14 テスト**: `tests/Unit/Integration/ProviderContractTest`（5）/ `tests/Feature/Integration/`:
  `InboundReservationSyncTest`（12）/ `OutboundOutboxTest`（8）/ `ProviderReconcileTest`（7）/
  `IntegrationAdminSecurityTest`（7）/ `ReservationIntegrationConcurrencyTest`（MySQL 2 コネクション・2）。
  `tests/Support/IntegrationTestHelpers`。既存 `RolePermissionSeederTest` の permission 数を更新。

### 検証（実装直後）
- `migrate:fresh --seed`：46 migration DONE（+5 Phase 9）。
- `npm run build`：vue-tsc 型エラー 0 / vite built。
- `artisan test`：692 passed / 4090 assertions / 0 failed。
- `composer audit` / `npm audit`：脆弱性 0。
- 非退行：Reservation 210 / Ticket / Payment / MFA / Membership / Portal / Admin すべて green。

### 2026-09-10 — Codex Red Team Review（READ-ONLY）+ Sonnet 再判定 + 修正（Task 9-15）

Codex は baseline `ebd6dcd` + 未コミットのワークツリーを静的監査。**20 件**（CRITICAL 3 / HIGH 11 / MEDIUM 5 / LOW 1）+ 設計上の疑問 3 件。
Sonnet 再判定はすべて **TRUE POSITIVE**（誤検知 0）。baseline commit 前に 20 件すべてを修正。大規模 refactor はせず、最小修正 + 対象テストで対応。

| ID | Sev | 判定 | 要点 | 最小修正 |
|----|-----|------|------|----------|
| F-01 | CRITICAL | TP | 並行 inbound import で予約二重作成・敗者が orphan 化 | `(provider, external_id)` の advisory lock（`GET_LOCK`/`RELEASE_LOCK`）で直列化。予約作成+mapping 確定を同一 transaction。`firstOrCreateForExternal` は既存 mapping の `reservation_id` 不一致で `ProviderPermanentException` を投げ、呼び出し側 transaction を rollback。`SlotUnavailableException` → `DUPLICATE_EXTERNAL` conflict。MySQL 2 コネクションで「同一 external 並行 → 予約 1 / mapping 1」を検証。 |
| F-02 | HIGH | TP | `external_updated_at` の無い Provider で遅延した旧 snapshot が ARK を巻き戻す | `ExternalReservationData::hasOrderingSignal()`（`externalUpdatedAt` か `rawVersion` がある）を追加。既存 mapping の 2 回目以降で異なる snapshot かつ ordering signal 無し → `STATUS_CHANGED_BOTH` conflict（自動反映しない）。`rawVersion` を `reservation_provider_mappings.external_version` へ永続化。 |
| F-03 | CRITICAL | TP | create の retry 待ち中に cancel が先行 → 後から外部予約が孤立して残る | `OutboxDispatcher::claimNext` に「同一 `(provider, reservation_id)` の先行未完了行があれば claim しない」`whereNotExists` ガード（sequence 直列化）。 |
| F-04 | HIGH | TP | claim 後 crash で outbox が永久 processing | `claimNext` に `processing AND locked_at < now-900s` の lease 回収を atomic claim へ内包。`ReservationIntegrationStatusQuery` に `stuck_processing`（15 分以上 processing）を追加し `needs_attention` に加算・管理画面表示。 |
| F-05 | HIGH | TP | outbound 成功後に mapping.fingerprint（共通 baseline）が未更新 → 競合判定が無効化/誤検知 | `afterCreate` / `afterMutate` で `ReservationFingerprint::forReservation($reservation->fresh())` を mapping へ原子保存（+ `last_synced_at` / `external_updated_at` / `sync_status=in_sync`）。 |
| F-06 | MEDIUM | TP | inbound で適用した変更が同じ Provider へ outbound 折り返し | `IntegrationContext` singleton（`applyingInbound(Closure)` / `isApplyingInbound()`）を追加。`InboundReservationSync::apply` を `applyingInbound` でラップ。`ReservationOutboxRecorder` は inbound 適用中は enqueue しない（永続 `source` に依存しない）。`source=EXTERNAL` ガードは多層防御として残す。 |
| F-07 | HIGH | TP | completed/no_show/未知 status が fingerprint から消え confirmed/no-op に化ける | `ReservationFingerprint` に canonical status（`ExternalStatusMapper` 通過後）を含める。`InboundReservationSync` は status 検証を no-op 判定より前に実施。`confirmed` の時刻/担当変更 と `canceled` のみ自動反映、それ以外（completed/no_show 等）は `STATUS_CHANGED_BOTH` conflict。 |
| F-08 | HIGH | TP | 外部 duration/service/staff 差を反映せず mapping を in_sync にする | 反映後に `finalizeMapping` で ARK fingerprint（`->fresh()`）と外部正規化 fingerprint の一致を検証。不一致は `sync_status=drift` + conflict。`ExternalReservationData` から `endsAt` を fingerprint 対象外化（ARK が service duration から再計算するため差異を conflict で拾う）。 |
| F-09 | HIGH | TP | 応答喪失の external-only 予約を reconcile が検出しない（`external_only=0` 固定） | `ProviderReconciler` に mapping 未消費の external ID 走査を追加。同一 customer/service/starts_at の mapping 無し ARK 予約があれば「probable orphan」→ `DUPLICATE_EXTERNAL` conflict（`external_ref_hash` 付き）。report の `external_only` を実カウント。 |
| F-10 | HIGH | TP | poll 途中失敗で既処理分が再投入・業務エラーが毎回 5 回 retry で storm | `ProcessExternalReservationJob` を `ShouldBeUnique`（`uniqueId` = provider/externalId/snapshot の hash・`uniqueFor=900`）化。業務判定は `apply()` 内で conflict/skipped に収束（例外を投げない）。`failed()` で `SyncEventStatus::Failed` を記録。 |
| F-11 | HIGH | TP | 手動 retry がグローバル `claimNext()` を呼び無関係な行を processing 固定 | `OutboxDispatcher::claimById(int $id, string)`（`WHERE id=? AND status=pending` の条件付き claim）を追加。controller は対象 ID の 1 行だけを claim → process。 |
| F-12 | HIGH | TP | active provider 切替時に旧 Provider の backlog を terminal skipped で破棄 | `process()` は行の `provider` key で resolver を解決。未解決 / 非 active は terminal 化せず `park()`（`status=pending` へ戻し `available_at +5min`・`last_error_category=config_error`）。config 復帰で再開可能。 |
| F-13 | CRITICAL | TP | authority 設定と新 Provider 設定が分離・外部正本でも即 confirmed | `IntegrationServiceProvider::boot()` で `active_provider` 有効時に `RESERVATION_AUTHORITY !== 'local'` なら `ProviderConfigurationException` で起動拒否（Phase 9 は authority=local のみ）。`.env.example` に `RESERVATION_INTEGRATION_PROVIDER=null` を明記。`active_provider` の既定を `mock` → `null`（fail-safe）へ変更。 |
| F-14 | HIGH | TP | normalized DTO の氏名/連絡先 と自由記述 cancel reason が queue/outbox に平文残存 | `ExternalReservationData` から `customerName` / `customerContactMasked` を削除（identity は `externalCustomerId` + `refHash` のみ）。`ReservationOutboxRecorder` の `payload_json` から `cancel_reason` を除去。outbound cancel の `cancelReason` は定型値 `'canceled'` 固定（自由記述は audit_logs で追う）。 |
| F-15 | MEDIUM | TP | create→update 切替後も `command.operation` が create のまま | `$effective` 決定後に command を構築（`command($row, $reservation, $effective)`）。 |
| F-16 | MEDIUM | TP | 末尾 4 文字 mask だけで conflict を同一視・別予約を混同 | `reservation_sync_conflicts.external_ref_hash`（`sha256(provider|externalId)`）を追加 + index。`ConflictRecorder::open` は reservation_id / external_ref_hash / masked のいずれかで dedup、23000 catch で収束。表示は従来どおり mask。 |
| F-17 | MEDIUM | TP | no-op event と reconcile snapshot が無制限増加 | fingerprint 一致時は event を出さず `last_seen_at` だけ更新。`config/retention.php` に `prune.reservation_sync`（events 60d / outbox 30d / conflicts 180d）。`reservations:prune-sync-logs`（succeeded/no_op/skipped event・完了 outbox・resolved/ignored conflict を保持日数で削除）+ scheduler `dailyAt('02:50')`。reconcile の fetch は 5000 件上限。 |
| F-18 | HIGH | TP | Provider 設定異常が「予約全体の 500」または「正常表示で無送信」 | `boot()` の起動時 config validation（unknown key / mock in production / authority 不整合で異常終了）。`ReservationIntegrationStatusQuery` は active+unconfigured / 必須 capability 不足を「未設定」「要確認」に分類。 |
| F-19 | MEDIUM | TP | 管理画面に retry 対象一覧・操作 UI が無い | `ReservationIntegrationStatusQuery::actionableOutbox()`（failed / needs_attention の masked 一覧・上限 20）。`Reservations.vue` に「要対応の送信キュー」テーブル + `password.confirm` 経由の「再送」ボタン（`can:integrations.manage`）。`auth.can.integrationsManage` を Inertia 共有。 |
| F-20 | LOW | TP（security/consistency 関連のため baseline 前に修正） | `claimNext` に SKIP LOCKED が無く並列起動で先頭行待ち | MySQL は `->lock('for update skip locked')`、他ドライバは `lockForUpdate()`。 |

設計上の疑問への回答（`docs/OPEN_QUESTIONS.md` にも反映）:
- **Q-01**: Phase 10 adapter が「必ず ARK の内部 ID へ解決してから DTO を作る」ことを Provider 契約として明文化する。Phase 9 の Mock は数値 ID をそのまま渡すが、`resolveCustomerId` 等は存在検証を行い、解決不能は `MISSING_MAPPING` conflict。→ OPEN_QUESTIONS の Peak Manager / SALON BOARD 質問表に「external ID ↔ ARK ID の対応表提供有無」を追加。
- **Q-02**: inbound は現状すべて `EXTERNAL`。provider の復元は `reservation_provider_mappings.provider` が正本（`source` では復元しない）。台帳/一覧の source 色分けに `EXTERNAL` / `SALON_BOARD` を追加済み。Provider 固有 enum 値は Phase 10 で「どの channel 由来か」を UI 表示するために温存。
- **Q-03**: `OutboundReservationCommand.idempotencyKey`（`rsv-out:{op}:{reservation_id}:{seq}`）は安定だが、「同一 key の再 create を同一外部予約へ収束させる」「key で既存外部予約を検索できる」ことは **Phase 10 の Provider 契約で必須化する**（OPEN_QUESTIONS に追記）。Phase 9 の Mock はこの収束を実装済み（ambiguous 応答喪失テストで検証）。

### 検証（Red Team 修正後・最終）
- `./vendor/bin/sail pint`：passed。
- `migrate:fresh --seed`：46 migration DONE（+6 Phase 9。`000006` で `external_ref_hash` / `external_version` 追加）。
- `npm run build`：vue-tsc 型エラー 0 / vite built（819ms）。
- `artisan test`：**695 passed / 4101 assertions / 0 failed**（Phase 9 追加テスト: Inbound 15 / Outbound 9 / Reconcile 7 / AdminSecurity 7 / Contract 5 / Concurrency 2）。
- `composer audit` / `npm audit`：脆弱性 0。
- 静的スキャン：`sk_live_`/`pk_live_` 平文なし・Integration domain に `Log::`/`logger()` なし・実 API endpoint URL なし・`DB::transaction` は claim / DB-only reservation+mapping のみ（外部 HTTP は transaction 外）・skeleton provider は capability 0 + 全 `doXxx` throw（Phase 10 先行実装なし）。

### Phase 9 判定

**Phase 9 AUTOMATED: GREEN**（automated fake / mock provider 前提）。

以下は Phase 9 の完了とは独立で、引き続き未完了:
- **REAL STRIPE TEST MODE QA = INCOMPLETE**（`.env` は placeholder のまま。人手 QA 未実施）。
- **Membership Production Readiness = NOT READY**。
- **SALON BOARD & Peak Manager Real Integration = BLOCKED / OFFICIAL SPEC WAITING**（skeleton のみ。実 API 仕様・credential・契約待ち。推測実装しない）。
