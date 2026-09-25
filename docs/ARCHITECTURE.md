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
| `Reporting` | 日次・月次・年間の共通read model（`DailyReportService` / `MonthlyReportService` / `AnnualReportService`）。Excelも同じSummaryを再利用する |
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

### 4.1 Phase 9 外部予約連携基盤（`app/Domain/Integration/*`）

Phase 9 で Provider 非依存の連携基盤を実装。Domain（`ReservationService`）に `if provider == x` を持ち込まない。
実 API（Peak Manager / SALON BOARD）は未確定のため **skeleton のみ**（capability 0・全操作 throw・推測実装なし）。

- **Contract**：`ReservationProvider`（`key` / `capabilities` / `healthCheck` / `fetchReservations` / `createReservation` / `updateReservation` / `cancelReservation`）。`AbstractReservationProvider` が capability を先行検査。
- **Resolver**：`config/reservation_integration.php` の `active_provider`（既定 `null` = fail-safe。`RESERVATION_INTEGRATION_PROVIDER` で切替）。`ProviderRegistry`（key→class・unknown は fail-closed）+ `ProviderResolver`。`IntegrationServiceProvider::boot()` が起動時に config を検証（unknown key / production で mock / `RESERVATION_AUTHORITY != local` は起動拒否。**Phase 9 は authority=local のみ**）。
- **Inbound（External → ARK）**：`ProcessExternalReservationJob`（`ShouldBeUnique`）→ `InboundReservationSync`。`(provider, external_id)` の advisory lock（`GET_LOCK`）で直列化 → Normalize（`ExternalReservationData`）→ Validate → Mapping lookup → Dedupe（fingerprint / `external_updated_at` / `rawVersion`）→ NO_OP / CREATE / UPDATE / CANCEL / CONFLICT。反映は必ず `ReservationService` 経由（slot UNIQUE・version lock・台帳を迂回しない）。自動反映は confirmed の時刻/担当変更と canceled のみ。順序比較材料が無い Provider は 2 回目以降の異なる snapshot を自動反映しない。両側変更は conflict（silent overwrite しない）。
- **Outbound（ARK → External）— Outbox パターン**：`ReservationService::create/reschedule/cancel` が**同一 DB transaction** で `reservation_sync_outbox` に 1 行 enqueue（`idempotency_key = rsv-out:{op}:{reservation_id}:{seq}`）。`DispatchReservationOutboxJob` → `OutboxDispatcher`：`claimNext`（`FOR UPDATE SKIP LOCKED` + lease 回収 + 同一予約の先行行を待つ sequence 直列化）→ `process`（**外部 HTTP は transaction 外**）。retryable/ambiguous は backoff で pending、permanent は needs_attention、非 active provider は terminal 化せず park。成功時に mapping.fingerprint（共通 baseline）を原子更新。
- **因果ループ防止**：`IntegrationContext`（`applyingInbound` 中は outbox recording を抑止）+ `source=EXTERNAL` ガード（多層防御）。
- **Reconcile**：`reservations:reconcile-providers`（read-mostly・`chunkById`・safe self-heal と needs_attention を分離・大量上書きしない・external-only 検出・差分で非 zero exit）。
- **Conflict**：`reservation_sync_conflicts`（fingerprint のみ・PII snapshot なし・`external_ref_hash` で dedup・並行 open は 23000 catch で収束）。手動解決は Phase 10。
- **Admin**：`GET /admin/integrations/reservations`（`can:integrations.view` = admin + manager）+ `POST .../outbox/{id}/retry`（`can:integrations.manage` = admin ＋ `password.confirm` ＋ audit）。raw payload / credential / PII 全文は返さない。外部 ID は表示上 mask。
- **PII / secret**：credential は `.env` のみ（git / DB / Vue props / フロント JS / log / exception / audit / test fixture に置かない）。`ExternalReservationData` は氏名・連絡先を保持しない。log 禁止：email / 電話 / 住所 / notes / raw provider payload / credential / token。sync event / outbox payload に自由記述を載せない。

### 4.2 勤務枠・予約受付管理（#11）

「毎日シフトを 1 件ずつ登録する」運用をやめ、**基本シフト（曜日テンプレート）→ 自動生成**へ。日常操作は「基本シフトを設定 / 例外日だけ変更 / 予約開放ルールを設定」の 3 つ。

