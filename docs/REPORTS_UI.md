# Reports・ブッキングボード・管理ナビの UI ルール（Task 11-18）

Reports 系画面と関連管理画面の表示ルールの正本。新しい集計画面を足す時は、ここの共通部品を使い、
画面ごとに入力欄サイズ・DatePicker・表 CSS を作らないこと。業務定義（集計値・率・NULL の意味）は
`docs/ARCHITECTURE.md` / 各 Reporting 文書が正本で、本書は「どう見せるか」だけを扱う。

## 1. Reports 一覧（管理画面「集計」メニュー）

| 画面 | route | 権限 | Vue |
|---|---|---|---|
| 日報 | `/admin/reports/daily-notes` | reports.view（編集は reports.manage） | `Pages/Admin/Reports/DailyNotes.vue` |
| 月計 | `/admin/reports/monthly` | reports.view + sales.view | `Pages/Admin/Reports/Monthly.vue` |
| 年間集計 | `/admin/reports/annual` | reports.view + sales.view | `Pages/Admin/Reports/Annual.vue` |
| 顧客統計 | `/admin/reports/customers` | reports.view | `Pages/Admin/Reports/Customers.vue` |
| スタッフ稼働率 | `/admin/reports/staff-utilization` | reports.view | `Pages/Admin/Reports/StaffUtilization.vue` |
| 時間帯別稼働率 | `/admin/reports/time-bands` | reports.view | `Pages/Admin/Reports/TimeBands.vue` |
| スタッフ売上（Task 11-22） | `/admin/reports/staff-sales` | reports.view + sales.view | `Pages/Admin/Reports/StaffSales.vue` |
| コース別売上（Task 11-25） | `/admin/reports/course-sales` | reports.view + sales.view（目標編集は settings.manage） | `Pages/Admin/Reports/CourseSales.vue` |

メニュー定義は `resources/js/layouts/reportNavigation.ts`。原本固定 6 シート Excel（`docs/EXCEL_EXPORT.md`）は
月計画面の「月計表をダウンロード」ボタン（reports.export 権限・決済日基準のみ）から出力する。

### 来店・会計入力（Task 11-19）

`/admin/checkouts`（ナビ「支払い」グループ「来店・会計」、`checkouts.manage`）。日付は `DateField`、一覧は `ReportTable`、
金額は `ReportValue`、値なしは `EmptyValue`。入力画面は左に施術・明細、右に会計サマリーと支払。規則は `docs/CHECKOUT_ENTRY.md`。

## 2. 値なし（NULL 等）の表示ルール

- 画面上は **薄いグレーの `-`** に統一する（`resources/js/components/ark/EmptyValue.vue`、文言は `MESSAGES.common.emptyValue`）。
- 元の意味（算出不可 / 未設定 / 未実績 / 未取得 / 勤務予定なし / 現行DBで未取得 / 該当なし）は
  `aria-label` と `title`（ホバー表示）に残す。データや API の null / unknown / unsupported / not_captured /
  分母0 の区別は一切変えていない。
- **0 は実績値なので `0` / `0円` / `0.0%` と表示する。ハイフンにしない。**
- Reports の数値セルは `ReportValue`（`resources/js/components/reports/ReportValue.vue`）を使う。
  `value` が null/undefined なら `EmptyValue`、`hidden`（未来日など）なら値があっても `-`。
  書式は `formatReportValue`（count / money / percent / decimal / minutes）に集約。
- 未来日・未来月（as_of_date より後）は「未実績」理由付きの `-`。年間集計の目標額だけは未来月でも表示する。
- 関連管理画面（商品・メニュー・スタッフ一覧、予約詳細、業務マスタ、外部予約連携）の「未設定」「―」表示も
  `EmptyValue` に置き換えた。2FA 状態など「状態ラベル」としての「未設定」は対象外（値ではないため）。

## 3. 表示条件（Filter）

- 共通部品: `ReportFilterBar`（白カード・見出し「表示条件」・右上に基準日などの meta・読み込み中／エラー表示）と
  `ReportFilterField`（幅 3 段階: `sm` 168px=年・区分、`md` 216px=年月・日付・基準、`lg` 256px=名前選択や
  クリアボタン付き日付）。狭い画面（〜599px）では各項目が全幅で折り返す。
