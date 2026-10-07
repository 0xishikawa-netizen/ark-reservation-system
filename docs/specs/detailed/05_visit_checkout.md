# 詳細設計 05 — 来店（実績）・会計

関連：基本設計 F-VIS、既存文書 `docs/CHECKOUT_ENTRY.md`（画面規則の正本）、`docs/DB_SCHEMA.md` Task 11-3 / 11-4 / 11-19 / 11-20

## 1. 責務の分離

| 概念 | テーブル | 意味 | 変更できる時 |
|---|---|---|---|
| 予約 | `reservations` | 予定 | 完了・取消まで |
| 来店 | `visits` | 実際に来た事実（予約あり／飛び込み） | `draft` の間だけ。完了後は不変 |
| 施術実績 | `visit_treatments`, `visit_treatment_staff` | 実際のメニュー・時間・実担当・ブース | 来店が `draft` の間 |
| 指名 | `visit_staff_nominations` | 来店単位・スタッフ単位の指名 | 来店完了まで |
| 会計 | `checkouts`, `checkout_lines` | 店舗の売上（明細ごとの税込・税抜・税額の控え） | `draft` の間。確定後は取消のみ |
| 支払内訳 | `checkout_tenders`, `checkout_tender_allocations` | 現金＋PayPay などの複数支払と、施術等／物販への配分 | 会計が `draft` の間 |
| スタッフ売上配分 | `staff_revenue_allocations` | 明細ごとのスタッフ別配分（最終額が正本） | 同上 |
| 施術日基準の売上 | `revenue_recognition_contracts`, `revenue_allocations` | 回数券・月額の売上を施術日に配賦 | 追記のみ |
| カード決済 | `payments` | Stripe との取引。会計とは別（`checkout_tenders.payment_id` で任意に紐付け） | [03_payment.md](03_payment.md) |

## 2. 状態

| 対象 | 状態 | 遷移 |
|---|---|---|
| `visits.status` | `draft` → `completed` / `voided` | 完了は `VisitCompletionService` のみ |
| `visit_treatments.status` | `draft` → `completed` / `voided` | 来店完了と同時に確定 |
| `checkouts.status` | `draft` → `finalized` → `voided` | 確定は `CheckoutService::finalize`、取消は `void`（理由必須） |
| `checkout_tenders.status` | `received` / `voided` | |

## 3. 来店完了（`VisitCompletionService`）

### 3.1 予約ありの来店（`completeReservation`）

**1 つの短い DB トランザクション**で、次の順に確定する（Stripe・外部 API は呼ばない）。

1. 予約行 `FOR UPDATE`。
2. 顧客行 `FOR UPDATE`（同じ顧客の来店順採番を直列化）。
3. `visits`（`reservation_id` UNIQUE）を作るか、既存の下書きを使う。営業日＝予約開始の JST 日付。
4. 下書きの施術・実担当があれば検証して確定。無ければ予約枠（インターバルを除く）と予約担当から初期実績を作る（`seedTreatmentsFromReservation`）。
5. 下書き会計があれば `CheckoutService::finalize` で確定。無ければ「会計未確定」のまま完了を許す（税・支払を推測で作らない）。会計免除理由（`free` / `prepaid` / `entitlement`）があれば保存。
6. 回数券 CONSUME / 月額 CONSUME（既存台帳・既存 dedupe key）。
7. 顧客行ロック下で `visit_sequence = MAX + 1`（初診＝1）。
8. 完了時刻を基準に「この予約以外に、未来の有効な予約があるか」を `EXISTS` で判定し、次回予約の有無を保存。
9. 初診なら性別・満年齢・流入経路・紹介・地域・来店目的を控えとして保存。指名の控えも保存。
10. 来店 `completed`、予約 `completed`（State Machine 経由）、`attended_at`、監査。

冪等：`visits.reservation_id` UNIQUE、顧客内 `visit_sequence` UNIQUE、決定的な `completion_operation_id`。ダブルクリック・再送・同時実行は 1 件に収束し、完了済みへの再試行は保存済みの値を返す（再計算しない）。キャンセル・無断キャンセル・来店記録のない旧完了予約は新たに完了しない。

### 3.2 予約なしの来店（`completeWalkIn`）

同じ控え（来店順・初診属性・次回予約・指名）を適用する。予約が無いため回数券・月額の消化は行わない。営業日は作成時に選んだ日。

## 4. 会計

### 4.1 明細（`checkout_lines`）

| 項目 | 内容 |
|---|---|
| 種別（`item_type`） | `service`（施術）/ `product`（物販）/ `ticket`（回数券購入）/ `membership`（月額）/ `other` |
| 参照 | 施術実績・メニュー・商品・回数券商品・月額プランへの nullable FK |
| 控え | 名称、税区分コード・名称、適用税率（basis point）、数量、単価、税抜・税額・税込 |
| スタッフ配分対象 | `is_staff_allocatable` |

税額：価格は税込。明細ごとに `税込 × 税率 ÷ (1 + 税率)` を 1 円未満切り捨て（`TaxAmountCalculator`）。税率は売上日に有効な税区分の税率。税率が登録されていない税区分は保存できない。集計時に再計算しない（控えを合計する）。

### 4.2 支払と配分

- 支払は複数可（`checkout_tenders`：決済方法マスタ、金額、受領日時、外部参照、任意の `payment_id`）。
- 配分（`checkout_tender_allocations`）：支払ごとに「施術等（`treatment`）／物販（`retail`）」へいくら充てたか。UNIQUE（支払, 区分）。
  - 片方の区分しか無い、または支払が 1 件 → サーバーが機械的に決める。
  - 施術等と物販が混在し、支払が 2 件以上 → 「うち物販」の明示入力が必要（自動按分しない）。