- **基本シフト**：`staff_shift_templates`（曜日 × 時間帯、同一曜日複数可）。`SaveShiftTemplates` が 1 スタッフぶんをまるごと置換。過去の実績枠は書き換えない。
- **例外日**：`staff_shift_exceptions`（`is_off` = 休み / 時間変更）。例外日がある (staff, date) は自動生成の対象外。`is_off` は生成済み `origin=template` 枠のみ削除し、手動枠・予約には触れない。
- **予約受付**：`BookingWindow`（`App\Domain\Reservation`）が予約可能期間と締切の窓口。休業日/特別営業時間の正本は `store_calendar_days`、読取窓口は `StoreCalendarService`。旧 `booking.closed_dates` はmigration時に一度取り込む。
  - 設定キーが未設定なら **完全に無制限**（既存環境の後方互換）。`SettingsSeeder` には含めず、管理画面「勤務枠 › 予約受付」で保存した時点から有効。
  - **horizon / lead は顧客予約のみ**に適用（`ReservationService::validateReservationDetails` で `!$adminContext` のとき）。管理者の手動予約は従来どおり期間制限を受けない。
  - **`closed_dates` は誰でも不可**（管理者手動・D&D を含む物理的に不可能な予約として拒否）。
- **自動生成**：`GenerateShiftsFromTemplates` Action + `shifts:generate` command（Scheduler：毎朝 06:00・`withoutOverlapping`）。冪等（同一 `(staff, date, start, end)` を二重に作らない）／店舗休業日は生成しない／例外日・手動枠のある日は触らない／**追加のみ（削除しない）**＝未来予約を巻き込まない。monthly は開放日を迎えた朝に翌月ぶんが増える。管理画面から「今すぐ反映」も可。
- **AvailabilityService は再実装しない**。顧客の空き枠算出（`openStartTimes`）は従来どおり `staff_shifts` + `reservation_resource_slots` を使う。

### 4.3 予約台帳のドラッグ&ドロップ時間変更（§13-17）

- カードを左右ドラッグ → 10 分（`reservation.slot_minutes`）スナップ → **確認ダイアログ**（変更前/後を表示）→「変更する」で初めてサーバーへ。キャンセル/Esc/バックドロップで元位置へ完全復帰。
- 対象は **未来の `confirmed` のみ**（`completed` / `canceled` / `no_show` / `expired` はドラッグ不可）。**時間のみ**移動（スタッフ・ブースは現状維持）。
- サーバーは専用の雑な UPDATE を作らず **`ReservationService::reschedule` を再利用**（`PUT /admin/schedule/reservations/{reservation}/time`・`can:reservations.manage`）。重複・勤務時間・営業時間・休業日・楽観ロック（`expectedVersion`）を再検証し、競合は 409 / 勤務外等は 422。監査ログは既存の `reservation.rescheduled`。

### 4.4 予約台帳の顧客・予約詳細パネル

Peak Manager の業務導線（カード→顧客情報→来店履歴→その日の台帳）を、少ないクリックで完結させる。

- **集約 API**：`GET /admin/reservations/{reservation}/panel`（`ReservationPanelQuery`）。予約カード 1 クリックに必要な
  「顧客 / この予約 / 回数券 / 月額プラン / 今後の予約 / 来店履歴」を 1 レスポンスで返す。既存 Query
  （`CustomerReservationListQuery` / `CustomerTicketQuery` / `AdminMembershipQuery`）を再利用し N+1 を出さない。
  来店履歴は初期 20 件（`has_more` フラグ・超過は Customer 360 へ）。
- **認可**：ルートは `can:reservations.view`。顧客 PII（電話・メール・生年月日・顧客メモ・履歴）は
  `can:customers.view` を持つ場合のみ payload に含める（サーバー側で除外）。予約操作の可否フラグは `can:reservations.manage`。
- **状態は URL**：`/admin/schedule?date=&view=&axis=&staff_id=&reservation=<id>`。`ScheduleController::index` が
  `reservation` を検証し `focus_reservation_id` として渡す。パネル開閉・カード切替は Inertia `router.get`
  （`preserveState` / `preserveScroll`）で、戻る/進む/リロードが自然に動く。
