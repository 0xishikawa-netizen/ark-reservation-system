# 全体コードレビュー報告（2026-10-07）

- 対象：`main`（HEAD `73c0532`）＋未コミット差分（予約台帳の日次集計拡充・マスタ画面UI統一）
- 規模：PHP 485 ファイル / 約 4.2 万行、Vue・TS 194 ファイル / 約 3.6 万行、migration 79 本、テスト 206 ファイル
- 検証結果：`sail artisan test` **1191 件すべて成功**（約 25 分）、`sail npm run build` 成功（型エラー 0）

## 1. レビュー方法と範囲

約 7.8 万行を全行精読するのは現実的でないため、**リスクが高い順に重点精読**し、残りは構造・規約の横断チェックとした。

| 区分 | 対象 | 方法 |
|---|---|---|
| 精読 | `ReservationService` / `PaymentService` / `StripeApiGateway` / `StripeWebhookProcessor` / `StripeWebhookController` / `CheckoutService`（確定・取消・回数券付与） / `CheckoutEntryService` / `TicketFefoSelector` / `MembershipReservationService`（予約時） / `GuestReservationTokenService` / `BookingConfirmationController` / `CustomerController` / `CustomerPolicy` / `routes/web.php` / `routes/console.php` / `RolePermissionSeeder` / `ScheduleQuery`（日次集計） | 1 行ずつ読み、入力・状態・並行実行で壊れる条件を検討 |
| 横断 | 全ルートの認可（`can:` / FormRequest / Policy）、`declare(strict_types=1)` の有無、フロントの `any` / `v-html`、巨大ファイル、セキュリティヘッダ、Rate limit | grep と構造確認 |
| 未精読 | Reporting 系 Service（`DailyReportQuery` ほか）、外部連携（`app/Domain/Integration`）、MFA・Google ログイン、Excel 出力、過去データ取込、各 Vue 画面の詳細 | 既存テスト（1191 件）と既存ドキュメントの検証記録に依拠 |

## 2. 総評

金銭・予約まわりの**設計の芯は堅牢**。とくに以下はそのまま維持すべき長所。

- Stripe 呼び出しを DB トランザクション外に強制（`assertStripeOutsideTransaction()`）。冪等キーは永続化した operation UUID から導出。
- 曖昧な応答（タイムアウト・5xx・未分類エラー）を `failed` に落とさず「要対応（`needs_attention`）」として保持し、二重返金・二重取消を構造的に防いでいる。
- Webhook は署名検証 → `stripe_event_id` UNIQUE → 「イベント本文で状態を決めず、Stripe の現在状態を取り直して前進のみ」。到着順の逆転に強い。
- 二重予約は `reservation_resource_slots` の UNIQUE 制約で DB レベル保証。楽観ロック（`version`）と行ロックを併用。
- 返金は「保留中も予約額として数える」ことで並行返金の過剰返金を防止。
- ゲスト予約の確認リンクは selector／validator 分離＋ハッシュ保存。`Referrer-Policy: strict-origin-when-cross-origin` で URL 内トークンの外部流出も防止。
- `declare(strict_types=1)` 欠落ファイル 0、ユーザー入力への `v-html` 0。

一方で、**「業務上の境界ケース」に穴がいくつかある**（下記 3 章）。いずれも通常運用では発生頻度が低いが、発生すると金銭・回数の不整合になるため、優先度 High として扱う。

## 3. 指摘事項

重大度：**High**＝金銭・回数・予約の不整合に直結 ／ **Medium**＝運用で誤りや手戻りが起き得る ／ **Low**＝保守性・規約・将来リスク

### High

#### H-1 月額の「解約予定」で期末後の日付でも予約できる
- 場所：`app/Domain/Membership/MembershipReservationService.php:54-60`
- 内容：解約予定（`canceling`）の会員は「期末まで利用可」とする判定で、**期末日を予約日ではなく今日と比較**している。
- 例：期末 10/31 の解約予定会員が、今日（10/7）時点で 11/15 の予約を月額で取れてしまう。期末後に利用権が無いのに予約が残る。
- 補足：回帰テスト `MembershipRedTeamRegressionTest::test_f07_…`（138 行）のコメントは「予約日（2026-09-15）より前＝期末経過」と**予約日比較を意図**しているが、テスト内の予約は日付を指定しておらず、実行日が期末より後なので偶然通っている。
- 対応案：`$reservation->starts_at` の JST 日付と `current_period_end` を比較する。テストの予約日も明示する。

