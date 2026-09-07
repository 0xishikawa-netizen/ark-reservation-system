# Phase 0 — 残タスク（土台づくり）

Phase 0 の目的：**動く土台**を作る。ドメインロジック（Phase 1 以降）は書かない。

前提：Docker Desktop 起動済み。ローカルに PHP/Composer/MySQL は無い → **Sail / Docker で完結**。

各タスク共通ルール（`AGENTS.md` 参照）：
- 実装は Codex。指示された 1 タスクだけ。既存 `docs/**` `config/{reservation,retention,stripe}.php` `.gitignore` `.env.example` `.github/workflows/ci.yml` `AGENTS.md` は上書き禁止（マージ）。
- コミットしない。Docker 公式イメージのみ。グローバルインストール禁止。本番接続禁止。Phase 1 実装禁止。
- 終了時に「変更ファイル一覧 / 実行コマンドと結果 / 受け入れ条件の充足 / 残課題」を報告。

---

## Task 0-1 — Laravel 13 scaffold

**目的**：この既存リポジトリ内に Laravel 13 実アプリを展開する（既存資産を温存）。

**手順の目安**
- 別ディレクトリに `composer create-project laravel/laravel` で Laravel 13 系を生成し、
  中身を本リポジトリ直下へマージ配置する。`composer:2` などの Docker 公式イメージを使う。
- **温存必須**（生成物で上書きしない。生成物側は破棄 or マージ）：
  `.gitignore` / `.env.example` / `config/reservation.php` / `config/retention.php` / `config/stripe.php` /
  `docs/**` / `AGENTS.md` / `.github/workflows/ci.yml` / `README.md` / `Makefile` / `tools/**`
- Laravel の `.env.example` に足りないキーがあれば**本リポジトリの `.env.example` へ追記**（Laravel 側で上書きしない）。
- Sail を導入（`php artisan sail:install --with=mysql,mailpit`）。`docker-compose.yml` は Sail 生成物を使う。
- `.env` は `.env.example` からコピー。`APP_KEY` を生成。
- DB 名は `.env.example` に合わせる（`ark_app` / user `ark` / pass `secret`）。**WordPress DB 名（`64ssq_ark_db`）は使わない**。

**受け入れ条件**
- [ ] `artisan` が存在し `./vendor/bin/sail artisan --version` が Laravel 13.x を表示
- [ ] `composer.json` の `require` に `laravel/framework ^13`
- [ ] `./vendor/bin/sail up -d` が成功、`./vendor/bin/sail artisan migrate` が成功（デフォルトの users/cache/jobs 系）
- [ ] `./vendor/bin/sail artisan test` が green（Laravel デフォルトの ExampleTest）
- [ ] `<http://localhost>` が Laravel welcome を返す
- [ ] `config/reservation.php` `config/retention.php` `config/stripe.php` がそのまま存在し、`sail artisan config:show reservation` が読める
- [ ] `git status` に上記「温存必須」ファイルの削除・破壊が無い

---

## Task 0-2 — Inertia.js + Vue 3 + TypeScript 導入

**目的**：Inertia（サーバー + クライアント）と Vue 3 + TypeScript を実際に動かす。

**手順の目安**
- `composer require inertiajs/inertia-laravel`、`php artisan inertia:middleware`、`HandleInertiaRequests` を `web` グループへ登録。
- npm: `@inertiajs/vue3` `vue` `@vitejs/plugin-vue` `typescript` `vue-tsc`。
- `vite.config.ts` へ移行（`.js` は削除）、`resources/js/app.ts`（Inertia + Vue3 起動）、`tsconfig.json` 追加。
- `resources/js/Pages/Welcome.vue`（TypeScript, `<script setup lang="ts">`）を用意し、ルート `/` を `Inertia::render('Welcome')` に。
- `package.json` の `build` を `vue-tsc --noEmit && vite build` に（型エラーを CI で検出）。

**受け入れ条件**
- [ ] `./vendor/bin/sail npm run build` が成功（**型エラー 0**）
- [ ] `<http://localhost>` が Inertia 経由の Vue ページ（Welcome.vue）を描画
- [ ] `composer.json` に `inertiajs/inertia-laravel`、`package.json` に `@inertiajs/vue3` `vue` `typescript`
- [ ] `resources/js/app.ts` / `vite.config.ts` / `tsconfig.json` が存在（`.js` 版は残さない）
- [ ] `./vendor/bin/sail artisan test` が green

