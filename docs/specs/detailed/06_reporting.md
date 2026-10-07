# 詳細設計 06 — 帳票（Reporting）

関連：基本設計 F-RPT。指標ごとの細則は次の既存文書が正本で、本書はそれらを「どの画面が・どのサービスを・どの正本から」で横断的にまとめる。

`docs/REPORTS_UI.md`（画面・表示ルール）／`docs/DB_SCHEMA.md` Task 11-5〜11-11（read model）／`docs/CUSTOMER_ANALYTICS.md`／`docs/STAFF_UTILIZATION.md`／`docs/TIME_BAND_UTILIZATION.md`／`docs/ANNUAL_REPORTING.md`／`docs/EXCEL_EXPORT.md`／`docs/HISTORICAL_IMPORT.md`／`docs/PHASE11_RECONCILIATION.md`

## 1. 基本方針

| 方針 | 内容 |
|---|---|
| 集計テーブルを持たない | 保存済みの事実（来店・施術・会計・支払・配賦）から都度集計する |
| 二重計上を構造的に防ぐ | 施術・スタッフ・明細・支払を 1 つの JOIN に連結しない。粒度ごとに別 SQL |
| SQL 本数を固定 | 期間（月）でも 8 本の集約 SQL で取得し `GROUP BY business_date`。1 日ずつの N+1 を作らない |
| 日付 | 営業日は JST。timestamp 列は JST 日の `[00:00, 翌00:00)` を UTC に変換した半開区間で検索（索引列に DATE 関数を使わない） |
| 推測しない | 値が無いものは unknown / NULL として件数を残す。現在のマスタ・予約・プロフィールから過去を推測しない |
| 率 | 期間の率は「分子合計 ÷ 分母合計」。日率の平均にしない。分母 0 は NULL（0% にしない）。100% 超を丸めない |
| 共通入口 | 単日・月・年・Excel・画面 API は同じ Summary（`DailyBusinessSummary` / `MonthlyBusinessSummary`）を再利用する |

## 2. サービスと正本

| サービス | 主な出力 | 正本 |
|---|---|---|
| `DailyReportQuery` / `DailyReportService` | 1 日・期間の来店・初診・次回予約・ロング・分析分類・決済日売上・施術日売上・支払方法別・税別・施術等/物販・会計待ち | `visits`、`visit_treatments`、`checkouts`/`lines`/`tenders`/`allocations`、`revenue_allocations` |
| `MonthlyReportService` / `MonthlyReportQuery` | 月の全暦日、2 つの売上基準、月率、前半/後半、目標進捗、営業日数、平日/土日平均 | 上記＋`store_calendar_days`、`monthly_sales_targets`、`settings` |
| `MonthlyOverviewService` | 月次概要（売上・来店・継続・稼働・決済構成） | 上記の合成 |
| `DailyLedgerService` | 日計明細（1 来店 1 行） | 来店・会計 |
| `ReservationAnalysisService` | 予約分析（経路・キャンセル率など。ARK 独自） | `reservations` |
| `CustomerAnalyticsService` / `CustomerAnalyticsQuery` | 初診コホート、再診・離反、2/6/10 回到達、初診時属性 | `visits.visit_sequence`・初診控え |
| `StaffUtilizationService` | スタッフ別の来店・稼働分・勤務分・予約可能分・指名・予約率（雇用区分別） | 主担当来店、実担当分、`staff_shifts`、`staff_attendances`、ブロック、`staff_employment_periods` |
| `TimeBandUtilizationService` | 4 つの JST 時間帯ごとの稼働率 | 同上を時間帯で分割 |
| `StaffSalesService` | スタッフ別売上・指名売上・非指名・指名不明 | `staff_revenue_allocations`、`visit_staff_nominations` |
| `CourseSalesService` | コース（回数券・月額・メニュー）別売上と目標 | 会計明細、`course_sales_targets` |
| `AnnualReportService` | 4 月始まり事業年度の 12 か月合成、年率 | 月計・顧客・稼働の合成 |
| `ReportWorkbookService` ほか `Excel/*` | 旧 6 シート互換の Excel（原本テンプレートの固定セルへ書き込み） | 上記 Summary |
| `HistoricalImportService` / `Import/LegacyWorkbookConverter` | 旧帳票の取込（原本の保護コピー・SHA-256・暗号化セル） | `historical_import_*`、`historical_metric_values` |
| `ReportReconciliationService` | 旧値と ARK 値の差異分類・確認記録 | `historical_metric_reviews` |
| `DailyBusinessNoteService` | 日別営業記録（営業の様子・振り返り） | `daily_business_notes` |

