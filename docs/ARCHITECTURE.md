# ARCHITECTURE

## 1. 位置づけ

ARK Conditioning の会員予約・決済システム。既存 WordPress サイト（`ark-conditioning.com`）とは
**別リポジトリ・別 DB・別ドメイン**の独立 Laravel アプリ。WordPress 本体には手を入れない。

- ドメイン想定： `member.ark-conditioning.com`（顧客）＋ `/admin`（店舗管理）
- WordPress との接点：当面はリンクのみ（DB 共有・PHP 共有なし）

## 2. スタックと方針

- Laravel 13（PHP 8.3+）/ Inertia.js / Vue 3 + TypeScript / Vuetify 3
- **SPA + API 完全分離はしない**。Inertia で 1 アプリ。
- **モジュラーモノリス**。マイクロサービス化しない。
- 顧客側＝スマホファースト。管理側＝PC・タブレットファーストの高機能 UI。
- 予約台帳など業務固有 UI は Vue 専用コンポーネントを自作（Vuetify カレンダーに依存しない）。

### モジュール（`app/Modules/*`）

| モジュール | 責務 |
|---|---|
| `Reservation` | 予約の CRUD（**自作 DB への書き込みの唯一の入口 = `ReservationService`**）、予約台帳、空き枠のローカル算出、State Machine、仮予約失効 |
| `Customer` | 顧客・認証プロフィール |
| `Ticket` | 回数券（wallet / 追記型 transaction / FEFO） |
| `Membership` | 利用権（`membership_plans` / `memberships` / `membership_usage_transactions`） |
| `Payment` | Stripe 課金・返金・Webhook（Cashier は課金契約のみ） |
| `Reporting` | 集計（Phase 11。初期は空の器） |
| `ExternalIntegration` | 外部予約サービスとの**通信のみ**（`ExternalReservationGateway`）、同期ジョブ、`sync_logs` |

補助：`app/Support`（`StateMachine` / `Money` / `SlotKey` / `Retention`）。
モジュールごとの composer package 化・ServiceProvider 乱立はしない（1 人で追える範囲）。

## 3. System of Record（SoR）切替

`config/reservation.php` の `authority`（env `RESERVATION_AUTHORITY`）:

| 値 | 予約の正本 | 顧客へ「予約完了」を出すタイミング | 外部通信 |
|---|---|---|---|
| `local`（既定） | 自作 DB | 自作 DB へ commit した時点 | 任意（best-effort。失敗しても予約有効） |
| `peak_manager` | Peak Manager | **外部予約登録が成功した後のみ** | 必須 |
| `salon_board` | SALON BOARD | **外部予約登録が成功した後のみ** | 必須 |

- `authority != local` で外部登録前は `reservations.status = pending_external_sync`。
  `pushReservation()` 成功で `confirmed`、失敗で `sync_failed`（顧客は再試行、枠・HOLD 解放）。
- `gateway`（env `EXTERNAL_RESERVATION_GATEWAY` = `null` / `peak_manager` / `salon_board`）は `authority` と独立。
  `authority=local` でも `gateway=peak_manager` の片方向ミラーが可能。

## 4. 外部予約ゲートウェイ

```php
interface ExternalReservationGateway
{
    public function capabilities(): GatewayCapabilities;
    public function fetchAvailability(CarbonInterface $from, CarbonInterface $to, AvailabilityQuery $q): AvailabilityResult;
    public function pushReservation(ReservationSnapshot $r): ExternalRef;
    public function updateExternalReservation(string $externalId, ReservationSnapshot $r): ExternalRef;
    public function cancelExternalReservation(string $externalId, ?string $reason): void;
    public function pullReservations(CarbonInterface $from, CarbonInterface $to): iterable;
}
```

- `NullExternalReservationGateway`（既定）：**DB CRUD をしない**。「外部システムなし」を表す。
  - `capabilities()` → すべて false
  - `fetchAvailability()` / `pushReservation()` / `updateExternalReservation()` / `cancelExternalReservation()` / `pullReservations()`
    → **no-op 成功にしない。`UnsupportedOperationException` で fail-fast**
  - `ReservationService` は `capabilities()` が false のとき Gateway を呼ばない（テストで保証）。
    空き枠は `staff_shifts` + 既存予約からローカル算出する。
- `PeakManagerReservationGateway` / `SalonBoardReservationGateway`：Phase 10 で実装。
- push は `PushReservationJob`（retry + backoff、冪等）。pull は `PullReservationsJob`（スケジュール、`external_reservation_id` UNIQUE で二重取込防止）。
- 契約テスト：`Null` と「記録用フェイク」の両方が同一契約テストを通過。

## 5. Stripe（課金のみ）

- `laravel/cashier` は **課金契約の管理専用**。「月何回使えるか」は `Membership` が持つ（混同しない）。
- 初期は必ず Test Mode。`APP_ENV in (local, testing)` で Live キー（`sk_live_` / `pk_live_`）検出 → 起動時例外。
- 自作 DB に保存するのは **ID と要約のみ**。カード情報・レスポンス全体は保存しない。カード入力は Payment Element（PAN 非通過＝SAQ A 想定）。
- Idempotency-Key を **create / capture / cancel / refund の操作ごとに安定生成**
  （`pi-create:{payment_operation_id}` / `pi-capture:{payment_operation_id}` / `pi-cancel:{payment_operation_id}` / `refund:{refund_operation_id}`。
  operation ID は DB 永続の UUID。**retry 回数・理由文字列を key に含めない**）。