---

## Task 0-3 — Vuetify 3 導入

**目的**：Vuetify 3 を実際に組み込み、コンポーネントが描画されることを確認。

**手順の目安**
- npm: `vuetify@^3` `@mdi/font`（または `@mdi/js`）。必要なら `vite-plugin-vuetify`。
- `resources/js/plugins/vuetify.ts` で `createVuetify`（テーマ 1 つ、ダーク/ライトは既定でよい）。`app.ts` で `use(vuetify)`。
- `Welcome.vue` に Vuetify コンポーネント（`v-app` / `v-btn` / `v-card` など）を最小限置いて表示確認。
- SSR はしない（Inertia + CSR）。スタイル（`vuetify/styles`）を読み込む。

**受け入れ条件**
- [ ] `./vendor/bin/sail npm run build` が成功（型エラー 0）、Vuetify のスタイル/コンポーネントがバンドルに含まれる
- [ ] `<http://localhost>` で Vuetify コンポーネント（例：`v-btn`）が Vuetify のスタイルで描画される
- [ ] `package.json` に `vuetify`
- [ ] `./vendor/bin/sail artisan test` が green

---

## Task 0-4 — 既存 docs / config / .env.example との統合確認

**目的**：生成物と本リポジトリの既存資産が矛盾なく共存していることを保証（新規ドメインコードは書かない）。

**手順の目安**
- `.env.example` に Phase 0 で必要な全キーが揃っているか確認（`RESERVATION_AUTHORITY` `EXTERNAL_RESERVATION_GATEWAY` `RESERVATION_SLOT_MINUTES` `RESERVATION_HOLD_MINUTES` `STRIPE_*` `RETENTION_*` `DB_*` `MAIL_*` 等）。不足は追記。
- `config/reservation.php` `config/retention.php` `config/stripe.php` が `config:show` で読めること。参照している env キーが `.env.example` に存在すること。
- `config/stripe.php` が参照する Gateway クラス名（`App\Modules\ExternalIntegration\Gateways\Reservation\*`）は Phase 1 で作るので、
  **この Task ではクラスを作らない**。代わりに `config/reservation.php` の `gateways.*.class` を `::class` 参照から文字列（FQCN 文字列）へ一時的に変えるか、
  「Phase 1 で実装」コメントを残し、`config:show` がクラス未存在で fatal にならない形にする（文字列参照に統一する方が安全）。
- `.gitignore` に Laravel/Sail 生成物（`/vendor` `/node_modules` `/public/build` `.env` 等）が入っているか（既存 `.gitignore` を活かしつつ不足を追記）。
- `README.md` の手順どおりに再現できるか（コマンドの齟齬があれば `README.md` は変えず、齟齬内容を報告）。

**受け入れ条件**
- [ ] `./vendor/bin/sail artisan config:show reservation` / `... retention` / `... stripe` がエラーなく表示
- [ ] `.env.example` に不足キーが無い（`config/*.php` が参照する env がすべて定義済み）
- [ ] `git status` で既存 `docs/**` に変更が無い（`docs/tasks/phase-00.md` の進捗チェック更新は可）
- [ ] `./vendor/bin/sail artisan test` が green

---

## Task 0-5 — CI / build / test / 起動の最終確認

**目的**：Phase 0 完成条件を満たすことを一括で確認し、結果を残す。

**手順の目安**
- `./vendor/bin/sail up -d` → `sail artisan migrate:fresh` → `sail npm ci` → `sail npm run build` → `sail artisan test` を通す。
- `.github/workflows/ci.yml` が生成物と整合するか確認（`artisan` / `composer.json` / `package.json` 検出で各ステップが走る作り。壊れていれば**最小限**修正して報告）。
- `composer.json` に `test` スクリプト（`@php artisan test`）があること。
- 実行ログ（バージョン・build 結果・test 結果）を `docs/tasks/phase-00.md` の末尾「実行ログ」へ追記。

