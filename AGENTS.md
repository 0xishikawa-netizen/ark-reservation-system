# AGENTS.md — ARK 予約・決済システム（Codex 向け指示）

あなた（Codex）は本リポジトリの **設計・実装・自己レビュー担当**。各 Task の計画、実装、検証、進行管理を一貫して行う。

## 毎回読むもの（作業前に必ず）

1. この `AGENTS.md`
2. `docs/PLAN.md`（承認済み設計と Current Phase / Current Task）
3. `docs/PLAN.md` が Current とする `docs/tasks/phase-XX.md`（Task の状態と受け入れ条件）
4. 必要に応じ `docs/ARCHITECTURE.md` `docs/DB_SCHEMA.md` および当該 Task から参照される資料

## 作業ルール

- **指示された Task 1 つだけ**を実装する。先の Task に手を出さない。
- 実装可能なのは、`docs/PLAN.md` で **Current Phase** とされ、対応する `docs/tasks/phase-XX.md` で **CURRENT / APPROVED** と明記された Task だけとする。両者が不一致、未承認、または複数 Current の場合は実装せず停止して報告する。
- Task の「受け入れ条件」をすべて満たすことをゴールにする。満たせない場合は理由を最後に明記して止まる。
- 変更は**このリポジトリ配下のみ**。リポジトリ外（`../ark-system-proposal`, `../vela`, WordPress, その他）には一切触れない。
- 既存ファイルを壊さない。特に以下は**上書き・削除しない**（内容統合が必要なら追記/マージする）:
  - `.gitignore` / `.env.example`
  - `config/reservation.php` / `config/retention.php` / `config/stripe.php`
  - `docs/**` / `AGENTS.md` / `.github/workflows/ci.yml`
- コミットはしない（`git add` / `git commit` しない）。差分はワークツリーに残し、Codex 自身が `git diff` で自己レビューする。
- アプリケーションコード、依存関係、DB schema のいずれかを変更した Task では、当該 Task の受け入れ条件に従って regression、`./vendor/bin/sail artisan test`、`./vendor/bin/sail npm run build` を確認する。ドキュメント限定 Task では静的検証を行い、build / test を省略した理由を報告する。
- 実装が終わったら、最後に「変更ファイル一覧・実行したコマンドと結果・受け入れ条件の充足状況・残課題」を短くまとめる。

## 絶対禁止

- 本番 WordPress / 本番 DB / 本番 `ark-conditioning.com` への接続・変更
- Stripe **Live** キーの使用・記述（`sk_live_` / `pk_live_` / `whsec_`(test 以外)）
- Peak Manager 実 API / SALON BOARD 実 API への接続
- DNS 変更・サーバー設定変更・OS 設定変更
- パッケージのグローバルインストール（`npm i -g`, `composer global`, `brew` 等）
- Git remote への push
- リポジトリ外への秘密情報送信
- `docs/PLAN.md` と対応する Task 文書で Current / Approved になっていない Phase・Task の先行実装

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
- 決済: Stripe + laravel/cashier（課金契約のみ）

## コーディング規約

- `declare(strict_types=1);` を PHP ファイル先頭に付ける。
- フロントは TypeScript。`any` を避ける。`npm run build` の型エラーは 0。
- Laravel Pint / ESLint に従える構成にする（設定は生成物のデフォルト＋最小限）。
- コメントは必要な箇所に日本語で簡潔に。
- 画面に出すメッセージ（エラー・完了・確認・注意・空の時の表示）は直書きしない。サーバーは `lang/ja/messages.php`（`__('messages.<グループ>.<キー>')`）、画面は `resources/js/constants/messages.ts`（`MESSAGES.<グループ>.<キー>`）に追加して参照する。

## 文言・共通処理の置き場所（Task 11-34 追記）

- 画面の文言は `resources/js/constants/messages.ts` と領域別の `resources/js/constants/messages/{board,masters,reports,customer}.ts`（`MESSAGES.boardUi` / `mastersUi` / `reportsUi` / `customerUi`）。既存キーに同じ文言があれば再利用する。
- 差し込み値のある文言は `{名前}` で書き、`fillMessage()`（`resources/js/utils/message.ts`）で埋める。`String.replace('{x}', 値)` は値の `$&` 等で表示が崩れるため使わない。
- 日時・金額などの整形は `resources/js/utils/{dateFormat,money,numberFormat}.ts`、マスタの有効/無効切替は `composables/masterActive.ts` を使う。画面ごとに同じ関数を書き直さない。
- 予約の FormRequest は `App\Http\Requests\Concerns\ValidatesReservationInput`、ログイン利用者の取得は `App\Http\Controllers\Concerns\ResolvesAuthenticatedUser`、予約・予定ブロックの同時作成の直列化は `App\Domain\Schedule\ResourceLock` を使う。