#### H-2 会計を取り消しても、確定時に付与した回数券が残る
- 場所：`app/Domain/Accounting/CheckoutService.php:305-320`（`void`）、付与は同 `:327-354`（`grantPurchasedTickets`）
- 内容：回数券購入の明細がある会計は、確定時に `TicketLedgerService::grant` で回数券を付与する。しかし取消（`void`）は会計の状態を変えるだけで、**付与した回数券を取り消さない**。`docs/CHECKOUT_ENTRY.md` にもこの扱いの記載がない。
- 例：回数券 5 回分を会計 → 入力ミスで取消 → 顧客の残数は 5 のまま。再入力すると 10 回分になる。
- 対応案：取消時に `checkout-line:{line}:{n}` に対応する付与を `REVOKE`（未使用分のみ）する。すでに使用済みの場合は取消を拒否するか「要対応」にする。業務判断が必要なので、先に方針を決めて文書化する。

#### H-3 ゲスト（未ログイン予約）がカード払い済み予約をキャンセルしても自動返金されない
- 場所：`app/Domain/Reservation/ReservationService.php:430-434`、呼び出し元 `app/Http/Controllers/Booking/BookingConfirmationController.php:186-191`（`actor = null`）
- 内容：キャンセル時の自動返金は「操作者が `User` の場合のみ」実行され、操作者なしの場合は `needs_attention` を立てて監査ログを残すだけ。ゲストのキャンセルは常に操作者なしなので、**キャンセル規定上 100% 返金のタイミングでも返金されない**（店舗が手動で返金する必要がある）。
- 返金の `created_by` が NOT NULL であることが原因の設計上の制約。意図した仕様なら、ゲストの確認画面とメールで「返金は店舗から連絡」と明示する必要がある。
- 対応案：①予約顧客（`reservation->customer->user`）を操作者として返金する（会員のマイページと同じ扱い）、または②システム用ユーザーを用意する。①が既存の作りに最も近い。

#### H-4 Stripe ダッシュボードで返金した後にアプリから返金すると、状態と返金額がずれる
- 場所：`app/Domain/Payment/PaymentService.php:446-448`（同期時に Stripe の返金額で `refunded_amount` を更新）と `:616-640`（アプリの返金完了時に `payment_refunds` の合計だけで再計算）
- 内容：返金の正本が 2 つ（Stripe 側の累計と `payment_refunds` の合計）あり、アプリ外で返金されると食い違う。
- 例：5,000 円の決済をダッシュボードで 1,000 円返金 → `charge.refunded` の Webhook で `refunded_amount=1000`、`partially_refunded`。その後アプリで 4,000 円返金 → Stripe 上は全額返金済みだが、`payment_refunds` 合計は 4,000 なので **状態は `partially_refunded` のまま、`refunded_amount` は 1,000→4,000 に「減って」から上書き**される。
- 対応案：完了時の `refunded_amount` は `max(payment_refunds 合計, Stripe 累計)` にするか、Stripe 側累計で状態を決める。運用ルールとして「返金は必ずアプリから」を `OPERATIONS.md` に明記する。

#### H-5 バックアップが未実装（手順書だけがある）
- 場所：`docs/OPERATIONS.md` §1、`composer.json`、`routes/console.php`
- 内容：手順書は「`spatie/laravel-backup` で日次に DB＋`storage/app` をオフサーバーへ（7 日＋4 週）」「デプロイ前に必ず `backup:run`」と定めているが、**パッケージが未導入で、スケジュールにも無い**。`backup:run` は実行できない。設計原則の優先順位 4「バックアップ／復旧」が満たされていない。
- 補足：本番は未構築のため現時点で実害はないが、本番稼働の前提条件。
- 対応案：本番環境の構成（VPS／共有ホスティング）を決めたうえで、パッケージ導入（またはサーバー側の mysqldump＋オフサイト転送）、スケジュール登録、システム状態画面への最終バックアップ表示、**復元訓練**までを 1 Task として承認を取る。

### Medium

#### M-1 月額の予約は「予約日の期」ではなく「今日の期」の回数を使う
- 場所：`app/Domain/Membership/MembershipReservationService.php:62`（`currentPeriod($membership)`）
- 内容：来月の予約でも当月分の利用回数を消費する。当月の回数を使い切っていると、来月分が付与されていても予約できない。
- 業務仕様の確認が必要（「先の月の予約は、その月の回数を使う」が自然）。仕様なら文書化、仕様でなければ予約日から期を決める。