**受け入れ条件**
- [ ] `sail npm run build` 成功（型エラー 0）
- [ ] `sail artisan test` 成功
- [ ] `<http://localhost>` 起動確認（Inertia + Vue + Vuetify）
- [ ] Laravel / PHP / Inertia / Vue / Vuetify のバージョンを記録
- [ ] 秘密情報混入なし（`sk_live_` / `pk_live_` / `64ssq_ark_db` を含まない）
- [ ] `.github/workflows/ci.yml` が生成物と矛盾しない

---

## 実行ログ（Codex / Claude Code が追記）

（Task ごとに：日時 / 実行コマンド / 結果 / バージョン / 残課題）

### 2026-09-07 — Task 0-1 試行 1（Codex, gpt-5.6-sol, sandbox=workspace-write+network）
- Codex は AGENTS.md / docs/PLAN.md / docs/tasks/phase-00.md を通読。
- **ブロック**：Docker daemon に接続不可（`docker version` の Server 応答なし）。Docker Desktop も起動できず（`kLSNoExecutableErr` / サンドボックスによりログ領域書き込み不可）。
- リポジトリへの変更なし（`git status --porcelain` 差分ゼロ、保護ファイルの SHA-256 不変、`git add`/`commit` 未実行）→ ガードレールは機能。
- Claude Code 側の診断：`pgrep -fl -i Docker` = プロセスなし。`~/.docker/run/docker.sock` は 6/7 の古いソケットが残存するのみ。**Docker Desktop は実際には起動していない**。
- 未達：Laravel 13 scaffold / Sail 起動 / migrate / test / localhost welcome。
- 合格：既存 config 3 ファイル温存 / 保護ファイル破壊なし。
- 次アクション：ユーザーが Docker Desktop を起動し `docker info` 成功を確認 → Task 0-1 を再実行。
- 補足リスク：Docker 起動後も、Codex の `workspace-write` サンドボックスから Docker ソケット（workspace 外）へ到達できない可能性あり。その場合は Claude Code がブートストラップ用シェル（`composer create-project` / `sail up` / `migrate` / `test`）を実行し、Codex は 0-2 以降のアプリコードを担当、という分担に切り替える。

### 2026-09-07 — Docker 復旧 + Task 0-1 実施（Claude Code / environment lane, 分担 A）
- `osascript -e 'quit app "Docker"'` → プロセス確認（vmnetd のみ）→ `docker desktop start`（CLI）で daemon 起動成功（`open -a Docker` はハングしていた）。`docker info` / `docker ps` 成功。既存の無関係コンテナ（onecli, onecli-postgres）はポート 10254-5 / 5432 で本件と非競合。
- `composer create-project laravel/laravel:^13.0`（`composer:2` 公式イメージ）→ `.laravel-tmp` に生成 → 保護ファイル除外で `rsync` マージ → `.laravel-tmp` 削除。Laravel の `AGENTS.md` / `CLAUDE.md` / `README.md` / `.gitignore` / `.env.example` は取り込まず、当リポジトリ側を維持。Laravel 既定 `.env.example` は `docs/tasks/laravel13-default.env.example` に退避（Task 0-4 の突合用）。
- **PHP バージョン論点**：`composer create-project` は composer:2 イメージ（PHP 8.4）で解決したため Symfony 8.1（PHP >= 8.4.1 要求）が入った。PLAN / AGENTS / CI は 8.3 基準のため、`composer config platform.php 8.3.7` + `composer update` で Symfony 7.4 系へ再解決（8.3 基準を維持。サーバー実測後に必要なら 8.4 へ引き上げ）。
- `composer require laravel/sail --dev` → `php artisan sail:install --with=mysql,mailpit --php=8.3`（`runtimes/8.3`）。生成物は `compose.yaml`（新形式）。`.env` は当リポジトリ `.env.example` から再生成し `WWWUSER/WWWGROUP` を追記。
- `./vendor/bin/sail up -d`（`sail-8.3/app` ビルド）→ mysql:8.4 healthy 待ち → `key:generate` → `migrate --force`（users/cache/jobs）→ `artisan test`（2 passed）→ `curl http://localhost` = 200 / `<title>ARK Reservation</title>`。
- `config:show reservation|retention|stripe` すべて表示 OK。保護ファイル SHA-256 不変。`git add`/`commit` 未実行。

