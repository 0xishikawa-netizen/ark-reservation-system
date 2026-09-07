# AGENTS.md — ARK 予約・決済システム（Codex 向け指示）

あなた（Codex）は本リポジトリの **実装担当**。設計・レビュー・進行は Claude Code が担当する。

## 毎回読むもの（作業前に必ず）

1. この `AGENTS.md`
2. `docs/PLAN.md`（承認済み設計。rev.5）
3. `docs/tasks/phase-00.md`（現在のタスク一覧と受け入れ条件）
4. 必要に応じ `docs/ARCHITECTURE.md` `docs/DB_SCHEMA.md`

## 作業ルール

- **指示された Task 1 つだけ**を実装する。先の Task に手を出さない。
- Task の「受け入れ条件」をすべて満たすことをゴールにする。満たせない場合は理由を最後に明記して止まる。
- 変更は**このリポジトリ配下のみ**。リポジトリ外（`../ark-system-proposal`, `../vela`, WordPress, その他）には一切触れない。
- 既存ファイルを壊さない。特に以下は**上書き・削除しない**（内容統合が必要なら追記/マージする）:
  - `.gitignore` / `.env.example`
  - `config/reservation.php` / `config/retention.php` / `config/stripe.php`
  - `docs/**` / `AGENTS.md` / `.github/workflows/ci.yml`
- コミットはしない（`git add` / `git commit` しない）。差分はワークツリーに残す。Claude Code が `git diff` でレビューする。
- 実装が終わったら、最後に「変更ファイル一覧・実行したコマンドと結果・受け入れ条件の充足状況・残課題」を短くまとめる。

## 絶対禁止

- 本番 WordPress / 本番 DB / 本番 `ark-conditioning.com` への接続・変更
- Stripe **Live** キーの使用・記述（`sk_live_` / `pk_live_` / `whsec_`(test 以外)）
- Peak Manager 実 API / SALON BOARD 実 API への接続
- DNS 変更・サーバー設定変更・OS 設定変更
- パッケージのグローバルインストール（`npm i -g`, `composer global`, `brew` 等）
- Git remote への push
- リポジトリ外への秘密情報送信
- **Phase 1 以降の実装**（ドメインモデル・予約ロジック・Stripe 決済・回数券/利用権・予約台帳など）。Phase 0 は「動く土台」まで。

## 環境

- ローカルに PHP / Composer / MySQL は入っていない。**Docker / Laravel Sail** を使う。
- Docker イメージは「通常のローカル開発に必要な公式イメージ」に限定
  （例：`composer:2`, `mysql:8.4`, `laravelsail/php83-composer`, `axllent/mailpit` 等 Sail 標準構成）。
- Composer / npm でのプロジェクト依存の取得は可（ネットワーク使用可）。
- 生成物の PHP 実行・artisan・テストはすべて Sail 経由（`./vendor/bin/sail ...`）で行う。

## 技術スタック（`docs/PLAN.md` §2 で確定）

- Laravel 13（PHP 8.3+）/ モジュラーモノリス
- Inertia.js（SPA+API 完全分離にはしない）
- Vue 3 + TypeScript + Vuetify 3
- DB: MySQL（Sail は当面 `mysql:8.4`。実バージョンは後日サーバー実測で確定）
- 認証: Laravel 標準 + spatie/laravel-permission（web guard 1 つ）
- 決済: Stripe + laravel/cashier（**Phase 1 以降**。Phase 0 では導入しない）

## コーディング規約

- `declare(strict_types=1);` を PHP ファイル先頭に付ける。
- フロントは TypeScript。`any` を避ける。`npm run build` の型エラーは 0。
- Laravel Pint / ESLint に従える構成にする（設定は生成物のデフォルト＋最小限）。
- コメントは必要な箇所に日本語で簡潔に。