#### M-2 回数券の選び方（FEFO）が予約日を考慮しない
- 場所：`app/Domain/Ticket/TicketFefoSelector.php:13-28`
- 内容：期限が近い回数券から押さえるため、**予約日より前に期限が切れる回数券**を押さえることがある（期限後も使える別の回数券があっても）。`ticket.expiration_hold_policy=preserve_hold`（押さえた分は期限後も有効）で実害は抑えられているが、「期限切れ回数券で施術する」状態になる。
- 対応案：予約日まで有効な回数券を優先し、無い場合だけ現行の FEFO にする。

#### M-3 予定ブロックと予約が同時に作られると重複し得る
- 場所：`app/Domain/Reservation/ReservationService.php:63-79`（検証がトランザクション外）、`app/Domain/Schedule/ScheduleBlockService.php`
- 内容：予約同士は slot の UNIQUE で守られるが、**予定ブロックとの重複はクエリによる事前チェックのみ**で、行ロックも一意制約もない。管理者 2 人が同じ時間に「予約」と「休憩」を同時に入れると両方成立し得る。
- 対応案：同じスタッフ・ブースの作成を `GET_LOCK` などで直列化するか、ブロックも `reservation_resource_slots` 相当の占有行を持たせる。

#### M-4 未来の予約にも「無断キャンセル」を付けられる
- 場所：`app/Domain/Reservation/ReservationService.php:510-531`、`ReservationStateMachine`
- 内容：開始時刻前でも `confirmed → no_show` に遷移でき、ポリシーによっては回数券・月額が消化される。誤操作の防止がない。
- 対応案：`starts_at` 以降のみ許可（または確認ダイアログで強く警告）。

#### M-5 予約台帳「日次集計」の客単価・売上内訳の基準ずれ（今回の未コミット差分）
- 場所：`app/Queries/ScheduleQuery.php:358-359`
- 内容：客単価＝「決済日基準の売上 ÷ 来店日基準の来店数」、施術等・物販は別基準。前払い・後日会計・返金がある日に数字が合わない。詳細は同日のレビュー（ReportFindings）参照。

#### M-6 有効/無効スイッチの二重発火・連打（今回の未コミット差分）
- 場所：`resources/js/Pages/Admin/Products/Index.vue:32`、`resources/js/Pages/Admin/Settings/BusinessMasters.vue` の各スイッチ
- 内容：`@click.stop` で切り替えており、処理中の無効化もない。`@update:model-value` と処理中フラグにする。

### Low

| ID | 場所 | 内容 |
|---|---|---|
| L-1 | `ReservationService.php:347-348` | 操作者が顧客レコードも持つスタッフの場合、管理画面からのキャンセルでも「顧客によるキャンセル」とみなされ、開始後キャンセルが拒否される |
| L-2 | `ReservationService.php:285-291` | 延長で追加するメニューの `is_active` を確認していない |
| L-3 | `ReservationService::markNoShow` / `VisitCompletionService` | 来店完了・無断キャンセルは外部連携の Outbox に記録されない（設計上 create/reschedule/cancel のみ）。外部を正本にする時に要対応 |
| L-4 | `PaymentService.php:802-808`、`CheckoutService.php` の多数の `ValidationException` | 画面に出るエラー文言が直書き。AGENTS.md「サーバーは `lang/ja/messages.php` に追加して参照」に反する |
| L-5 | `resources/js/Pages/Admin/Schedule/Index.vue:1639,1657,1681` | `(el: any)`。AGENTS.md「`any` を避ける」に反する |
| L-6 | `resources/js/Pages/Admin/Schedule/Index.vue`（2,871 行）ほか 1,000 行超 3 本 | 単一ファイルが巨大。分割は進んでいるが、状態管理（パネル・ドラッグ・高さ計算）をさらに composable へ |
| L-7 | `app/Queries/ScheduleQuery.php` 日次集計 | 戻り値型が `array<string, mixed>` に緩んだ／コース名マスタを毎回全件取得／`online` を `source <> admin` で判定 |
| L-8 | `app/Http/Controllers/Booking/BookingConfirmationController.php:125-141` | ゲストの予約変更で担当未指定の場合、空き枠の `available_staff_ids` から担当を選ぶが、担当必須でないメニューではこの配列が常に空のため「空いていません」になる |
| L-9 | `config/mfa.php` `trusted_device.ttl_days=365` ＋管理画面の自動ログアウトなし | 端末の紛失・共用時に長期間 MFA なしで管理画面に入れる。店舗端末の運用ルールを `OPERATIONS.md` に明記するか、期間を短くする |