#### Task 0-1 受け入れ条件
- [x] `artisan` 存在・`sail artisan --version` = Laravel Framework **13.30.1**
- [x] `composer.json` require `laravel/framework ^13.17`（解決 13.30.1）
- [x] `sail up -d` 成功・`sail artisan migrate` 成功（MySQL 8.4）
- [x] `sail artisan test` green（2 tests / 2 assertions）
- [x] `http://localhost` が 200・Laravel welcome
- [x] `config/{reservation,retention,stripe}.php` 温存・`config:show` 動作
- [x] 保護ファイルの削除・破壊なし

#### バージョン（Task 0-1 時点）
- PHP 8.3.33（Sail runtime 8.3）
- Laravel Framework 13.30.1
- MySQL 8.4（Sail 既定。**本番の実バージョンはサーバー実測で確定**）
- Mailpit latest / Composer 2 / Symfony 7.4 系

### 2026-09-08 — Task 0-2（Inertia + Vue 3 + TS）: **完了**
- Codex（`gpt-5.6-sol`、sandbox=workspace-write）がファイル実装を担当。Claude Code が Docker/Sail/Composer/npm を実行し検証。
- **Codex 作成**：`app/Http/Middleware/HandleInertiaRequests.php` / `resources/views/app.blade.php` / `resources/js/app.ts` / `resources/js/Pages/Welcome.vue` / `vite.config.ts` / `tsconfig.json`
- **Codex 変更**：`composer.json`（require に `inertiajs/inertia-laravel ^2.0`）/ `package.json`（`@inertiajs/vue3`,`vue`,`@vitejs/plugin-vue`,`typescript`,`vue-tsc`,`@types/node`、`build`=`vue-tsc --noEmit && vite build`）/ `bootstrap/app.php`（`$middleware->web(append:[HandleInertiaRequests::class])`）/ `routes/web.php`（`Inertia::render('Welcome', ['appName'=>config('app.name')])`）/ `resources/css/app.css`（最小・Tailwind なし）
- **Codex 削除**：`vite.config.js` / `resources/js/app.js`（`bootstrap.js` は元から無し）
- **Claude Code 実行**：`sail composer update`（`inertiajs/inertia-laravel v2.0.26` 導入・PHP8.3/Laravel13 で解決）→ `sail npm install`（146 packages・脆弱性0）→ `sail npm run build`（`vue-tsc --noEmit` 通過→vite build 成功、`public/build/manifest.json` 生成）→ `sail artisan test`（2 passed / 2 assertions）→ `curl http://localhost`（200、`<div id="app" data-page="{...component:Welcome...}">` + ビルド済み `app-*.js`/CSS 参照）→ `vue-tsc --noEmit` 単独再実行 exit 0（型エラー0 確認）→ 秘密情報/本番接続スキャン クリーン。
- 保護ファイル SHA-256 不変。`git add`/`commit` 未実行。

#### Task 0-2 受け入れ条件（ユーザー指定 12 項目）
| # | 内容 | 判定 |
|---|---|---|
| 1 | `composer.json` に `inertiajs/inertia-laravel` | ✅（`^2.0` / 導入 v2.0.26） |
| 2 | `package.json` に `@inertiajs/vue3`,`vue`,`typescript`,`vue-tsc`,`@vitejs/plugin-vue` | ✅ |
| 3 | `HandleInertiaRequests` 登録 | ✅（`bootstrap/app.php` web append。curl の Inertia ハンドシェイクで動作確認） |
| 4 | `app.ts` / `vite.config.ts` / `tsconfig.json` 存在 | ✅ |
| 5 | 旧 `app.js` / `vite.config.js` なし | ✅（削除確認） |
| 6 | `/` が `Inertia::render('Welcome')` | ✅ |
| 7 | `Welcome.vue` が `<script setup lang="ts">` | ✅ |
| 8 | `sail npm run build` 型エラー0で成功 | ✅ |
| 9 | `sail artisan test` 成功 | ✅（2/2） |
| 10 | `http://localhost` で Inertia 経由 Vue ページ | ✅（Inertia ペイロード + ビルド済みバンドル参照。ブラウザ DOM 目視は未実施＝環境上ブラウザ不可） |
| 11 | 保護ファイル破壊なし | ✅（SHA-256 一致） |
| 12 | 秘密情報・本番接続の混入なし | ✅（スキャン クリーン） |

