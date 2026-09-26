# Claude Cloud への引継ぎ（2026-09-26）

ローカル（Claude Code / Laravel Sail）での作業はここで終了し、以降は Claude Cloud で進める。
過去のローカルチャットを知らなくても、このリポジトリと `docs/` だけで再開できるように要点をまとめる。

## 1. リポジトリと状態

- repository: `git@github.com:0xishikawa-netizen/ark-reservation-system.git`
- branch: `main`（この文書を含むコミットが最新。コミットハッシュは `git log -1` で確認）
- Current Phase: Phase 11 — Reporting / Business Automation
- Current Task: **なし**（`docs/PLAN.md` / `docs/tasks/phase-11.md`）。次 Task は明示承認してから着手する。
- 作業ルール: `AGENTS.md`（1 Task ずつ、本番・Stripe Live・外部 API へ接続しない、画面文言は `messages.ts` / `lang/ja/messages.php`）

## 2. 完了済み（Phase 11）

- 日次 / 月計 / 年間集計 / 顧客統計（新規・再診・離反・到達率）/ スタッフ稼働率 / 時間帯別稼働率 / 日報（営業の様子・振り返り）
- 原本固定 6 シート Excel 出力（`docs/EXCEL_EXPORT.md`）。canonical template:
  `resources/report_templates/ark-jiyugaoka-2026-10-source.xlsx`（シート: 数値 / 日報 / 月計表 / 稼働率（社員）/ 稼働率（アルバイト）/ 年間計画書 (実数)）
- 過去データ取込基盤（`docs/HISTORICAL_IMPORT.md`）
- Task 11-18: Reports・ブッキングボード・設定メニューの UI/UX 統一（`docs/REPORTS_UI.md`）

## 3. 画面・UI の正本

- Reports 一覧、null 表示ルール、共通 Filter / DatePicker / Table、各画面の列構成、ブッキングボードのスタッフ表示仕様、
  設定メニュー構造、未解決 UI 課題 → **`docs/REPORTS_UI.md`**
- 業務定義 → `docs/ARCHITECTURE.md`（Reporting 節）、`docs/CUSTOMER_ANALYTICS.md`、`docs/STAFF_UTILIZATION.md`、
  `docs/TIME_BAND_UTILIZATION.md`、`docs/ANNUAL_REPORTING.md`、`docs/tasks/phase-11.md`（確定済み業務定義）
- デザイン → `docs/design/ARK_DESIGN_SYSTEM.md`（ブランド色 navy `#1A2653`）

重要な表示ルール（詳細は REPORTS_UI.md）:
- 値なしは画面上 **薄いグレーの `-`**（`EmptyValue` / `ReportValue`）。null / unknown / unsupported / not_captured / 分母0 の区別は
  データ・API で保持し、画面では aria-label / title に理由を残す。**0 は 0**。
- 日付・年月・年の入力は `DateField` / `MonthField` / `YearField` のみ（ブラウザ標準入力を増やさない）。
- ブッキングボード: 出勤予定（`staff_shifts` あり かつ 店舗カレンダーで営業）のスタッフだけ表示。休みでも予約・予定があれば
  「勤務予定外」で行を残す。判定は `AvailabilityService::workingStaffIdsByDate`（予約可能判定と同じ正本）。

## 4. 未解決事項

- **Task 11-13 BLOCKED**: 旧 Excel / Google Sheets の実績入力済み原本と、同期間の ARK 実データとの実数値照合が未実施（資料待ち）。
- **過去施術時刻の JST 問題**: 来店完了時の自動施術生成が JST 壁時計値を UTC として保存していた不具合は、新規生成分のみ修正済み。
  既存の自動生成済み施術実績は変更していない（推測 backfill なし）。実データ・原資料が揃った段階で予約時刻との照合が必要
  （`docs/PHASE11_OPERATIONAL_VERIFICATION.md`）。
- 月計の原本 K〜P 列（8% 物販の決済別内訳・予備・計）に相当するデータが ARK に無い（画面では同位置に税区分別税額を表示）。
- Google Sheets 連携: **未着手**（DEMO 含め実装しない）。SALON BOARD / Peak Manager 実 API 連携: **未着手**。
- その他の未確定事項は `docs/OPEN_QUESTIONS.md`。

## 5. Cloud で最初にやること（提案）

実物帳票サンプル（日計表・日報・月計表・顧客統計（新規統計）・スタッフ稼働率・時間帯別稼働率・年間集計・その他）を受け取ったら、
推測で項目を足さずに、次の順で「最新 ARK 画面 / Excel 出力」と比較して差分表を作る:

1. 月計表（`/admin/reports/monthly` の日別実績と Excel「月計表」シート）: 列・順序・8% 物販列の扱い
2. 日計表・日報（`/admin/reports/daily-notes` と Excel「日報」シート）
3. スタッフ稼働率（社員 / アルバイト）と時間帯別稼働率
4. 顧客統計（新規統計）: 属性の分類名・順序、未取得属性（来店目的・動機・紹介・地域）の取得方法
5. 年間集計（年間計画書 (実数)）

差分ごとに「表示だけの差」か「業務定義・データ取得の差」かを分け、後者は新 Task として承認を取ってから実装する。

## 6. ローカル環境メモ

- 実行は Laravel Sail（`./vendor/bin/sail artisan test` / `./vendor/bin/sail npm run test` / `./vendor/bin/sail npm run build` / `./vendor/bin/sail pint`）。
- 開発管理者は `DevelopmentAdminSeeder`（認証情報は `.env` の `DEV_ADMIN_*`、リポジトリには含めない）。
- `migrate:fresh` / `db:wipe` は禁止（ローカル検証データを保持するため）。
