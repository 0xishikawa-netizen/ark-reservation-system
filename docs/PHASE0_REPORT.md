# PHASE 0 レポート

対象: `/Users/ishikawatatsuya/developer/ark-reservation-system`（新規・独立リポジトリ）
初版: 2026-09-07 ／ 最終更新: 2026-09-08

> 設計の Source of Truth は **`docs/PLAN.md`（rev.5）**。Task 進行ログは `docs/tasks/phase-00.md`。
> 本ファイルは Phase 0 の結果サマリ。

---

## ステータス: ✅ Phase 0（ローカル）完了 — 2026-09-08

`docs/tasks/phase-00.md` の Task 0-1〜0-5 をすべて完了。Phase 0 完成条件を充足。

### 現在の構成（動作確認済み）

| 項目 | 値 |
|---|---|
| Laravel Framework | 13.30.1 |
| PHP | 8.3.33（Sail runtime 8.3、`composer` platform pin 8.3.7） |
| フロント | Inertia.js v2.0.26 + Vue 3.5.42 + TypeScript 5.9.3 |
| UI | Vuetify 3.13.3（最小構成・手動 components/directives 登録） |
| ビルド | Vite 8.2.2 / @vitejs/plugin-vue 6.0.8 / laravel-vite-plugin 3.2.0 / vue-tsc 3.3.11 |
| DB（ローカル/CI） | MySQL 8.4（Sail 既定）※本番の実バージョンは未確定（下記） |
| メール（ローカル） | Mailpit |
| 実行環境 | Docker Desktop + Laravel Sail（`compose.yaml`） |

### 検証結果（2026-09-08）

- `sail npm run build`：`vue-tsc --noEmit` 型エラー **0** → vite build 成功
  （`app-*.js` ≈797kB / `app-*.css` ≈509kB。>500kB 警告あり＝Vuetify 手動一括登録による。技術課題として継続）
- `sail artisan test`：**3 passed / 12 assertions**（ExampleTest×2 + `tests/Feature/WelcomePageTest.php`×1）
- `curl http://localhost`：**200**、Inertia 初期ペイロードに `component:"Welcome"`
- `docker compose config -q`：妥当／`composer validate`：valid／`composer audit`・`npm audit`：脆弱性 0
- 秘密情報スキャン（実キー形式・AWS・PEM・Slack token）：**混入なし**
- `.env` は `.gitignore` 対象。**コミット 0 件**（`git add`/`commit`/`push` していない。staging も 2026-09-08 に整理済み）
- リポジトリ外：`vela` 本体 HEAD 不変、`vela/ark-system-proposal/` 無変更、WordPress 無関係

### 作成物

- `docs/`：`PLAN.md`（設計正本）/ `ARCHITECTURE.md` / `DB_SCHEMA.md` / `OPEN_QUESTIONS.md` /
  `DEPLOYMENT.md` / `OPERATIONS.md` / `TROUBLESHOOTING.md` / `PHASE0_REPORT.md` / `tasks/phase-00.md`
- `config/`：`reservation.php`（SoR 切替・gateway バインド設定）/ `retention.php` / `stripe.php` + Laravel 標準 config
- `.env.example`（ARK 独自キー + Laravel 標準キー網羅）
- `.github/workflows/ci.yml`（秘密情報・WP DB 混入ガード付き。実走は git remote 接続後）
- `Makefile` / `tools/server-probe.php` / `tools/server-probe.sh`
- Laravel 実アプリ一式（`app/` `bootstrap/` `routes/` `resources/` `tests/` `database/` 等）
- Codex 連携：`AGENTS.md`（Codex 用ルール）

---

## 未完了 — Phase 0 の残タスク（Phase 1 と並行で進む・別担当）

### A. お名前.com レンタルサーバー実測（ユーザー作業）

このセッションから対象サーバーへ SSH/接続情報がなく実測不可（Auto Mode でも接続禁止）。
`tools/server-probe.php` または `tools/server-probe.sh` を**サーバー上で実行**し、下表を埋めて確定する。

| 項目 | 結果 | 判定への影響 |
|---|---|---|
| PHP バージョン（8.3+ か） | — | 8.3 未満なら共有継続不可 |
| 必須拡張（pdo_mysql, mbstring, openssl, bcmath, intl, gd, zip, curl, tokenizer, xml, ctype, fileinfo） | — | 欠落があれば不可 |
| Composer / SSH 可否 | — | 不可なら vendor 同梱運用 |
| cron 最小間隔（1 分可か） | — | 5 分以上なら仮予約失効の設計を緩和 |
| mod_rewrite / AllowOverride | — | 不可なら Laravel ルーティング困難 |
| memory_limit / max_execution_time | — | 低すぎると不可 |
| supervisor 常駐可否 | — | 不可なら cron 毎分 `queue:work --stop-when-empty` |
| **MySQL バージョン** | — | Sail / CI のイメージを合わせる |
| mysqldump 可否 | — | バックアップ方式に影響 |

### B. 共有レンタルサーバー継続可否の判断（暫定：VPS 推奨）

**暫定結論：Staging / Production は VPS 移行を推奨。ローカル開発は影響なし。**
根拠：常駐 Queue worker + 毎分 `schedule:run`（仮予約失効・Webhook 確実処理・同期 retry）が
安全性要件の前提で、典型的な共有レンタルサーバーと相性が悪い。1 人保守の切り分けやすさも VPS が有利。

**共有継続を再検討する条件**：A の実測が「PHP 8.3+ ＆ 必須拡張すべて有 ＆ Composer 実行可 ＆ cron 1 分可 ＆
mysqldump 可 ＆ supervisor 可」を**すべて満たす**場合のみ。1 つでも欠ければ VPS で確定。
`docs/DEPLOYMENT.md §6` に共有継続時の運用手順を用意済み。

### C. その他ユーザー確認事項（`docs/OPEN_QUESTIONS.md`）

- #11 サブドメイン / DNS / SSL（`member.ark-conditioning.com` を別 docroot・VPS へ向けられるか）
- #3 決済・契約データの法定保存年数（会計士確認）
- CI の実走（git remote 接続後）

---

## 履歴（初版 2026-09-07・参考）

初版作成時点では Docker デーモンが停止しており Laravel 本体は未生成だった。
その後 `docker desktop stop` → `docker desktop start`（CLI）でデーモンを復旧し、
`composer create-project laravel/laravel:^13.0`（`composer:2` イメージ）→ 保護ファイル温存マージ →
`laravel/sail` 導入 → `sail:install --php=8.3` → `sail up` → `migrate` → `test` で Task 0-1 を完了。
以降 Task 0-2〜0-5 を Codex 実装 + Claude Code 検証で完了。詳細は `docs/tasks/phase-00.md`。