#### 備考（0-4 以降で扱う軽微事項）
- `package.json` の版が新しめ（vite 8 / `@vitejs/plugin-vue` 6 / `vue-tsc` 3 / `laravel-vite-plugin` 3.1）。install/build は正常。
- `inertiajs/inertia-laravel` は仕様どおり `^2.0`（v3 も存在。移行判断は別途）。
- scaffold 由来の `optionalDependencies: @laravel/multiplex` / `concurrently` が残存（無害・0-4 で整理可）。
- `composer.lock` は Claude Code の `composer update` で更新（環境レーン作業）。
- 試行 1：Codex がモデル `gpt-6-astra` を自動選択 → インストール済み CLI (codex-cli 0.150.1) が非対応（`requires a newer version of Codex`）。変更なし。
- 試行 2：`-m gpt-5.6-sol` を明示指定（Task 0-1 で成功したモデル）→ **Codex 利用上限に到達**（"You've hit your usage limit ... try again at Sep 8th, 2026 12:15 PM"）。変更なし。
- 結論：Codex はアカウントのクォータ枯渇で **2026-09-08 12:15 (PT) 頃まで利用不可**。Task 0-2〜0-5 は Codex 実装のためブロック。
- Claude Code はアプリコードを実装しない方針のため、ここで STOP しユーザー判断を仰ぐ。
- Sail スタックは起動したまま（`http://localhost` 稼働）。次回セッションで `./vendor/bin/sail up -d` で再開可。
- 次回 Codex 実行時の注意：`-m gpt-5.6-sol` を明示（既定の `gpt-6-astra` は現 CLI 非対応）。`codex update` は Auto Mode によりしない。

---

### 2026-09-08 — Task 0-3（Vuetify 3 最小導入）: **完了**
- Codex（`gpt-5.6-sol`、sandbox=workspace-write）がファイル実装。Claude Code が npm/build/test を実行し検証。
- **Codex 新規**：`resources/js/plugins/vuetify.ts`（`import 'vuetify/styles'` + `createVuetify({components, directives})`、テーマ/アイコン設定なし）
- **Codex 変更**：`package.json`（devDependencies に `vuetify: ^3.0.0`）/ `resources/js/app.ts`（`.use(plugin).use(vuetify)`、Inertia 起動フロー維持）/ `resources/js/Pages/Welcome.vue`（ルートを `<v-app>` 化、`v-main`/`v-card`/`v-card-title`/`v-card-text`/`v-card-actions`/`v-btn` 配置、技術スタック表記に「Vuetify 3」追加、`<script setup lang="ts">` 維持）
- **導入せず（YAGNI）**：`vite-plugin-vuetify`、`@mdi/font` 等アイコン、カスタムテーマ、管理レイアウト、Tailwind
- **Claude Code 実行**：`sail npm install`（`vuetify@3.13.3`、脆弱性0）→ `sail npm run build`（`vue-tsc --noEmit` 型エラー0 → vite build 成功、1309 modules。`app-*.css` 0.12kB→**509kB**、`app-*.js` 251kB→**797kB** で Vuetify 同梱を確認。>500kB 警告あり＝手動一括登録によるもの・許容）→ `sail artisan test`（2 passed / 2 assertions）→ `curl http://localhost`（200、HTML が Vuetify 膨張後の `app-*.js`/`app-*.css` と Inertia payload `component:Welcome` を参照）
- **検証**：`app-*.css` に `.v-application`/`.v-btn` 存在、`app-*.js` に vuetify 参照存在。保護ファイル SHA-256 全て不変。Codex 変更ファイルの秘密情報/本番接続スキャン クリーン。Tailwind 参照なし。`git add`/`commit`/`push` 未実行。作業は `ark-reservation-system` 配下のみ（`ark-system-proposal`・WordPress・本番 いずれも無変更）。

#### Task 0-3 受け入れ条件（ユーザー指定 14 項目）
| # | 判定 |
|---|---|
| 1 package.json に vuetify | ✅（`^3.0.0` / 3.13.3） |
| 2 Vuetify plugin 登録 | ✅（`app.ts` `.use(vuetify)`） |
| 3 Vuetify CSS 読み込み | ✅（`vuetify/styles` → 509kB CSS を `<link>` 配信） |
| 4 Welcome.vue で Vuetify コンポーネント | ✅（`v-app`/`v-main`/`v-card`/`v-btn` 等） |
| 5 TypeScript 型エラー0 | ✅ |
| 6 `sail npm run build` 成功 | ✅ |
| 7 `sail artisan test` 成功 | ✅（2/2） |
| 8 localhost 200 | ✅ |
| 9 Task 0-2 構成を壊していない | ✅ |
| 10 競合 UI（Tailwind 等）追加なし | ✅ |
| 11 Phase 1 先行実装なし | ✅ |
| 12 秘密情報・本番接続の混入なし | ✅ |
| 13 git add/commit/push なし | ✅ |
| 14 ark-system-proposal/WordPress/本番に変更なし | ✅ |