## 3. 主な指標の定義

| 指標 | 定義 |
|---|---|
| 来店数 | `visits.status=completed` の件数（営業日別） |
| 初診 | `visit_sequence = 1` |
| リピーター | 来店数 − 初診 |
| 次回予約率 | 完了時に「他の未来の有効予約あり」の件数 ÷ 来店数。控えが NULL のものは unknown |
| ロング | 来店内の完了施術の `actual_minutes` 合計 > 60。施術なし・分数 NULL は unknown |
| 分析分類（コース） | 施術の分類コードの控えを来店内で distinct。M&T を分解しない。NULL は unknown |
| 決済日基準売上 | 確定会計の受領済み支払を受領日（JST）で合計。下書き・取消は除外 |
| 施術日基準売上 | 完了来店の施術に直結する確定明細＋`revenue_allocations.recognized_on` |
| 税・税抜 | 明細の控え（net / tax / gross）を合計。再計算しない。日付は受領済み支払の最終受領日 |
| 施術等 / 物販 | 支払配分（`treatment` / `retail`）。混在会計で配分未記録なら「配分未記録」 |
| 客単価（月次概要） | 来店に紐づく会計 ÷ 完了来店数（店頭販売は含めず別表示） |
| 返金 | 帰属日が未確定のため自動控除しない |
| 営業日 | `store_calendar_days.status=closed` を除く日。平日＝月〜金、土日＝土日（祝日判定なし） |
| as_of_date | 当月＝JST 今日、過去月＝月末、未来月＝月初前日。実績はこの日まで、残営業日は翌日以降 |
| 売上目標 | 月別（`monthly_sales_targets`）優先、無ければ `settings.sales.target.default_amount` |

## 4. 画面・API と権限

| 画面 | 画面ルート | データ API | 権限 |
|---|---|---|---|
| 概要 | `/admin/reports/overview` | — | reports.view + sales.view |
| 日計明細 | `/admin/reports/daily-ledger` | — | 同上 |
| 予約分析 | `/admin/reports/reservation-analysis` | — | reports.view |
| 日報（日別営業記録） | `/admin/reports/daily-notes` | `PUT …/{businessDate}`（reports.manage） | reports.view |
| 日次（JSON） | — | `/admin/reports/daily` | reports.view + sales.view |
| 月計 | `/admin/reports/monthly` | `/monthly/data` | reports.view + sales.view |
| 顧客統計 | `/admin/reports/customers` | `/customers/data` | reports.view |
| スタッフ稼働率 | `/admin/reports/staff-utilization` | `/staff-utilization/data` | reports.view |
| 時間帯別稼働率 | `/admin/reports/time-bands` | `/time-bands/data` | reports.view |
| スタッフ別売上 | `/admin/reports/staff-sales` | `/staff-sales/data` | reports.view + sales.view |
| コース別売上 | `/admin/reports/course-sales` | `/course-sales/data`、目標 `PUT …/targets`（＋settings.manage） | reports.view + sales.view |
| 年間 | `/admin/reports/annual` | `/annual/data` | reports.view + sales.view |
| Excel 出力 | — | `/admin/reports/excel` | ＋reports.export |
| 照合 | `/admin/reports/reconciliation`、確認 `POST …/{metric}/review` | | ＋reports.reconcile |
| 過去データ取込 | `/admin/reports/historical-imports`（プレビュー・取込・確定・顧客照合・無効化） | | historical_data.import（プレビュー・取込は `throttle:6,1`） |
| 勤怠入力 | 勤務枠画面「実勤怠」タブ（`POST/PUT /admin/staff-shifts/attendances`） | | shifts.manage |

## 5. 表示ルール（要約）

`docs/REPORTS_UI.md` §2〜5 が正本。

- 値なしの区別：「未取得（unknown）」「対象なし（0 件）」「未実績（未来日）」「算出不可（分母 0）」を表示し分ける。0 と空欄を混同しない。
- 日付・年月・年の選択は共通部品（`DateField` / `MonthField` / `YearField`）。
- 表は `ReportTable` に統一。

## 6. 既知の制約

- 旧 Excel / Google Sheets との実数値照合（Task 11-13）は外部資料待ちで BLOCKED。
- 勤怠の締め・承認フローは未実装（手入力の修正を許容）。
- 返金・途中解約・期限切れの売上帰属規則は未確定。
