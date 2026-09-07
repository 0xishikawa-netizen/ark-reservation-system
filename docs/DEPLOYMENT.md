# DEPLOYMENT

## 1. 環境マトリクス

| | Local | Staging | Production |
|---|---|---|---|
| ドメイン | `localhost` | `stg-member.ark-conditioning.com` | `member.ark-conditioning.com` |
| DB | ローカル MySQL（バージョンは本番に合わせる） | `64ssq_ark_test`（既存） | 新規（例 `64ssq_ark_app`） |
| Stripe | Test | Test | Live |
| `RESERVATION_AUTHORITY` | `local` | `local`（のち外部） | Phase 10 で決定（当面 `local`） |
| `EXTERNAL_RESERVATION_GATEWAY` | `null` | `null`（のち sandbox） | `null` または具象 |
| メール | Mailpit | 制限付き実送信 | 実送信 |
| 公開 | — | Basic 認証 + `noindex` | 公開 |
| `APP_DEBUG` | true | false | false |
| `SESSION_SECURE_COOKIE` | false | true | true |

WordPress DB（`64ssq_ark_db`）には**どの環境からも接続しない**。

## 2. 依存パッケージ（Laravel 本体導入後に追加）

Composer:
- `laravel/framework` ^13
- `inertiajs/inertia-laravel`
- `laravel/cashier`（Stripe 課金のみ）
- `spatie/laravel-permission`（role / permission）
- `laravel/fortify`（顧客認証 + スタッフ TOTP MFA）
- `spatie/laravel-backup`（DB + storage バックアップ）
- dev: `pestphp/pest` `pestphp/pest-plugin-laravel` `laravel/pint` `larastan/larastan`

npm:
- `@inertiajs/vue3` `vue` `typescript` `vite` `@vitejs/plugin-vue`
- `vuetify` `@mdi/font`
- 予約台帳の D&D はまず素の Pointer Events で実装（重い D&D ライブラリは入れない）

## 3. 初回ブートストラップ（ローカル / Docker のみ）

README「ローカル開発環境のセットアップ」を参照。要点：

1. `docker run --rm -v "$(pwd)":/app -w /app composer:2 create-project laravel/laravel:^13.0 tmp_app`
   → `rsync -a tmp_app/ ./ && rm -rf tmp_app`（`config/reservation.php` `config/retention.php` `config/stripe.php` `.gitignore` `.env.example` は本リポジトリ側を優先）
2. `composer require` で §2 のパッケージ
3. `php artisan sail:install --with=mysql,mailpit`
4. `cp .env.example .env` → `sail up -d` → `key:generate` → `migrate`
5. `AppServiceProvider::boot()` に **Stripe Live キー検出ガード**（`APP_ENV in [local,testing]` かつ `sk_live_`/`pk_live_` → 例外）を実装

## 4. Staging / Production デプロイ（VPS 前提）

> 共有ホスティング継続の場合は §6 を参照。

- Git ベース。`main` へのマージで GitHub Actions がビルド（`composer install --no-dev`、`npm ci && npm run build`）。
- リリースは Deployer 等で zero-downtime（`releases/` に配置し `current` シンボリックリンクを切替）。
- リリース後：
  ```
  php artisan down --render="errors::503"
  php artisan migrate --force
  php artisan config:cache && php artisan route:cache && php artisan view:cache
  php artisan queue:restart
  php artisan up
  ```
- `migrate --force` は**マイグレーションを含むリリースのみ**。実行前に §OPERATIONS のバックアップを取得。
- Queue：supervisor で `php artisan queue:work --tries=3 --max-time=3600` を常駐。
- Cron：`* * * * * cd /path/current && php artisan schedule:run >> /dev/null 2>&1`
- スケジュール登録（`routes/console.php` or `app/Console`）：
  `backup:run`（日次）/ `model:prune`（日次）/ `reservations:expire-pending`（毎分）/
  `tickets:reconcile` `memberships:reconcile` `reservations:reconcile-payments` `reservations:reconcile-slots`（日次）/
  `db:snapshot-size`（日次）

## 5. ロールバック

- アプリ：`current` を直前 `releases/*` へ戻す。
- マイグレーションを含むリリース：
  - **原則ロールフォワード**（修正を当てて再デプロイ）。
  - どうしても戻す場合のみ `php artisan migrate:rollback --step=1`（かつその down が安全なとき）。
  - リリース直前に取得した DB バックアップからのリストア手順は OPERATIONS.md。
- リスクの高い機能は feature flag（`settings` テーブル or config）で無効化できるようにする。

## 6. 共有ホスティング継続の場合（Phase 0 の判断が「継続」だったとき）

- `composer install` はローカル/CI で行い、`vendor/` ごとアップロード。
- `npm run build` はローカル/CI。`public/build/` をアップロード。`node_modules` はサーバーに置かない。
- Queue：supervisor 不可なら cron 毎分 `php artisan queue:work --stop-when-empty --max-time=50`。
- `schedule:run` を cron 毎分。cron 最小間隔が 5 分等なら「毎分前提のジョブ」（仮予約失効）を
  5 分許容に緩め、`RESERVATION_HOLD_MINUTES` を長めにする（OPEN_QUESTIONS #12 と整合）。
- `.env` はドキュメントルート外に置き、パーミッション 600。
- デプロイは rsync or Git pull + `artisan migrate --force`（特権 DB ユーザ）+ 各種 `*:cache`。