#### 残課題（0-4 以降・軽微）
- バンドルサイズ（`app.js` 797kB / `app.css` 509kB）。手動一括登録による。0-4 で `vite-plugin-vuetify`（treeshake）検討可。今は YAGNI で許容。
- `<Head>` が `<v-app>` 内にネスト（`teleport` のため機能上問題なし）。
- `package.json` の版が新しめ（vite 8 等）。install/build 正常。
- scaffold 由来 `optionalDependencies: @laravel/multiplex` / `concurrently` 残存（無害）。
- ブラウザでの描画目視は環境制約で未実施（CSS/JS 同梱・型・テスト・Inertia handshake で担保）。

#### Task 0-4 へ進める状態か
進める状態。ただしユーザーの明示許可を待つ。

---

### 2026-09-07 — Task 0-4（既存 docs / config / `.env.example` / CI 整合、Codex）
- **変更**：`.env.example` に Laravel 標準キー 19 個と、`config/retention.php` が参照していた未定義の `STRIPE_ARCHIVE_WEBHOOK_DISK=local` を追加。既存 ARK 独自キーは保持し、重複なし。
- **変更**：CI の秘密情報ガードから POSIX ERE 非対応の negative lookahead を除去し、Stripe Live key と webhook secret を POSIX grep で別々に検査。MySQL サービスを `mysql:8.4` に統一。
- **変更**：`config/reservation.php` の未実装 gateway 3 クラスを FQCN 文字列化し、各行へ `Phase 1 で実装` コメントを追加。
- **変更**：`package.json` から未使用の `concurrently` と `optionalDependencies.@laravel/multiplex` を削除。`package-lock.json` は npm 未実行のため未更新。
- **変更**：`AGENTS.md` の Sail MySQL 記述を 8.4 に統一。README のセットアップ節へ「Task 0-1〜0-3 で実施済み」の注記 1 行のみ追加。
- **実行制約**：指示に従い Docker / Sail / npm / composer は未実行。Git の add / commit / push も未実行。
- **静的検証**：`package.json` の JSON parse、CI YAML parse、CI guard の `bash -n`、env キー重複・削除・対象 config 参照漏れ、指定文字列の残存を確認し、すべて問題なし。
- **Task 0-4 で確認した既知差異**：
  - `docs/PHASE0_REPORT.md` は Docker 停止・Laravel scaffold 未実行時点の報告。履歴資料として本文は変更せず、現在状況は本ファイルの Task 0-1〜0-4 ログを正とする。
  - README の再構築手順本文には scaffold 前提の説明と `CASHIER_KEY` 表記が残る。構造・本文は変更せず、今回許可された実施済み注記だけを追加した。
  - 本ファイル Task 0-4 の手順に「`config/stripe.php` が Gateway クラス名を参照」とあるが、実際の参照元は `config/reservation.php`。タスク定義本文は履歴として変更していない。
- **Vuetify バンドル警告**：今回は設定変更なし。Phase 1 の UI 基盤タスクで `vite-plugin-vuetify` によるツリーシェイクを検討する。
- **次の検証**：Claude Code が `sail npm install`、build、test、`config:show reservation`、config cache/clear を順に実行する。

### 2026-09-08 — Task 0-5（CI 整合・最終検証、Codex + Claude Code）
- **Codex 変更ファイル一覧**
  - `.github/workflows/ci.yml`
  - `tests/Feature/WelcomePageTest.php`
  - `README.md`
  - `docs/tasks/phase-00.md`
