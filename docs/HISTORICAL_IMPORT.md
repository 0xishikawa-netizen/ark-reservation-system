# Phase 11 / Task 11-12 — 過去データ移行基盤

## 対象と境界

- 原本はアップロード入力として読むだけ。変更しない。5 MiB以下のCSV（UTF-8 / Shift-JIS系）とXLSXを受け入れ、保護コピーを`local`ディスク（`storage/app/private/historical-imports`）へ保存する。Google Sheets APIには接続しない。
- dry-run `/admin/reports/historical-imports/preview` はDB・保護コピーとも変更しない。staging、report、commit、invalidate、顧客照合確認は`historical_data.import`権限と監査対象。
- 原本の詳細レイアウトが未提供のため、取込契約はARK定義の中間CSV/XLSXである。原本からこの形式への変換表は、原本入手後に別途確定する。元のExcelのセル位置やGoogle Sheetsの列を推測しない。

## 中間形式

各シートの1行目に一意な列名を置く。全行を`historical_import_rows`、全非空セルを`historical_import_cells`へ出典付きでstagingする。元ファイルhash、sheet、行番号、セル座標、行/セルHMAC、暗号化原文、source identifier、validation status/errors、imported_atを保持する。原文とsource identifierは暗号化し、公開APIの検証報告には値を返さない。

集計値を本登録する行は`record_type=historical_aggregate`とし、`metric_code,period_start,period_end,value`を必須にする。期間は`YYYY-MM-DD`の両端を明示し、値は整数とする。許可するmetric codeはServiceの`METRICS`に限定する。`legacy_treatment_count`は過去の「施術数」を消さず保持するための別指標で、新しい来店数へ自動変換しない。

顧客明細候補は`record_type=customer_detail`とし、`customer_id`、`member_no`、`phone`の順に照合候補を提示する。電話は既存の正規化HMACで単一一致した場合のみ候補とする。名前は照合に使わない。管理者は既存顧客IDを明示して照合結果を確認でき、確認者と日時を保存する。ただし原本項目と権利・来店・会計の対応規則が未確定なので、顧客明細はVisit、Checkout、Customerその他の業務事実へ自動登録しない。

## 状態と再実行

`preview` → `stage`（validated / invalid / needs_review）→ `commit`（imported / partial）→ 必要なら`invalidate`。同一ファイルSHA-256のstageは既存batchを返し、同一行のcommitは`source_row_id`の一意制約で冪等。部分失敗ではvalidな過去集計値だけをtransactionで登録し、invalid/needs_review行を残す。再度commitしても登録済み行は増えない。invalidateは削除せず、登録済み集計値に`invalidated_at`を付けて照合対象から除外可能にする。元ファイルも削除しない。

本登録先`historical_metric_values`は既存のVisit/Checkout/Reporting事実と完全に分離する。明細のない月計から架空のVisit/Checkoutを作らない。既存ARK Reportingへ過去集計を暗黙加算しない。比較はTask 11-13のreconciliationで、同じmetric/期間を指定して行う。

## 入力安全性と未確定事項

上限は5 MiB、各シート5,000行・50列、XLSX展開後50 MB・500ファイル。XLSXの式セルは実行も取込もせず拒否する。CSV/XLSXの式らしい文字列は検証エラーにする。暗号化済みの原文と保護コピーについては法定・業務上の保持期間が未確定なので自動pruneしない。削除方針は承認後に定める。

原本サンプル、入力列の実対応、顧客明細の業務意味、保持期限、実データ照合結果は外部資料待ちである。これらを推測して本登録しない。

## Task 11-26 — 旧帳票ブックの変換mapping

実物サンプル（`docs/handoff/2026-09-26-sample-comparison.md`）の確認後、旧ブックを上記中間形式へ変換する読取専用コマンドを追加した。DBへは書かず、出力CSVを従来どおり管理画面でpreview → stage → 手動確認 → commitする。

```
php artisan ark:legacy-import:convert {kind} {path.xlsx} --output=out.csv \
  [--fiscal-year=2023] [--staff-label=A] [--course-mapping=mapping.json]
```

