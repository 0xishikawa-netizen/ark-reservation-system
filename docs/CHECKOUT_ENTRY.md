# 来店・会計入力（Task 11-19 / 11-20）

旧運用の「日計表」（1来店1行の手入力）をARKの事実データへ直接入力するための画面と規則。
比較根拠は `docs/handoff/2026-09-26-sample-comparison.md`。

## 画面と導線

| 画面 | route | 権限 |
|---|---|---|
| 来店・会計一覧（日別） | `GET /admin/checkouts?date=` | `checkouts.manage` |
| 予約から来店・会計を開く | ブッキングボード予約パネル「来店・会計」→ `POST /admin/reservations/{id}/visit` | `checkouts.manage` |
| 予約なし来店を作成 | 一覧「予約なし来店」→ `POST /admin/visits` | `checkouts.manage` |
| 店頭販売（来店なし会計）を作成 | 一覧「店頭販売」→ `POST /admin/checkouts` | `checkouts.manage` |
| 来店・会計入力 | `GET/PUT /admin/visits/{visit}/checkout`、完了 `POST /admin/visits/{visit}/complete` | `checkouts.manage` |
| 店頭販売入力 | `GET/PUT /admin/checkouts/{checkout}`、確定 `POST .../finalize` | `checkouts.manage` |
| 会計取消 | `POST /admin/checkouts/{checkout}/void`（理由必須） | `checkouts.manage` + `checkouts.void` |

`checkouts.manage` は admin と新規作成時の manager に付与。`checkouts.void` は admin のみ。staff には付与しない。
予約パネルの従来の「来店完了」（会計なし完了）は「…」メニューへ移し、既存の完了処理をそのまま使う。

画面は左に施術（主担当・指名スタッフ・施術・実担当と担当時間）と会計明細、右に会計サマリー（税抜・税額・税込）と支払（複数行）。
保存は画面状態で下書きを丸ごと置き換える（`CheckoutEntryService`）。確定済みは読み取り専用。

## 規則

- 予約あり来店は既存 `ReservationService::markCompleted` → `VisitCompletionService` の唯一の完了経路を通る（回数券・月額の予約消化、次回予約・初診snapshot、会計確定を1 transaction）。
- 予約なし来店は `VisitCompletionService::completeWalkIn` で同じsnapshot規則（来店順、初診性別・年齢、次回予約、指名）を適用する。予約に紐づかないため回数券・月額の予約消化は行わない（利用は予約経由、または既存の顧客回数券調整で行う）。
- 来店なし会計は `visit_id NULL`。回数券・月額の明細は顧客必須。匿名の物販は可。
- 施術の担当時間合計＝施術時間（保存時と施術確定時に検証）。開始時刻（JST HH:MM）を入れると担当ごとの実績区間を連続して保存し、時間帯別稼働率に使われる。時刻未入力は時間帯集計でunknown。
- 主担当・実担当・指名は別に保存する。指名は `visit_staff_nominations` にスタッフ単位で保存し、完了後は変更できない。指名欄を記録していない予約来店は、従来どおり予約の明示指名（`is_staff_requested`）だけを完了時snapshotにする。
- スタッフ売上配分は明細ごとに明示入力（`staff_revenue_allocations`）。「担当時間で按分」ボタンはPhase 11 §4.4の担当時間按分（最大剰余法）で入力欄を埋める補助で、担当だから100%とは自動推測しない。配分対象明細は配分合計＝税込額でないと確定できない。
- 支払合計＝会計税込合計でないと確定できない。Stripe APIは呼ばない（`payments`はprovider transaction、会計の支払内訳とは別）。
- 決済日基準の売上日は支払の受領日時（JST日付）。後日入力でも営業日の売上になるよう、当日以外は営業日の正午（JST）を受領日時にする。
- 確定済み会計は編集できず、取消（void、理由必須・監査）のみ。返金帰属は未確定のため自動控除しない。
- 税額: 価格は税込。明細ごとに `税込 × 税率 ÷ (1 + 税率)` を1円未満切り捨て（`TaxAmountCalculator`、画面プレビューも同式）。税率は売上日に有効な税区分の税率。税率未登録の税区分は保存できない。

## 支払配分（Task 11-20）

旧日計表の「施術支払方法」「物販支払方法」に対応する。支払そのものを施術／物販に固定せず、支払ごとに「うち物販」を明示入力する。

- 例: 施術8,000円＋物販3,000円を 現金5,000円＋PayPay6,000円 → 現金: 施術5,000、PayPay: 施術3,000・物販3,000。
- 画面は、施術等と物販が混在し支払が2件以上の時だけ「うち物販」欄を出す。自動按分はしない。
- 片方の区分しか無い、または支払が1件で合計が一致する場合は、答えが一意なのでサーバーが機械的に配分する。
- 確定時に支払ごと・区分ごとの合計を検証（`CheckoutService::assertTenderAllocations`）。
- 月計: 施術等決済別・物販決済別（税込）、施術等計・物販計（税抜）、税抜売上・税額・税込売上、売上金（選択基準）。配分未記録がある月だけ「配分未記録」列を出す。
