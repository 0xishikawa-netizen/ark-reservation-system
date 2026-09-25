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

来店目的・動機・紹介者・地域の入力元および初診時snapshotの設計は、原本確認・承認後に別Taskで確定する。Google Sheets実書込、SALON BOARD、外部予約サイト同期は本Taskの対象外。