- Webhook：`POST /stripe/webhook`、署名検証必須、`webhook_events.stripe_event_id` UNIQUE で冪等化。**payload は DB に保存しない**。
- Webhook 復旧（詳細は OPERATIONS.md）：
  - A. `stripe:replay {event_id}`（Stripe が event を保持する約 30 日以内）
  - B. Stripe オブジェクト（PaymentIntent / Invoice / Refund / Subscription）から `*:reconcile`（**無期限・長期の本命**）
  - C.（任意）raw payload を暗号化して **DB 外**へ 30〜90 日保管（`STRIPE_ARCHIVE_WEBHOOK_PAYLOAD`）

## 6. データ整合性設計（要点）

- **トランザクション境界の原則**：自 DB 内の 1 原子操作（対象行 `FOR UPDATE` + 台帳追記 + slot 確保 + キャッシュ更新）は
  **短い DB transaction 1 つ**で完結。**外部 HTTP（Stripe / Gateway）中に transaction を開いたままにしない**。
  外部を跨ぐ整合性は **State Machine + Saga/Compensation + Idempotency**。
- **二重予約の DB レベル保証**：`reservation_resource_slots(resource_type, resource_id, slot_start)` **UNIQUE**。
  予約 0 件から同時 2 リクエストでも、後発の slot INSERT が一意制約違反で rollback（アプリのチェック漏れに依存しない）。
- **仮予約**：単発決済は `status=pending_payment` + `payment_expires_at`（既定 10 分）で枠 HOLD。
  `ExpirePendingReservationsJob`（毎分）が期限切れを `expired` にし、slot・回数券/利用権 HOLD を同一 transaction で解放。
- **回数券 / 利用権**：追記型台帳。`available = SUM(delta)`（RESERVE 系は既に負なので**二重減算しない**）、
  `held = 未解消 RESERVE 本数`、`total = available + held`。
  すべての追記は `dedupe_key` UNIQUE で冪等（同一予約の RESERVE/RELEASE/CONSUME、同一期の GRANT が retry で重複しない）。
- **決済と外部登録の補償 Saga**（`authority != local`）：
  1. `capture_method=manual` で authorize のみ → `payment_status=authorized`（顧客表示「予約確保中」）
  2. `pushReservation()`
  3. 成功 → capture → `payment_status=paid` → `confirmed` → **capture 後に**「予約完了」＋「決済完了」
  4. 外部登録失敗 → authorization cancel → `payment_status=voided` → `expired`
  - capture 済みで後段失敗 → **自動返金** → `refunded` → `expired`
  - 各ステップ・補償ステップは Idempotency-Key で冪等（**二重返金・二重取消・二重 capture 防止**）
  - 途中失敗は握りつぶさず `failed_jobs` + 管理画面「要対応」

## 7. 認証・権限（簡素化）

- **単一 `web` guard**。顧客・スタッフを guard で分けない。`spatie/laravel-permission` の role（customer / staff / manager / admin）+ Policy。
- 顧客：セルフ登録・メール確認・パスワードリセット・ログイン rate limit。
- スタッフ：管理者/マネージャーが作成（セルフ登録なし）。
- 管理者保護：**TOTP MFA 必須**、`/admin` の短い idle timeout（例 30 分）、`/admin/*` は deny-by-default、
  機微操作（返金・回数券/利用権の付与/取消/調整/期限変更・契約解約）は manager 以上 + 理由必須 + パスワード再入力 + 監査。

## 8. セキュリティ

- SQLi：Eloquent / Query Builder のみ、バインド必須。
- XSS：Vue 既定エスケープ。ユーザー入力への `v-html` 禁止。CSP。
- CSRF：Laravel + Inertia XSRF-TOKEN。
- パスワード：argon2id。
- Stripe Webhook：署名検証必須、冪等化。
- Rate limit：login / signup / reset / 予約作成 / webhook(IP)。
- 監査：管理操作・認証・金銭操作を `audit_logs` に**要約 1 行**（before/after の JSON スナップショットは持たない）。
- 個人情報：電話・生年月日は必要に応じ `encrypted` cast。**検索が要る項目は正規化値のキー付き HMAC を lookup 専用カラムに併置**して等価検索（平文の検索用コピーは保存しない）。
  HMAC キーには `APP_KEY` ではなく独立した `PII_LOOKUP_KEY` を使用し、ローテーション時は全対象行の再計算を必要とする。
- 秘密情報：`.env` は Git 禁止。環境ごとに別キー。
- DB 権限：アプリ用ユーザは最小権限（本番で DROP 不可）。migration は CI/デプロイの特権ユーザ。
- 環境隔離：開発は本番 WP DB・本番 Stripe に接続しない。

## 9. 監視（段階的）

- Phase 1：`failed_jobs` を管理画面で確認、ログの見方を OPERATIONS.md に明記。
- Phase 5〜8：決済失敗 / Webhook 失敗 / 同期失敗 / 仮予約滞留のリスト + 再実行、`db_size_snapshots` 日次 + 閾値通知、バックアップ通知。
- Phase 8：`Admin/SystemStatus`（Stripe / Reservation Authority / Queue / 仮予約滞留 / 同期失敗 / 最終バックアップ / DB 使用量）。
- **自動復旧と人間判断を分ける**（OPERATIONS.md の切り分け表）。