- 旧ブックは`PhpSpreadsheet`で開くだけで保存しない。**数式は実行しない**。式セルはExcelが保存した計算済みキャッシュ（`getOldCalculatedValue`）だけを読み、旧式の結果（率・合計）は取込値にせず`legacy_reported`（比較用）へ分離する。ARK側の値は入力セルから再集計する。
- 出力CSVには氏名・フリガナ・電話・番地・建物名・紹介者名を含めない。地域は都道府県と市区町村（区・市・町・村まで）だけ、紹介者は`has_referrer`の有無だけ持つ。旧顧客番号は暗号化されるsource identifierとしてだけ保持し、名前で既存顧客へ統合しない。
- 明細行（`customer_detail` / `legacy_visit_detail` / `legacy_attendance_detail`）は常に`needs_review`でstageし、Visit・Checkout・Customerへ自動登録しない。本登録するのは`historical_aggregate`だけ。
- `historical_metric_values.dimension`（追加型migration、NULL可）に内訳キー（`channel:hotpepper`、`staff_slot:1`、`band:10_12|weekday`、`method:airpay`、`sheet:R8.9`等）を保存する。dimension付きの値は月合計と比較せず、照合APIでは`not_comparable`とする。

### 種別ごとの対応

| kind | 旧資料 | 取込む集計（metric_code） | 手動確認・警告 |
|---|---|---|---|
| `customer_list` | 顧客データ一覧 / 新規統計 | 来店日月別の`new_customers`・`reached_2/6/10`、来店動機・来店目的・性別・年代・初回担当・地域別の内訳（dimension付き） | 日付として読めない来店日、ARKマスタと完全一致しない来店目的、未知の来店動機、コース名（対応表がない限り全件）。新規統計シートの旧値は`legacy_reported`へ（F1/F2/F3の影響を受けるため取込まない） |
| `staff_utilization` | 稼働率（年度ブック、`--fiscal-year`必須） | 枠別`staff_occupied_minutes`・`staff_working_minutes`・`staff_patient_count`・`staff_reservation_count`・`staff_nomination_count`（`staff_slot:n`） | `#REF!`/`#DIV/0!`枠と空枠はskip（F7）。旧稼働率は比較用のみ |
| `time_band` | 時間帯別稼働率 | 帯×平日/土日の`band_occupied_minutes`・`band_capacity_minutes`・`band_visit_count` | 入力欄の手計算式（`=15+90+60`）は保存済み結果値を使いwarning（F8）。旧月率は日率平均なので比較用のみ |
| `attendance` | 出勤簿（`--staff-label`必須） | `attendance_working_minutes`・`attendance_break_minutes`・`attendance_days` | 休憩欄が空の日は労働時間未確定として集計から除外（F9、0分扱いしない） |
| `daily_ledger` | 日計表 / 分析 | 月別`visit_count`・`long_visit_count`、施術/物販の支払方法別金額、分析シートの`net_sales` | コース名（対応表なし）、未知の支払方法、分析シートの参照列が他月と異なる月（F6） |
| `monthly_sheet` | 月計表 | 日別入力欄がある場合のみ | テンプレートのみ（実績入力0）の場合は0件と明示し、数式キャッシュ0を実績にしない |

担当欄の`奨(指)`は指名あり、メニュー欄`T45, M15`は分数合計（施術時間）とし、ロングは施術時間60分超で再計算する（ARK日計のロング定義と同じ）。

### コース対応JSON

旧コース名はARKコースへ**明示した対応だけ**変換し、名称の類推はしない。

```json
{ "8回券60分": "ticket:3", "月額45分4回券": "membership:1", "一般60分": "menu:12" }
```

値は`ticket:{ticket_product_id}` / `membership:{membership_plan_id}` / `menu:{menu_id}`。対応表にない名称はコード化せず手動確認一覧へ件数付きで出す。対応表の内容は業務承認が必要（Q13）。

### サンプルdry-run結果（2026-09-27、件数のみ）

| kind | aggregate | manual_review | skipped | warning | 主な手動確認 |
|---|---:|---:|---:|---:|---|
| customer_list | 663 | 1194 | 0 | 49 | 来店日不明49、コース名6種（全件）、完全一致しない来店目的 |
| staff_utilization（FY2023） | 240 | 0 | 55 | 0 | 旧値96件すべて再集計値と一致 |
| time_band | 144 | 0 | 0 | 46 | 手計算式46セル。旧月率（日率平均）32件は比較用 |
| attendance（A） | 69 | 541 | 1 | 4 | 休憩欄空の4日を除外 |
| daily_ledger | 70 | 543 | 2 | 2 | コース名29種、分析シートR6.10/R6.11の列揺れ |
| monthly_sheet（2026.10） | 0 | 0 | 0 | 0 | 実績入力なし |

サンプルExcelと変換後CSVはリポジトリへ含めない。本登録（commit）はローカル検証DBでも行っていない。