### ドキュメントと実装の食い違い

設計書（`docs/specs/`）作成の過程で見つかったもの。設計書側は**実装に合わせて**記載した。

| 文書 | 記載 | 実装 |
|---|---|---|
| `DB_SCHEMA.md` membership_plans | `monthly_price` / `included_sessions_per_month` | `price` / `usage_count_per_period` / `billing_interval` / `sort_order` |
| `DB_SCHEMA.md` memberships.status | active / paused / canceled | pending / active / grace / canceling / paused / canceled（`cancel_at_period_end`・`grace_until`・`membership_operation_id` 等も追加済み） |
| `DB_SCHEMA.md` reservations.source | 5 値 | `SALON_BOARD` / `EXTERNAL` を含む 7 値。`inflow_channel`・`staff_gender_preference`・`buffer_min` 列も未記載 |
| `DB_SCHEMA.md` staff_schedule_blocks.type | 7 値 | `WORK` を含む 8 値 |
| `ARCHITECTURE.md` §4.3 | スロット 10 分 | `config/reservation.php` と `SettingsSeeder` の既定は 5 分 |
| `ARCHITECTURE.md` §2 | モジュールは `app/Modules/*` | 実体の大半は `app/Domain/*`。`app/Modules` は外部予約 Gateway のみ |
| `DB_SCHEMA.md` | — | `reservation_guest_tokens` / `trusted_devices` / `ticket_reservation_usages` / `membership_reservation_usages` / `reservation_segments` が未記載 |

## 4. 推奨する対応順

1. **H-1**（修正は数行＋テストの予約日明示）
2. **H-3**（予約顧客を操作者として返金。ゲスト確認画面の文言も確認）
3. **H-2**（取消時の回数券の扱いを業務判断 → 実装）
4. **H-4**（運用ルールの明記をまず行い、実装は後追い）
5. **H-5**（本番構築の Task に必ず含める）
6. M-1 / M-2 は業務仕様の確認後に判断
7. M-5 / M-6 は今回の未コミット差分なので、コミット前に直す

いずれも `docs/PLAN.md` の Task 管理外の修正になるため、着手前に Task として承認を取る（AGENTS.md の作業ルール）。

---

## 5. 指摘のClose（Task 11-33、2026-10-07）

実装は Codex（5 バッチ）、レビュー・テスト実行・設計書同期は Claude Code。判定は最終コードを確認したうえで付けた。