- **来店履歴 → 台帳ジャンプ**：履歴行クリックで `date` と `reservation` を差し替えて遷移 → 対象カードへ
  `scrollLeft` で自動スクロール＋ CSS animation で数秒の一時ハイライト（`reservation-card--flash`）。
- **クリック / D&D の分離**：`DRAG_THRESHOLD_PX = 6`。pointer 移動がしきい値以下なら click（詳細パネル）、
  超えたら drag（時間変更）。drag 直後の click は `suppressNextClick` で無視。
- **予約操作**：パネルの「来店完了 / キャンセル / 無断キャンセル / 予約を編集 / 決済確認」は既存の
  `AdminReservationController` のルート・`ReservationService` をそのまま呼ぶ（業務ルールを二重実装しない）。
  キャンセル系は v-dialog の確認を挟む。
- Customer 360（`/admin/customers/{id}`）の置き換えではない。詳細編集・全決済・回数券/月額操作は Customer 360 に残す。
- **メモの区別**：顧客メモ = `customers.note`、予約備考 = `reservations.notes`。両者を統合しない。
  「管理メモ」に相当する独立カラムは現状無いため、パネルには出さない（`cancel_reason` はキャンセル理由として別掲）。

### 4.5 予約台帳の店舗オペレーション UX 拡張（Peak Manager 参考・ARK 独自実装）

4.3/4.4 の上に、台帳を「店舗の中央オペレーション画面」として完成させる拡張。既存の
`ReservationService` / `AvailabilityService` / `AuditLogger` / 権限は再利用のみで、業務ルールの
二重実装は行わない。

- **サイドパネルの共用化**：台帳専用の左パネル（グローバルナビとは別）が「顧客詳細（`ReservationDetailPanel`）／
  顧客検索（`CustomerSearchPanel`）／新規予約（`NewReservationPanel`）」の 3 モードを共用。`PanelHeader` が
  🔍（検索）／＋（新規予約）／×（閉じる・Esc 可）を出す。パネルは `board-layout` 内の左カラムとして展開し、
  **台帳本体を押し出さない**（本体側が縮んで内部スクロールする）。状態は `panel` / `customer` / `reservation`
  クエリで URL 化。
- **顧客のみのパネル**（特定の予約に紐づかない）：`GET /admin/customers/{customer}/board-panel`
  （`ReservationPanelQuery::getForCustomer()`）。顧客検索の選択結果や空き枠クリック時に使う。
  `date` を渡すと、表示中の日にその顧客の予約があれば `today_reservation_id` を返し、台帳側でカードをハイライトする。
- **会員番号**：専用カラムは存在しないため、顧客（`user_id`）をゼロ詰め（`sprintf('%06d', $id)`）した値を
  「会員番号」として表示・検索対象にする（`ReservationPanelQuery::customerPayload()` /
  `CustomerLookupQuery::search()`）。スコープ上の代替であることを明示。
- **顧客検索（台帳版）**：`CustomerLookupQuery` に会員番号（数字のみの入力を顧客 ID とみなす）検索を追加。
  電話番号検索は既存の HMAC 一致のまま変更なし。ルートの権限（`can:reservations.manage`）は既存テストを
  保護するため変更していない＝台帳の顧客検索は `reservations.manage` を持つ管理者のみ利用可能。
- **空き枠クリック→新規予約**：時間軸の空きセルクリックで `NewReservationPanel` を新規予約モードで開き、
  日付・時刻・担当スタッフ/ブースを事前入力する。フォーム自体は既存 `POST /admin/reservations` を叩くのみで、
  `Admin/Reservations/Create.vue` と業務ロジックは共有（バリデーション・競合検知の二重実装なし）。
- **スタッフ名クリック→勤務枠**：レーンラベルの担当者名クリックで `/admin/staff-shifts?staff_id=` に遷移し、
  対象スタッフを選択済みにする。