- 入力欄は全て Vuetify outlined + `density="compact"`（高さ40px）・ラベル内包で揃える。セレクトは `ReportSelect`。
- 画面ごとの条件:
  - 日報: 対象月
  - 月計: 対象月 / 売上基準（決済日・施術日）/ meta 基準日
  - 年間集計: 対象年 / 売上基準 / 基準日（空=既定。選べる範囲は前年末日〜対象年末日）
  - 顧客統計: 対象月 / 観察基準日 / meta 対象cohort・観察基準日
  - スタッフ稼働率: 対象月 / 雇用区分 / スタッフ / meta 主担当不明件数・基準日
  - 時間帯別稼働率: 対象月 / スタッフ / meta 対象時間帯外の実績分・基準日

## 4. 日付・年月・年の選択（DatePicker）

同じ用途で複数実装を残さない。ブラウザ標準の `type="date" / "month" / "number"`（年）は Reports と関連管理画面から撤去した。

| 用途 | 部品 | 中身 |
|---|---|---|
| 日付 | `components/ark/DateField.vue` | `ArkCalendar` のポップアップ |
| 年月 | `components/ark/MonthField.vue` | `ArkMonthCalendar`（Task 11-17） |
| 年 | `components/ark/YearField.vue` | 同じ見た目の12年グリッド |

置換済み: 年間集計（年・基準日）、顧客統計（観察基準日）、業務マスタ（税率の開始日・終了日、店舗カレンダーの営業日）、
勤務枠の実勤怠（営業日）。未置換: 顧客編集の生年月日・顧客マイページ（年を大きく遡る入力のため、年ジャンプ付き
カレンダーが必要。§8 残課題）、実勤怠の出退勤・休憩（`datetime-local`、日時入力の共通部品がまだ無い）。

## 5. 表（ReportTable）

`components/reports/ReportTable.vue` が枠とクラスを提供し、各画面は thead/tbody/tfoot だけを書く。

- 見出しは `thead` ごと上に固定（2 段のグループ見出し `tr.group-row` もずれない）、合計行 `tfoot` は下に固定。
- `is-sticky` / `is-sticky-2` で左の日付・スタッフ列を固定（幅は `sticky-width`）。
- `num` = 右寄せ・等幅数字、`group-start` = 列グループの区切り線、縞・hover、`row-muted`（未来・非稼働）、
  `row-alert`（休業日）、`row-group-start`（日付／スタッフのまとまりの区切り）、`empty-cell`（空表示）。
- 縦スクロールは `max-height`（既定 70vh）。ページ全体の縦スクロールと取り合う小さい表は `max-height="none"`。
- KPI は `ReportKpi` + `.report-kpi-grid`（小さめの白カード、主要1枚だけ `emphasis`）。

## 6. 各画面の構成

- **月計**: 右上に「顧客統計を見る」（outlined）と「月計表をダウンロード」（primary・Excelアイコン。施術日基準では
  無効化し、決済日基準のみ対応の注記）。KPI 8枚。日別実績は原本「月計表」の並び:
  日・曜 → 決済手段別売上（決済手段マスタの表示順＝現金・PayPay・AirPAY・Square・スマート払い・商品券・iD…、
  最後に「計」＝決済日売上）→ 税率別 税額 → 売上金（選択した売上基準）→ 来店（来店数・ロング・予約・予約率）
  → 初診（初診数・初診予約・初診予約率）→ 施術分類（M・T・A・M&T・A&T・分類不明）。
  値は全て既存 MonthlyReportService の値で、画面側で新しい集計はしない。原本 K〜P 列（8% 物販の決済別内訳）は
  ARK の read model に存在しないため、同じ位置に既存の「税率別 税額」を置いている（§8）。画面には税区分マスタの名前を出さず、税率ごとに「10%対象」のように合算表示する。決済手段の見出しは原本Excelの表記（エアペイ・スクエア・スマート払・ID等）に合わせる。
  決済列の並び用に `MonthlyReportController` が `paymentMethodColumns`（有効な決済手段マスタの id/code/name）を渡す。
- **顧客統計**: 右上「月計に戻る」ボタン。KPI 6枚（新規・再診・離反・2/6/10回目到達率＋分子/分母）。
  新規患者内訳をカードに分割: 基本属性（性別・年代・初回来店時年齢）/ 到達状況 / 初回来店（コース・初回担当・
  次回予約・来店目的）/ 集客経路（来店動機・紹介者）/ 地域（都道府県・市区町村）。各属性は件数＋相対バー。
  not_captured の属性は `-`（理由: 現行DBで未取得）。