| ID | 判定 | 対応 |
|---|---|---|
| H-1 | **Fixed** | `MembershipReservationService::reserve` が、解約予定会員について「予約開始の JST 営業日 > 期末」または「今日（JST）> 期末」を拒否。期末当日は可。既存テスト `test_f07_*` の予約日を明示（偶然依存を解消）し、期末前・当日・翌日・今日=期末・今日>期末・月末・年末・JST 0:30 / 23:30 の境界をデータ駆動で追加 |
| H-2 | **Fixed** | `CheckoutService::void` が、付与した回数券をロックし、予約で押さえ中・消化済みなら取消を拒否、未使用なら残数を全量 `REVOKE`（行・台帳・監査は保持）。予約キャンセルで回数が戻った回数券は取消可能 |
| H-3 | **Fixed** | ゲスト（操作者なし・顧客コンテキスト）のキャンセルは、予約の顧客ユーザーを返金の操作者として会員と同じ経路で自動返金 |
| H-4 | **Fixed** | 返金前に Stripe から返金累計を同期（取得失敗時は返金しない）、上限 = `max(Stripe 累計, 成功済み) + 保留中 + 今回 ≦ 決済額`、`refunded_amount` は減らさない。レビューで見つけた追加不具合（自分の返金の Webhook が先着すると二重計上）も修正し、完了後に再同期 |
| H-5 | **Fixed**（本番保存先は未検証） | `spatie/laravel-backup` 導入、`config/backup.php`、スケジュール（01:00 clean / 01:30 run / 07:00 monitor）、`ark:backup:verify-restore`、システム状態の最終バックアップ表示、`.gitignore` に保存先を追加。開発環境で backup → 別 DB へ restore → 全 89 テーブル・主要テーブルのチェックサム一致を確認 |
| M-1 | **Accepted** | 予約時点の期の回数を使う。次期の回数は請求成功時に付与され、予約時点では存在しないため現仕様として妥当。設計書に明記 |
| M-2 | **Accepted** | FEFO は予約日を見ないが、押さえた分は期限後も有効（`preserve_hold`）で顧客に不利がない。設計書に明記 |
| M-3 | **Fixed** | 予約（作成・変更・延長）と予定ブロック（作成・変更）がトランザクション内でスタッフ行・ブース行を同じ順でロックし、相手側との重複を再判定 |
| M-4 | **Fixed** | 開始前の予約は無断キャンセルにできない。既存テストの fixture は開始を過去に直した（アサーションは不変） |
| M-5 | **Fixed** | 予約台帳の客単価を月次概要と同じ定義（来店に紐づく確定会計の税込合計 ÷ 来店数）に統一。施術等・物販は Task 11-20 で決済日基準に揃っており現状維持 |
| M-6 | **Fixed** | 有効/無効スイッチを `@update:model-value`・目標値の明示送信・処理中 disabled に変更。業務マスタの編集ダイアログで全項目のエラーを表示 |
| L-1 | **Fixed** | 顧客コンテキストの推定は `admin.access` を持たない操作者に限定 |
| L-2 | **Fixed** | 延長で追加するメニューが無効なら拒否 |
| L-3 | **Deferred** | 来店完了・無断キャンセルの外部連携 Outbox 記録は、外部予約サービスの実 API 仕様が未確定のため見送り（連携は既定で無効） |
| L-4 | **Deferred**（一部 Fixed） | 既存の直書き文言の全面移行は無関係な大量変更になるため見送り。今回新規・変更した文言（予約台帳の日次集計、バックアップ、会計取消、無断キャンセル）は `messages.php` / `messages.ts` に置いた |
| L-5 | **Fixed** | `Schedule/Index.vue` の `any` を型の絞り込みヘルパーに置換（ビルド型エラー 0） |
| L-6 | **Deferred** | 巨大ファイルの分割は動作変更のリスクに見合わないため見送り |
| L-7 | **Fixed**（一部 Accepted） | 日次集計の戻り値を配列 shape で型付けし、分析分類名は必要な時だけ取得。`online`（`source ≠ ADMIN`）は新着予約通知と同じ既存定義なので維持 |
| L-8 | **Fixed** | ゲストの予約変更で、担当必須でないメニューは担当を自動選択せず現在の担当のまま |
| L-9 | **Accepted** | 信頼済み端末 365 日は `SESSION_POLICY.md` の方針。共用端末の運用注意を `OPERATIONS.md` §2.3 に追記 |
| 文書の食い違い | **Fixed** | 設計書（`docs/specs/`）は実装に合わせて記載済み。`OPERATIONS.md` の実在しないコマンド名（`reservations:reconcile-payments` 等）を実在のものへ修正 |

### Task 11-33 で追加で見つけて直したもの

| 内容 | 対応 |
|---|---|
| H-4 の初回修正で、ARK 自身の返金の Webhook が完了記録より先に届くと返金額を二重計上し「全額返金」と誤記録する不具合（Claude Code のレビューで検出） | 修正・回帰テスト |
| H-2 の初回修正が「過去に押さえた履歴」だけで取消を拒否し、予約キャンセル済みでも会計を取り消せなかった | 判定を予約との紐付け状態だけに修正・回帰テスト |
| 復元検証コマンドが、`USE <稼働DB>` を含むダンプを稼働 DB に書き込み得た／DB 名の同一判定が大文字小文字を区別していた | 取込前にダンプを検査して拒否・比較を大文字小文字無視に |
| バックアップ保存先 `storage/backups/` が Git 管理対象になり、個人情報を含むダンプをコミットし得た | `.gitignore` に追記 |
| `league/commonmark` 2.10.1 の既知脆弱性 2 件（既存依存。アプリでは未使用） | 2.10.3 へパッチ更新（`composer audit` 0 件） |
| `OPERATIONS.md` に実在しないコマンドが多数 | 実在コマンドへ置換 |