- **D&D の拡張（時間／担当スタッフ／ブース／日付）**：`updateReservationTime()` が `staff_id` / `booth_id` を
  任意パラメータとして受け付け、未指定なら現状維持。値の有無は `Request::has()` で判定し（`null` 明示との区別）、
  最終的に同じ `ReservationService::reschedule()` を呼ぶだけ＝サーバー側の再検証（重複・勤務時間・メニュー対応・
  ブース・休業日・楽観ロック）は 4.3 の実装をそのまま再利用する。日付変更は前日/今日/翌日ボタン上へのドロップで
  検知し、時刻はそのまま日付だけを差し替える。**確認ダイアログは変更前/後の日付・時刻・担当を必ず提示**し、
  ここでも「変更する」を押すまでサーバーに送らない。ドラッグの「動いた」判定は水平移動・垂直移動（レーン変更）・
  日付ドロップのいずれかで真になる（水平方向のみだと同一時刻でのスタッフ変更ドラッグが検知できないため）。
- **クリック/ドラッグの分離**：4.4 の `DRAG_THRESHOLD_PX` パターンを継承し、`moved` フラグで判定する。
- **オンライン予約通知**：`ScheduleNotificationQuery` が「店舗スタッフの手入力（`ReservationSource::Admin`）以外」の
  直近予約を「その管理者が未読のもの」だけ返す（`GET /admin/schedule/notifications`、20 秒ポーリング）。
  既読は `reservation_notification_dismissals`（`user_id` + `reservation_id` で一意）に保存し、
  **管理者ごとに永続**（`localStorage` 依存ではない）。ブロードキャスト基盤（Reverb/Pusher 等）は未設定
  （`BROADCAST_CONNECTION=log`）のため、真のリアルタイム push ではなくポーリングで近似している。
- **当日サマリー**：予約件数・キャンセル・無断キャンセルは予定情報である`reservations`から、来店完了・初診・
  リピーター・売上はPhase 11の`DailyReportService`から取得する。売上は`checkout_tenders.received_at`を
  `Asia/Tokyo`営業日へ変換した決済日基準で、`sales.view`保有者にだけ返す。スタッフ絞り込みには関わらず
  店舗全体を対象にし、予約価格や現在のメニュー価格から実績売上を推測しない。
- **軸「両方」**：`ScheduleQuery::get(axis: 'both')` がスタッフとブースの両方を返し、フロントは 1 つの
  縦積みレーン一覧としてレンダリングする（スタッフ行の後にブース行、ブース区間の先頭行に「ブース」の
  区切りバッジを表示）。1 件の予約がスタッフ行・ブース行の両方に現れ得るため、**D&D と「満席」帯表示は
  `axis='both'` では無効化**している（対象リソース種別が曖昧になるため。時間変更・スタッフ変更を行いたい
  場合はスタッフ軸／ブース軸に切り替える）。

### 4.6 予約台帳の最終UX仕上げ＋予定ブロック機能

4.5 の上に、会員番号の正式DB化・指名の正式管理・左パネルの共通シェル化・D&Dの安定化・
予約以外の時間占有（予定ブロック）を追加した最終仕上げ。

- **会員番号（`customers.member_no`）**：`ARK` + `user_id` を6桁ゼロ詰め（例：`ARK000164`）にした
  実カラム。DB内部ID（`user_id`）とは別の正式な業務項目とし、`Customer` モデルの `creating` フックで
  一度だけ発番・以後変更しない。`user_id` 自体が既に一意な AUTO_INCREMENT のため、追加の採番テーブル
  なしに同時登録でも安全。既存顧客は migration で一括 backfill。顧客検索・パネル表示はこの実カラムを
  そのまま使う（以前の「顧客IDをゼロ詰めして表示するだけ」の代替実装は廃止）。
- **指名（`reservations.is_staff_requested`）**：担当スタッフの割当が「顧客の明示的な指名」か
  「システム/店舗による自動割当」かを区別する正式なフラグ。新規予約時・予約編集時にチェックボックスで
  指定でき、`ReservationInput` / `RescheduleInput` に引数を追加して `ReservationService::create()` /
  `reschedule()` がそのまま永続化する（業務ルールの二重実装なし）。スケジュール変更を伴わずフラグだけを
  変更する場合は軽量な `UpdateReservationNomination` Action（`UpdateReservationNotes` と同じ薄いパターン）
  を使う。D&D（時間・スタッフ・日付変更）はこのフラグに触れず現状を維持する。スタッフ割当が外れた場合は
  フラグも自動的に false になる。顧客向けの新規予約フローはこのコードベースに未実装のため、Web予約からの
  指名連携は将来の課題として残る（§残課題）。
