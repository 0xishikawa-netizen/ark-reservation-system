# Phase 11 / Task 11-8 スタッフ勤務・稼働率

## 正本と単位

`StaffUtilizationService`はJSTの`visits.business_date`ごとに、来店指標と時間指標を別集計する。来店指標は`visits.status=completed`かつ`primary_staff_id`が一致するVisitを1件ずつ数える。複数施術・複数担当でも人数、次回予約人数、指名数は増えない。主担当NULLは`unknown_primary_visit_count`へ残す。

稼働分は完了Visit内の完了`visit_treatments`に属する`visit_treatment_staff.actual_minutes`合計。予約枠や現在のメニュー時間から再計算しない。担当分数NULLがあれば、そのスタッフ日の稼働分・率はNULL、`occupied_unknown_count`で件数を示す。スタッフ売上は本TaskのSummaryに含めず、必要な時は保存済み`staff_revenue_allocations`を正本にする。

次回予約人数は主担当Visitの`future_reservation_exists_at_checkout=true`数。指名は予約の明示的`is_staff_requested`のみを完了時に`visits.staff_requested_at_checkout`と`requested_staff_id_at_checkout`へsnapshotする。後の予約変更で過去値は変わらない。既存Visitは推測backfillせずNULL。指名先と主担当が異なる場合も主担当の指名として誤計上せずunknown扱い。NULLが混じるスタッフ日の指名数・率はNULL、`nomination_supported=false`と`nomination_unknown_count`を返す。担当者IDがあるだけでは指名ではない。次回予約snapshotのNULLも同様に未知として扱う。

## 勤務・分母

`staff_shifts`は予定、`staff_attendances`は実出退勤の別事実。分割勤務を許容し、勤怠の出勤・退勤はUTC日時、`business_date`は出勤時JST日付。休憩は`staff_attendance_breaks`にUTC区間で保持する。実出退勤が揃った日は勤務区間unionから実休憩unionを1回だけ控除して`actual`。勤怠行がない日はシフトunionを`scheduled_fallback`。勤怠があるが出勤・退勤が欠損する日は`unknown`で、予定へ黙ってfallbackしない。シフトも勤怠もなければ`unknown`かつ`scheduled_shift_present=false`。夜勤はJST日境界で実分数を分割する。入力画面は既存勤務枠画面の「実勤怠」タブで、`shifts.manage`権限と作成・修正auditを使う。

予約可能分は、同じ`StoreCalendarService`で解決した通常・特別営業時間とシフトの積集合から、担当スタッフの予定ブロック（休憩・会議・研修・予約不可等）と実休憩をunionして控除する。臨時休業または現在予約受付停止中のスタッフは0分。実績稼働は休業日でも消さない。予約済み枠は分母から控除しない（そこは稼働時間であり、予約可能容量の一部）。サービス適合、ブース空き、予約受付期間・リードタイム、過去の`is_bookable`変更履歴は現在のDBから汎用的な分単位分母として再現できず、個別予約の空き候補とは区別する。Task 11-9へ渡すのは勤務・block・休憩のintervalであり、ここでは時間帯別bucketは作らない。

既存互換率=`occupied_minutes/working_minutes`、予約可能基準率=`occupied_minutes/bookable_minutes`。予約率=`future_reservation_count/patient_count`、指名率=`nomination_count/patient_count`。分母0・unknownはNULL、100%超をcapしない。月率は日率平均ではなく各分子・分母の月合計から求める。未来日は日次実績・月次実績の分母に加えない。`as_of_date`を指定可能で、未指定は対象月末・JST今日・未来月の月初前日のうち適切な日を用いる。

雇用区分は`staff_employment_periods`の半開区間`[effective_from,effective_to)`から対象日に決める。月途中で区分変更した場合、月次行もスタッフ×雇用区分で分ける。区分なしはNULLとして保持し、社員・アルバイトのハードコードはしない。Task 11-11ではこの区分別行から既存2シートへmappingできる。

## API・性能・残る制約

`GET /admin/reports/staff-utilization`と`/data`は`reports.view`。`year`,`month`,`staff_id`,`employment_type_id`,`as_of_date`で絞り込む。来店と実担当は別GROUP BY、シフト・ブロック・勤怠・雇用履歴・店舗カレンダーは月範囲で一括取得するため、スタッフ×日数のDB N+1はない。画面の月合計・日別一覧はNULL/未取得/勤務予定なしを区別する。

実勤怠の手入力は完成済み勤務区間の修正を許容するため、締め後の改ざん防止・承認フローは未実装。勤怠の休憩は区間内のみ保存可能で、重複入力時も集計はunionする。過去の予約可能設定履歴・指名snapshotがない旧Visitを推測で埋める移行も行わない。実Excel出力はTask 11-11、時間帯別稼働率はTask 11-9。

## Task 11-22 スタッフ別売上・指名売上

`StaffSalesService`（`/admin/reports/staff-sales`、`reports.view`＋`sales.view`）。正本は確定会計の `staff_revenue_allocations`（明細ごとに明示入力された税込配分額。担当だから100%とは推測しない）。

- 売上: スタッフへの配分額の合計。決済日基準（会計の受領済み支払の最終受領日＝月計と同じ）と施術日基準（来店の営業日。来店なし会計は施術日がないため対象外）を切り替えられる。
- 指名売上: 来店単位・スタッフ単位の指名snapshot（`visit_staff_nominations`）にそのスタッフが含まれる来店での、そのスタッフへの配分額。10,000円をA6,000/B4,000で配分し両者指名なら、A指名6,000・B指名4,000（配分額以上を計上しない）。
- 非指名売上: 指名記録済みでそのスタッフが指名されていない来店、および来店なし会計の配分額。
- 指名不明: 指名未記録の旧来店（`visits.nominations_recorded_at` NULL）の配分額。推測で指名/非指名へ振り分けない。指名不明があるスタッフの指名売上比率は算出不可（NULL）。
- 主担当・実担当・指名は別概念で、主担当や担当者であることから指名を推測しない。Task 11-8の主担当単位の指名数・指名率は従来の意味のまま。
- 配分額は税込（明細税込額を配分）。税抜の配分は丸めが必要になるため作らない。