- 受領日時：当日入力は現在時刻、後日入力は営業日の正午（JST）。決済日基準の売上日はこの日付。

### 4.3 確定（`CheckoutService::finalize`）

会計・明細・支払・配分を `FOR UPDATE` し、すべて満たす時だけ `finalized`。

| 検証 | エラー |
|---|---|
| 下書きであること | 下書き会計だけを確定できます |
| 明細が 1 件以上 | 会計明細がありません |
| 配分対象明細：スタッフ配分の合計 ＝ 明細の税込 | スタッフ売上配分の合計が…一致しません |
| ヘッダ合計 ＝ 明細合計 | 会計ヘッダーと明細の合計が一致しません |
| 受領済み支払の合計 ＝ 会計の税込合計 | 有効な支払明細の合計が会計総額と一致しません |
| 配分がある場合：支払ごとの配分合計＝支払額、区分ごとの合計＝該当明細の税込合計 | `messages.checkout_entry.tender_allocation_*` |
| 来店会計：来店が完了済み（`CheckoutEntryService::finalize`） | `complete_visit_first` |

### 4.4 確定時の副作用

- 回数券購入の明細：顧客必須。数量ぶん `TicketLedgerService::grant`（dedupe `checkout-line:{line}:{n}`）。
- 月額の明細：売上の記録のみ（利用権は月額管理が正本）。
- 監査ログ。

### 4.5 取消（`CheckoutService::void`）

確定済みのみ、理由必須、監査 `checkout.voided`。同じトランザクションで、その会計の回数券明細から付与した回数券（`grant:checkout-line:{line}:{n}`）を扱う（Task 11-33 / H-2）。

1. 会計明細 → 付与記録 → 回数券の順に ID 順で `FOR UPDATE`。
2. いずれかの回数券が予約で**押さえ中**または**消化済み**（`ticket_reservation_usages.status` が `held` / `consumed`）なら取消を拒否（`messages.checkout_entry.void_ticket_in_use`）。会計は確定のまま、何も取り消さない。先に予約の支払方法変更・キャンセル、または回数券の手動調整を行う。予約キャンセルで回数が戻った回数券は取消できる。
3. 未使用なら利用可能残数をすべて `REVOKE`（dedupe `checkout-void:{checkout}:{wallet}`、理由に会計取消理由）。回数券の行・台帳は削除しない。監査 `ticket.revoked`。
4. 二重の取消要求は 2 回目が何もしない（REVOKE も監査も増えない）。

取消済み会計は日次・月次の売上から除外される（確定会計だけを集計するため）。返金の売上帰属は未確定のため、帳票で自動控除しない。

## 5. 入力画面の保存方式（`CheckoutEntryService`）

- 保存は「画面の状態で下書きを丸ごと置き換える」。施術を置き換える前に、施術に紐づく明細・配分を外す（`clearDraft`）。
- 施術の担当時間の合計＝施術時間（保存時と確定時に検証）。開始時刻（JST HH:MM）を入れると担当ごとの実績区間を連続して保存し、時間帯別稼働率に使う。未入力は時間帯集計で unknown。
- 予約から開いた下書きの初期値：予約メニュー・開始・分数・担当・明示指名。施術料の下書き明細は**現地払いの予約だけ**（回数券・月額・事前決済は二重請求を避けるため作らない）。
- 予約の合計分数を超える施術実績は保存できない（`exceeds_reserved_minutes`）。

## 6. 画面・API

| メソッド・パス | 権限 | 内容 |
|---|---|---|
| `GET /admin/checkouts?date=` | checkouts.manage | 来店・会計一覧（日別） |
| `POST /admin/reservations/{id}/visit` | checkouts.manage | 予約から来店下書きを開く（冪等） |
| `POST /admin/visits` | checkouts.manage | 予約なし来店を作成 |
| `GET/PUT /admin/visits/{id}/checkout` | checkouts.manage | 来店・会計入力の表示・保存 |
| `POST /admin/visits/{id}/complete` | checkouts.manage | 来店完了（下書き会計があれば同時に確定） |
| `POST /admin/checkouts` | checkouts.manage | 店頭販売（来店なし会計）を作成 |
| `GET/PUT /admin/checkouts/{id}` | checkouts.manage | 店頭販売の表示・保存 |
| `POST /admin/checkouts/{id}/finalize` | checkouts.manage | 確定 |
| `POST /admin/checkouts/{id}/void` | checkouts.manage + checkouts.void | 取消（理由必須） |
| `PATCH /admin/reservations/{id}/complete` | reservations.manage | 会計なしの来店完了（免除理由必須） |

## 7. 施術日基準の売上（`RevenueRecognitionService`）

- 契約（`revenue_recognition_contracts`）：既存の回数券または月額（期付き）へのリンクと、購入時の契約金額の控え。状態 `draft` / `active` / `closed` / `voided`。
- 配賦（`revenue_allocations`）：来店・施術・回数券利用・月額利用へのリンク、計上日（`recognized_on`）、金額、契約内連番、端数行フラグ、操作キー。利用ごと・操作キーごとに UNIQUE、追記のみ。
- 契約行をロックして配賦超過を防ぎ、`close` 時だけ配賦合計＝契約金額を要求する。
- 未消化・途中解約・返金・期限切れの帰属規則は未確定（`docs/OPEN_QUESTIONS.md`）。
