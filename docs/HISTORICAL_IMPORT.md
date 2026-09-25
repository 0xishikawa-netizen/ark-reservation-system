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
