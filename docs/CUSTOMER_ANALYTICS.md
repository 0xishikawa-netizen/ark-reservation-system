# Phase 11 Task 11-7 — 顧客統計

## 正本と期間

`visits.status=completed`、`visits.business_date`（Asia/Tokyo）だけを来店履歴とする。対象月Mは`[月初,翌月初)`の半開区間。予約や取消・voided・draftは履歴へ入れない。人数は顧客IDの重複を除いた人数であり、来店件数ではない。Task 11-12の過去移行前に存在しないVisitを予約履歴から生成しない。

`as_of_date`はJSTの日付で、対象月のcohort確定と到達判定をその日までに限定する。既定値はJST当日。過去月のcohortも指定日まで追跡する。対象月が未来なら新規・再診は0、前月がまだ終わっていない場合の離反はNULL（未判定）とする。未来日付の完了Visitを指定日より先取りしない。

## 指標

| 指標 | 定義 |
|---|---|
| 新規 | M内の`visit_sequence=1`の完了Visitを持つ顧客。月計の`first_visit_count`と同じVisit事実・同じsequence定義。 |
| 再診 | Mに完了Visitあり、M-1に完了Visitなし、M-2以前に完了Visitあり。新規は含めない。 |
| 離反 | M-2に完了Visitあり、M-1に完了Visitなし。Mの来店有無は問わない。 |
| 2/6/10回目到達 | Mの新規cohortのうち、`as_of_date`までの完了Visitの最大`visit_sequence`が閾値以上の顧客。 |
| 到達率 | 到達人数 / Mの新規cohort人数。分母0はNULL。最近cohortも分母から除外しない。 |

再診と離反は排他的ではない。たとえば7月来店・8月なし・9月再来店は9月の両方に入る。到達人数は`10 <= 6 <= 2 <= 新規`を検証する。顧客別のN+1照会は行わず、cohortを絞るSQL、集約・EXISTS / NOT EXISTSを使う。

## 初診属性と未入力

初診完了時、顧客の性別とその営業日時点の満年齢を`visits`へnullable snapshot保存する。暗号化生年月日そのものは複製せず、年齢のみ保存する。現在のプロフィールを変更しても過去の初診統計は変わらない。既存Visitは推測backfillしないため両列NULLのまま「未入力・不明」に含む。生年月日がない場合に手入力年代を代用するルールは未承認であり、代用しない。

初回担当は初診Visitの主担当ID・名前snapshot、次回予約は初診Visitの`future_reservation_exists_at_checkout`のtrue/false/NULLを使用し、現在の予約や現在のスタッフ名を再参照しない。コースはTask 11-1の定義に沿い、初診の完了施術に保存された分析分類snapshotからM/T/A/M&T/A&Tを得る。分類なし・未知の組合せはunknownとし、予約serviceから推測しない。

年代bucketはユーザー承認済みの10区分で、`AgeDecadeBucket`が機械可読codeと表示名を一元管理する。0〜9歳、10代、20代、30代、40代、50代、60代、70代、80代、90代以上。初診時満年齢snapshotがNULLならunknownとし、年代別人数の合計は新規人数と一致する。現在年齢へ再計算しない。来店目的・動機・紹介者・都道府県・市区町村は現行DBに構造化項目がないため`not_captured`とし、自由入力メモ・住所文字列等から推測しない。これらは対象cohort全員をunknownに保持する。各利用可能dimensionのNULLもunknown bucketとして人数に含み、「その他」へ統合しない。割合を算出する場合の分母は新規cohort全員とする。

## 入口と未確定事項

管理画面`/admin/reports/customers`、JSON API`/admin/reports/customers/data?year=...&month=...&as_of_date=...`は`reports.view`を要求する。月計画面と相互導線を持つ。現行結果はVisitが蓄積された範囲のみであり、過去Excel/Google Sheets原本との照合や取込はTask 11-12/13で行う。

来店目的・動機・紹介者・地域はTask 11-21で入力元と初診時snapshotを実装した（下記）。Google Sheets実書込、SALON BOARD、外部予約サイト同期は本Taskの対象外。

## Task 11-21 顧客カルテ項目

**入力元**: 管理画面の顧客編集「カルテ（分析項目）」（`PUT /admin/customers/{customer}/karte`、`customers.manage`、監査は変更項目名のみで値を残さない）。
旧運用では「新規統計」ブックの「顧客データ一覧」に手入力し、日計表がIMPORTRANGEで参照していた。

| 項目 | 保存先 | 選択肢の正本 |
|---|---|---|
| 来店動機 | `customers.acquisition_channel_id`（＋その他記入 `acquisition_note`） | `acquisition_channels`（業務マスタ「カルテ選択肢」で追加・無効化・並び替え）。`reservations.inflow_channel`（Web予約の流入元）とは別概念 |
| 来店目的 | `customer_visit_purpose`（複数選択）＋補足 `visit_purpose_note` | `visit_purposes` |
| 紹介者 | `customers.referrer_customer_id`（任意の既存顧客）または `referrer_name`（顧客以外の記入） | — |
| 都道府県・市区町村 | `customers.prefecture`（47都道府県から選択）/ `city`（数字を含む番地らしい値は拒否） | 番地・建物名は分析データとして持たない |

**初診snapshot**: 初診（`visit_sequence=1`）完了時に `visits.first_visit_karte_snapshot_at`、`first_visit_acquisition_channel_id`、`first_visit_referred`、`first_visit_prefecture`、`first_visit_city`、`visit_first_purposes` を保存する。後日の顧客情報変更で過去の新規統計は変わらない。snapshot前の旧Visitと未入力はunknown（0や「その他」にしない）。紹介の有無は「紹介者の記録あり、または来店動機=紹介」ならtrue、来店動機が別の値ならfalse、どちらも未入力ならNULL。

**統計**: 顧客統計の来店動機・来店目的（複数選択のため合計は新規人数を超え得る）・紹介・都道府県・市区町村を `available` とした。加えて `cross_tabs.motivation`（来店動機別の新規数・2回目到達数・到達率）と `cross_tabs.first_staff`（初回担当別）を返す。到達の定義・観察期間は既存の2/6/10回到達と同じ（as_of_dateまでの完了Visit）。紹介者の氏名は統計に出さない。

**選択肢の根拠（旧資料のdistinct値）**:

- 来店動機: 顧客データ一覧（ホトぺ315 / EPARK170 / 紹介114 / HP92 / チラシ90 / その他47 / OZmall5）、日計表の予約媒体（EPARK / HP / チラシ / 紹介 / ホトぺ / 都立 / 看板 / OZmall / その他）、新規統計（Hotpepper、旧シートは「ホットペッパー」）。「ホトぺ」「Hotpepper」「ホットペッパー」は同一媒体の表記違いとして「ホットペッパー」に統一した。「都立」の意味は資料から判断できないため表記のまま残した。
- 来店目的: 完全一致で繰り返し使われた「痛みを取りたい」（40）「リラクゼーション」（21）「根本的に治したい」（15）「運動不足解消」（7）と「その他」だけをマスタにした。次の値は同義か判断できないため統合せず、**手動確認対象**とする: 痛み取りたい（6）、痛み改善（5）、症状改善（3）、ダイエット（3）、疲れ（3）、姿勢改善（2）、筋肥大（2）、痛みとりたい（2）、複数目的を読点で連結した値（例「痛みを取りたい、根本的に治したい」）、その他の自由記述（1件ずつ）。必要なら業務マスタで選択肢を追加し、過去取込時（Task 11-26）に対応表を承認して割り当てる。