- **台帳サイドパネルの共通シェル（`PanelShell.vue`）**：顧客詳細／顧客検索／新規予約／予定作成／予定詳細の
  5モードすべてが `PanelShell.vue`（ヘッダー＋本文スクロール＋任意フッター）を土台にする。幅は親
  （`.board-layout__panel`、約400px）で統一済みだが、内部の padding・gap・フッターの位置もこのシェルで
  揃える。区切り線が端まで届く一覧を持つコンポーネント（顧客詳細パネル）は `body-padding="false"` で
  本文の余白を自前管理する。
- **D&Dの安定化**：ドラッグ開始判定を「水平移動量」だけでなく「垂直移動でレーンが変わったか」「日付ドロップ
  ターゲットへのホバー」のいずれかで真になるよう修正（水平移動が無い、同一時刻でのスタッフ間ドラッグを
  取りこぼさないため）。ドラッグ中は元カードを半透明にし、顧客名・時刻を表示する追従ゴーストカードを
  別途描画する。ドロップ先レーンは「既存の予約・予定と時間帯が重ならなそうか」をクライアント側で簡易
  チェックし azure/red の色分けヒントを出すが、**最終判定は必ずサーバー側**（`ReservationService::reschedule`
  の完全な再検証）。
- **軸「両方」の見出し**：スタッフ行とブース行の間にあった小さな区切りバッジを、横幅いっぱいの
  「👥 スタッフ」「▣ ブース」見出し行に変更。レーンラベル列・トラック列の両方が同じ `displayRows`
  （見出し行 or レーン行のリスト）を辿るため、2列の行数・高さは常に一致する。
- **予定ブロック（`staff_schedule_blocks`）**：予約以外（休憩・ミーティング・事務作業・清掃・研修・外出・
  その他）でスタッフ／ブースの時間を予約不可にする独立した概念。`Reservation` を顧客なしで無理やり
  流用しない（DB_SCHEMA参照）。`ScheduleBlockService`（`create`/`update`/`delete`）が勤務時間内チェック・
  予約との重複チェック・他ブロックとの重複チェックを行い、`AvailabilityService::openStartTimes()` は
  同日ぶんのブロックを1クエリでまとめて取得し、時間帯が重なる候補開始時刻を除外する。
  `ReservationService::validateReservationDetails()` にも同じ重複チェックを追加し、台帳外（既存の
  `POST /admin/reservations` 等）からの新規予約もブロックとの重複を確実に拒否する。
  空きセルクリックは「予約を入れる／予定を入れる」の小さな選択を経由し、予定ブロックの作成・編集・削除
  ・D&D（時間・担当・日付変更、確認ダイアログあり）も台帳サイドパネルとタイムラインで完結する。
  作成・変更・削除は既存 `AuditLogger` に記録し、顧客向けのメール・SMS・Stripe・回数券・月額プランは
  一切動かさない。

### 4.7 予約台帳 完成（週表示・任意日D&D・リアルタイム通知・draft保持）

4.6 までの残課題（左パネルUX・MenuPicker）を全て解消した最終ラウンド。

- **新規予約⇄予定追加の入力保持**：`resources/js/composables/reservationDraft.ts` に
  `ReservationDraft`/`BlockDraft` という素の TypeScript 型と、prefill を「新しい情報がある項目だけ」
  上書きする純粋関数（`applyReservationPrefill`/`applyBlockPrefill`）を切り出した。
  `Schedule/Index.vue` がこの draft オブジェクトを `reactive()` で1つだけ持ち続け、
  `NewReservationPanel.vue`/`ScheduleBlockCreatePanel.vue` へそのまま渡す（同じオブジェクト参照なので
  子側の入力がそのまま親の draft に反映される）。クリアするのは作成成功時・予定削除時のみ。
  Vue から独立した純粋関数にしたことで Vitest で直接ユニットテストできる。