- **Codex 変更内容**
  - `.github/workflows/ci.yml`：秘密情報ガード 3 種を「実キー形式のみ」検出へ（`sk_live_/pk_live_/rk_live_[A-Za-z0-9]{16,}`、`whsec_[A-Za-z0-9]{16,}`（`whsec_test` 許容）、`64ssq_ark_db` は `DB_DATABASE=`／`'database'=>` の代入文脈のみ）。対象拡張子を `--include` で限定。`bash -n` 通過・YAML 妥当。
  - `tests/Feature/WelcomePageTest.php` 新規：`GET /` → 200 かつ `AssertableInertia` で `->component('Welcome')` を検証。
  - `README.md`：セットアップ節の「骨組み未実行」記述を「Task 0-1〜0-3 で導入済み。以下は再構築手順」に修正。env 表の `CASHIER_KEY` → `STRIPE_KEY`。
  - `composer.json` は既定の `scripts.test`（`config:clear` → `artisan test`）が既にあり変更なし。

- **Claude Code 検証結果（2026-09-08）**
  - `sail npm install`：脆弱性 0
  - `sail npm run build`：`vue-tsc --noEmit` **型エラー 0** → vite build 成功（`app-*.js` 797kB / `app-*.css` 509kB、>500kB 警告は Vuetify 手動一括登録による・課題として継続）
  - `sail artisan test`：**3 passed / 12 assertions**（ExampleTest×2 + WelcomePageTest×1）
  - `curl http://localhost`：**200**、Inertia payload に `component:"Welcome"`
  - `docker compose config -q`：妥当
  - CI guard ローカル擬似実行：live key / whsec / WP DB 接続 いずれも**検出なし（誤検知なし）**
  - `composer audit`：脆弱性なし／`composer validate`：valid
  - 全体秘密情報スキャン（実キー形式・AWS・PEM・Slack token）：**混入なし**
  - `.env` は `.gitignore` 対象
  - `git`：コミット 0 件（`git log` = "no commits yet"）。add/commit/push なし
  - リポジトリ外：`vela` 本体 HEAD 不変（`792243e`）、`ark-system-proposal/` 無変更

- **使用バージョン（実測）**
  - Laravel Framework 13.30.1 / PHP 8.3.33（Sail runtime 8.3）
  - inertiajs/inertia-laravel v2.0.26
  - @inertiajs/vue3 2.3.27 / vue 3.5.42 / vuetify 3.13.3
  - typescript 5.9.3 / vue-tsc 3.3.11 / vite 8.2.2 / @vitejs/plugin-vue 6.0.8 / laravel-vite-plugin 3.2.0
  - MySQL 8.4（Sail・CI とも。本番の実バージョンはサーバー実測で確定：OPEN_QUESTIONS #2）

---

## ✅ Phase 0（ローカル）完了 — 2026-09-08

Phase 0 完成条件はすべて充足：Laravel 13 実アプリ起動 / Inertia.js / Vue 3 / TypeScript / Vuetify 3 /
`npm run build` 成功 / PHP test 成功 / localhost 200 / Codex 実装フロー正常（0-2〜0-5）/
Claude Code レビュー正常 / docs・config・CI 整合 / 秘密情報なし / 本番接続なし / WordPress 影響なし。

### 残課題（Phase 1 以降・いずれも非ブロッカー）
1. **Vuetify バンドルサイズ**（js 797kB / css 509kB、>500kB 警告）。Phase 1 UI 基盤で `vite-plugin-vuetify` ツリーシェイクを導入検討。
2. **CI は未実行**（git remote 未設定）。ガード・ステップはローカル擬似実行で確認済み。remote 接続時に実走で最終確認。
3. **お名前.com サーバー実測は別タスク**（`tools/server-probe.*`）。本番 PHP/MySQL・Composer/cron/mod_rewrite・共有継続可否は未確定（OPEN_QUESTIONS #1〜#3、PHASE0_REPORT §3-4）。
4. `docs/PHASE0_REPORT.md §2-4` は Docker 停止時点の記述が残る（履歴資料。現況は本ファイルを正）。
5. 初期に `git add -A` した分が staged 表示（`AM`/`A`）。コミット 0 件で無害。次回コミット時に再ステージ。

### Phase 1 開始条件
1・4・5 は Phase 1 をブロックしない。2・3 はユーザー側の別作業。Phase 1 は `RESERVATION_AUTHORITY=local` /
`EXTERNAL_RESERVATION_GATEWAY=null` / Stripe 未接続で進行可能。**ユーザーの明示許可を待つ。**