- **年間集計**: 列を 売上 / 来店・予約 / 初診 / 顧客 / 到達 / 稼働率 のグループ見出しにまとめた。
- **スタッフ稼働率**: 月合計と日別を同じ列グループ（実績 / 予約・指名 / 稼働率）で表示。日別には表示だけの絞り込み
  （`ReportDailyToolbar` + `useDailyFilter`）: スタッフチップ（全員 ↔ 1人）、日付、「勤務・実績ありのみ」、
  並び順「日付ごと / スタッフごと」。1人表示ではスタッフ列を省く。上部の表示条件（サーバー再集計）とは独立。
  出勤mの横に「実勤怠 / 予定代用」の小タグ。出勤mなしの理由（勤務予定なし / 未取得）は `-` の title。
- **時間帯別稼働率**: 全体（時間帯ごと）/ スタッフ別（月合計、スタッフでまとめて時間帯を並べる）/ 日別
  （日付×スタッフでまとめ、スタッフ・日付・実績ありで絞り込み）。
- **日報**: 共通 FilterBar と共通ボタン（保存）。

## 7. ブッキングボード

- **スタッフ行の表示ルール**（`app/Queries/ScheduleQuery.php` の `visibleStaff`）:
  - 通常は「対象期間（日表示は当日、週表示は週内のいずれか）に出勤予定」のスタッフだけ行を出す。
    判定は予約可能判定と同じ正本 `AvailabilityService::workingStaffIdsByDate`
    （勤務枠 `staff_shifts` が1つ以上 **かつ** 店舗カレンダーが休業日・営業時間なしでない）。通常シフトの自動生成・
    特別勤務はどちらも `staff_shifts` に入るため区別不要。
  - 休みでも期間内に予約・予定ブロックがあるスタッフは **行を残して「勤務予定外」バッジ** を出す（予約を見失わない）。
    予約受付を止めた（is_bookable=false）スタッフでも予約・予定があれば同様に残す。データは変更しない。
  - スタッフで明示的に絞り込んだ場合は休みでも行を出す。
  - 隠したスタッフはツールバーに「休み N名」（ホバーで氏名）。出勤者が0人なら勤務枠・休業日の確認を促す。
- **行の高さ**（`components/admin/scheduleLayout.ts`）: 画面下端までの空きを実測し、行数で割った高さを
  最小 68px〜上限（1〜3行 152px / 4〜6行 112px / 7行以上 88px）に収める。空きが足りなければ 68px＋ページの縦スクロール。
  狭い画面（〜1023px）は 68px 固定。見出し行（両方表示）は 28px 固定。
- **左パネル**: 本日の集計の位置に合わせず、`calc(100dvh - パネル上端 - 24px)` で画面の使える最下部まで伸ばす
  （上端は折り返しで変わるため実測。最小 420px）。中身はパネル内スクロール。

## 8. 設定メニュー（ヘッダー）

定義は `resources/js/layouts/settingsNavigation.ts`。第1階層はカテゴリのみ（1項目のカテゴリもネスト）、全行アイコン付き・
同じ高さ（36px）・余白・hover・右矢印。route と権限条件は従来どおり。

- 店舗設定: スタッフ / メニュー / 商品 / 業務マスタ / ブース / 勤務枠
- 予約設定: 予約ポリシー / 通知設定
- 回数券・月額: 回数券商品 / 回数券運用設定 / 月額プラン
- 権限管理: ロール権限

システム状態・失敗ジョブ・監査ログ・外部予約連携は従来どおりヘッダーの「システム」メニュー。

## 9. 未解決の UI 課題

- 月計の原本 K〜P 列（8%物販の決済別・予備・計）に相当するデータは ARK に無い。実物帳票との比較後に扱いを決める。
- 生年月日などの長期間の日付入力はブラウザ標準のまま（年ジャンプ付きカレンダーが必要）。日時入力（実勤怠）も同様。
- 顧客統計の属性バーは属性内の最大件数に対する相対表示で、割合（%）は出していない（新しい指標を増やさないため）。
- ブッキングボードの日表示で行が高い日、予約カードは上寄せで下に余白が出る。カード内の情報追加は実運用を見て判断。
- 実物帳票（日計表・日報・月計表・顧客統計・稼働率・時間帯別・年間）との項目・順序の突合は未実施（Cloud 側で実施予定）。

月計の日別表は枠内で縦スクロールさせず（`max-height="none"`）、31日分をページのスクロールで見る。列数が多いため、横方向だけは表の枠内でスクロールする（日付列は固定）。