- **週表示の実用台帳化**：`ScheduleQuery::get()` の `staff_shifts` 取得を「日表示の1日だけ」から
  「表示範囲（週表示なら7日）ぶんをまとめて1クエリ」に拡張し、各行へ `work_date` を追加（N+1にはならない、
  既存どおり1クエリ）。フロントは `Schedule/Index.vue` に「スタッフ×日付」のCSS Gridを新設し、
  各セルを営業時間を100%とした帯グラフ（`weekBarRect()`）として、予約・予定ブロック・勤務外（斜線）・
  当日ハイライト・予約件数を表示する。クリック処理（予約詳細を開く・空きセルから新規予約/予定追加）は
  日表示と全く同じ `openReservationPanel`/`openSlotChoicePanel`/`openBlockDetailPanel` を再利用し、
  業務ロジックの二重実装はしていない。
- **任意日付へのD&D**：`PendingMove`/`PendingBlockMove` の `dateOffsetDays`（±1日固定）を
  `targetDate`（ISO日付、自由に書き換え可能）に置き換え、確認ダイアログに `ArkCalendar` を使った
  「日付を変更」ポップオーバーを追加。ドラッグ中の前日/今日/翌日ボタンへのドロップは従来どおり ±1日の
  ショートカットとして残しつつ、確認ダイアログ側で任意の日付へ最終調整できる。サーバー側の検証は
  既存の `ReservationService::reschedule()` / `ScheduleBlockService::update()` をそのまま再利用（勤務枠・
  休業日・受付ルール・重複・ブース・メニュー対応可否・所要時間・権限・楽観ロックを毎回フル再検証）ので
  バックエンドの変更は不要だった。失敗時は既存のD&Dロールバック機構（§4.6の延長、トースト表示＋即時
  元位置復帰）がそのまま効く。
- **MenuPicker「よく使う」**：`ReservationFormOptionsQuery::popularServiceIds()` が
  `reservations` テーブルの `starts_at`（既存インデックス）で直近30日に絞り、`status IN
  (confirmed, completed)` のみを対象に `service_id` で GROUP BY・1クエリで集計する
  （キャンセル・無断キャンセル等は除外。N+1にはならない設計）。`ScheduleController` は
  `ReservationFormOptionsQuery::get()` を1回だけ呼び出して `menu_options`/`booth_options`/
  `popular_service_ids` を同時に取り出す（以前は同じクエリを2回呼んでいた無駄も合わせて解消）。
  スタッフ個人・顧客個人ごとの集計は行わない（店舗全体の実績のみ、過剰な複雑化を避ける）。
- **オンライン予約通知のリアルタイム化（Laravel Reverb）**：`composer require laravel/reverb` +
  `php artisan reverb:install` で導入。`App\Events\OnlineReservationCreated`（`ShouldBroadcastNow`。
  このアプリの `QUEUE_CONNECTION=database` はワーカー常駐前提ではないため、キューを介さず即時配信する）を
  `ReservationService::create()` のトランザクションコミット後に必ず発行し、`broadcastWhen()` で
  「オンライン予約経路（ARK Web/外部連携）かどうか」を判定する（管理画面からの手入力は配信しない）。
  購読は `private-schedule-notifications` チャンネル1本（`routes/channels.php` で
  `reservations.view` を検証）。認可ロジックは `App\Broadcasting\ScheduleNotificationChannel::authorize()`
  という薄いクラスに切り出し、HTTP/ブロードキャスト経路を介さず直接ユニットテストできるようにしている
  （**注意**：`Broadcast::channel()` へ `[Class::class, 'method']` の配列コールバックを直接渡すと、
  Laravel の `Broadcaster::extractParameters()` が `ReflectionFunction` でパラメータ名を読もうとして
  `TypeError` になる。必ずクロージャ経由で呼ぶこと——実機検証で見つかった実際の落とし穴）。
  フロントは `resources/js/echo.ts` で Reverb（pusher-js プロトコル）に遅延接続し、
  `ScheduleNotifications.vue` が push受信時は即座に、接続断・push未確立時は20秒間隔の
  フォールバックpollingで同じ「未読オンライン予約」一覧を取得する（push接続中はpollingを120秒間隔まで
  落とすだけで完全には止めない＝取りこぼし対策）。同一予約IDの通知はpush/polling どちらから届いても
  `id` でデデュープしてUI上は1件のみ表示する。Reverbサーバーは `php artisan reverb:start`
  （`compose.yaml` に `8080` ポートのマッピングを追加済み）。
