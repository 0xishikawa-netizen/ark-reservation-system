# Phase 11 / Task 11-10 年間集計

`AnnualReportService`は指定した暦年の1〜12月を`MonthlyReportService`で取得する。年間用の売上・来店SQLや集計テーブルを新設せず、各月の`MonthlyBusinessSummary`をそのまま行へ写す。新規・再診・離反・2/6/10回到達は`CustomerAnalyticsService`、スタッフ2基準の稼働は`StaffUtilizationService`、時間帯別は`TimeBandUtilizationService`の結果を合成する。年は暦年（1月〜12月）であり、事業年度は未確定のため推測しない。

月別に、決済日/施術日/選択売上、目標、進捗率、来店、ロング、次回予約人数/率、初診/初診予約、新規/再診/離反、2/6/10回到達人数/率、スタッフ稼働分・勤務分・予約可能分/率、時間帯別稼働を返す。売上基準は月計と同じ`SalesBasis`。月別金額・件数は同条件の月計と一致する。`as_of_date`より後の月は未実績表示とし、将来月の目標は残す。月計の事実合計と目標進捗を混同しない。

年間の予約率、初診予約率、2/6/10回到達率、スタッフ稼働率、時間帯別稼働率は、12か月の率の平均ではなく、各分子合計/各分母合計で計算する。分母0はNULL。目標が未設定の月を0とみなさず、`target_missing_months`を返し、年間目標と達成率はNULLとする。離反未判定の月があれば年間離反もNULLとし、既判定分と未判定月数を別に返す。実担当分・勤務分に不明があれば年間稼働率はNULLにする。

管理画面/APIは`GET /admin/reports/annual`と`/data`で、`reports.view`と`sales.view`の両方を要求する。`year`、`basis`、`as_of_date`を指定できる。既存6シートExcelの年間計画書（実数）は、このread modelを参照し、Excel内で独自の集計式を再実装しない。
