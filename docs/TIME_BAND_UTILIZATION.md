# Phase 11 / Task 11-9 時間帯別稼働率

`TimeBandUtilizationService`はTask 11-8と同じ`MinuteIntervals`、`staff_shifts`、`staff_attendances`、実休憩、`staff_schedule_blocks`、`StoreCalendarService`を使う。4帯は`[10:00,12:00)`、`[12:00,15:00)`、`[15:00,18:00)`、`[18:00,21:00)`。すべてJSTの半開区間とし、11:30〜12:30は30分ずつ配分する。

完了Visit・完了施術の`visit_treatment_staff.actual_started_at/actual_ended_at`と`actual_minutes`が一致する場合だけ時間帯へ配分する。新たな予約完了時に予約枠から初期実績を作る経路は、既存の分数算出と同じ予約枠を実績時刻snapshotとして記録する。手入力施術で実担当時刻が未入力の行、旧Visitの時刻欠損、区間長と分数の不一致は推測せず、対象スタッフ日の全帯の稼働分・率をNULLとし`occupied_unknown_count`を返す。帯外の確定分数は`outside_band_minutes`として別計上し、4帯へ押し込まない。日跨ぎ区間はJST日境界でも分割する。

勤務分母はTask 11-8と同じく、実勤怠が完備していれば実出退勤union−実休憩union、勤怠なしで予定シフトがあれば予定代用、実勤怠欠損ならNULL。予約可能分母は予約可能スタッフの予定シフト∩店舗営業時間から、スタッフ予定ブロックと実休憩のunionを控除する。休業日は0、特別営業時間は当日設定を使う。勤務・予約可能区間を各帯へintersectionして分数にする。`legacy_utilization_rate=稼働分/勤務分`、`bookable_utilization_rate=稼働分/予約可能分`で、分母0・不明分子はNULL。全体・月率は分子と分母の合計で計算し、日率の平均はしない。

管理画面とJSON APIは`GET /admin/reports/time-bands`と`/data`で、`reports.view`が必要。年月、`staff_id`、`as_of_date`で絞り込める。稼働時刻、勤務、block、勤怠、カレンダーは期間一括取得し、スタッフ×日数のDB N+1を避ける。過去の実績時刻を推測backfillしない。Google Sheetsや外部予約サイトへの書込はない。

## Task 11-23 表示拡張

時間帯・率の定義（4帯、`時間帯稼働率 = 稼働分 / 予約可能分`、既存互換率 = 稼働分 / 勤務分）は変更しない。旧「時間帯別稼働率」Excelの分母「稼働時間」は勤務か予約可能か資料から判断できないため、ARKは両方の率を表示し、旧値に合わせない。

- **人数**: その時間帯に実担当区間が1分以上重なった完了来店の数。店舗全体はVisit単位、スタッフ別は（スタッフ, Visit）単位で重複を除く。11:30〜12:30の1来店は10〜12・12〜15の両方で1人。担当時刻が未記録の来店は推測で帯に入れず `visit_unknown_count` に件数を出す。
- **店舗全体の日別×時間帯**（`store_daily_rows`）: 全スタッフ（スタッフ絞り込み時はそのスタッフ）の稼働・勤務・予約可能分の合計と率。
- **平日 / 土日**（`day_type_rows`）: 平日＝月〜金、土日＝土・日（祝日は曜日どおり）。率は分子合計 ÷ 分母合計。旧Excelの「日率の平均」「120分帯と180分帯の単純平均」は再現しない。
- 全体（帯別）に稼働時間（分÷60、表示のみ）と人数を追加。