- **フロントエンド自動テスト基盤**：Vitest + @vue/test-utils を追加（`vitest.config.ts`、
  `resources/js/test-setup.ts` で Vuetify・jsdom未実装API のポリフィルを用意）。`npm run test` /
  `npm run test:unit` で実行。対象は `reservationDraft.ts`・`panelHistory.ts`・
  `reservationStatusLabel()`（`design/tokens.ts`）・`MenuPicker.vue`・`PanelShell.vue`・
  `PanelCustomerSearchBar.vue`。Vuetify の `v-dialog` は `document.body` へ teleport されるため、
  `wrapper.find()` ではなく `document.body` を直接検索する必要がある点に注意（`MenuPicker.spec.ts` 参照）。
  `Schedule/Index.vue` 本体（4000行超）はコンポーネントテストで丸ごと検証するには大きすぎるため、
  戻る履歴・draft・ステータス変換など再利用しやすい部分を純粋関数として切り出してテストする方針を採った。

### 4.5 Phase 11 日次・月次Reporting

- `DailyReportQuery::fetchRange()`が指定期間を集計粒度別の8本のSQLで取得する。単日APIも月計も同じSQL定義を使い、月計が日次APIを最大31回呼ぶ構造にはしない。
- `DailyReportService`は単日`forDate()`と期間`forRange()`を持ち、いずれも`DailyBusinessSummary`へ変換する。来店、ロング、次回予約snapshot、初診、分類、決済日／施術日売上、支払方法、税snapshotの定義はここで一元化する。
- `MonthlyReportService`は月の全暦日を`MonthlyBusinessSummary`へ構成し、店舗カレンダー、月間売上目標、`as_of_date`を重ねる。月率は日別率平均ではなく、月の分子合計÷分母合計で算出する。
- 平日は月〜金、土日は土・日とし、祝日専用判定は行わない。臨時休業日は営業日数・平均の分母・残営業日から除外する一方、その日に保存済みの事実は月合計と曜日別の分子から失わない。
- `as_of_date`は現在月=JST今日、過去月=月末、未来月=月初前日。実績進捗は同日まで、残営業日は翌日以降とする。未来日はUI上「未実績」で、平均分母へ含めない。
- 管理画面/APIは`reports.view`と`sales.view`を両方要求する。`GET /admin/reports/monthly`が画面、`GET /admin/reports/monthly/data`が年月・売上基準切替用JSONである。

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

## 認証 / MFA レイヤ（Phase 5.5）

```
App\Domain\Auth\MfaPolicy          … MFA 要件判定の唯一の入口（middleware / UI / テストが共有）
App\Domain\Auth\SmsOtpService      … OTP 発行・検証（平文を保存もログ出力もしない）
App\Domain\Auth\Sms\SmsSender      … SMS 送信の抽象（provider 固有コードを外へ出さない）
  ├ LogSmsSender                     … local / staging（実送信しない）
  └ FakeSmsSender                    … testing
App\Http\Middleware\EnsureStaffMfa       … MFA 未設定なら設定画面へ誘導
App\Http\Middleware\PreventStaffTotpDisable … 業務ロールの TOTP 無効化を拒否（自己ロックアウト対策）
```

- **Phase 9.6**: Passkey / WebAuthn は撤去。認証は「メール・パスワード（または Google）
  → 主認証成功 → 業務ロールのみ 6 桁 TOTP チャレンジ」。
- Google ログインは `laravel/socialite`（stateful）。`App\Http\Controllers\Auth\GoogleAuthController`
  が redirect / callback / 既存アカウント連携 / 解除を担う。identity は `user_social_accounts`
  （`UNIQUE(provider, provider_user_id)`）。**token は保存しない。特権ロールは callback から
  自動作成・自動昇格・silent link しない。**
- 再認証は `password.confirm`。機微操作のルート定義は変更不要。
- `two_factor_confirmed_at` を各所で直接判定しない。必ず `MfaPolicy` を通す
  （`isSatisfiedBy` = 確認済み TOTP）。
