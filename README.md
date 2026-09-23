# ARK Conditioning 予約・決済システム

ARK Conditioning の会員予約・事前決済・月額利用権（サブスク）・回数券・顧客マイページ・
店舗管理画面を提供する **独立した Laravel アプリケーション**。

既存の WordPress サイト（`ark-conditioning.com`）とは **別リポジトリ・別 DB・別ドメイン**で、
WordPress 本体には一切手を入れない。

- 設計の正本： [`../vela` 配下の承認済みプラン] ではなく、本リポジトリの [`docs/`](docs/) を参照。
- アーキテクチャ： [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- DB スキーマ： [`docs/DB_SCHEMA.md`](docs/DB_SCHEMA.md)
- 運用手順： [`docs/OPERATIONS.md`](docs/OPERATIONS.md)
- 未確定事項： [`docs/OPEN_QUESTIONS.md`](docs/OPEN_QUESTIONS.md)
- Phase 0 の状況報告： [`docs/PHASE0_REPORT.md`](docs/PHASE0_REPORT.md)

---

## 技術スタック

| レイヤー | 採用 |
|---|---|
| バックエンド | Laravel 13（PHP 8.3+） / モジュラーモノリス |
| フロント統合 | Inertia.js（SPA+API 完全分離にはしない） |
| UI | Vue 3 + TypeScript + Vuetify 3 |
| 予約台帳など業務固有 UI | Vue 専用コンポーネント自作 |
| 決済（課金） | Stripe + laravel/cashier（課金契約のみ）+ Payment Element |
| DB | MySQL（実バージョンは Phase 0 で確認 → `docs/PHASE0_REPORT.md`） |
| 認証 | Laravel 標準 + spatie/laravel-permission（web guard 1 つ + ロール） |
| キュー | Laravel Queue（DB ドライバ）+ cron `schedule:run` |

---

## ローカル開発環境のセットアップ（Docker / Laravel Sail 前提）

> Laravel 13 / Inertia / Vue / TypeScript / Vuetify は Task 0-1〜0-3 で導入済み。
> 以下は再構築・新規クローン時の手順で、ローカルに PHP / Composer を入れず Docker のみで完結させる。

### 前提
- Docker Desktop が起動していること（`docker info` が成功する）
- Node.js 20+ / npm（フロントビルド用。ローカルに導入済みでよい）

### 1. Laravel 本体の導入（初回のみ）

```bash
cd ark-reservation-system

# Composer を Docker 経由で実行し、この場に Laravel を展開する
docker run --rm -v "$(pwd)":/app -w /app composer:2 \
  create-project laravel/laravel:^13.0 tmp_app --no-interaction --prefer-dist

# 生成物を直下へ移動（既存の docs/ config/ 等は温存してマージ）
rsync -a tmp_app/ ./ && rm -rf tmp_app
```

- `config/reservation.php` `config/retention.php` `config/stripe.php` は本リポジトリ側を優先（上書きしない）。
- `.gitignore` `.env.example` も本リポジトリ側を優先。

### 2. Sail の導入と起動

```bash
docker run --rm -v "$(pwd)":/app -w /app composer:2 require laravel/sail --dev --no-interaction
docker run --rm -v "$(pwd)":/app -w /app composer:2 exec sail:install --publish=false 2>/dev/null || \
  docker run --rm -v "$(pwd)":/app -w /app -e SAIL_SKIP_CHECKS=1 laravelsail/php83-composer:latest \
    php artisan sail:install --with=mysql,mailpit

cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

アプリ: <http://localhost> ／ Mailpit: <http://localhost:8025>

### 3. 主要な env（`.env.example` 参照）

| 変数 | ローカル既定 | 意味 |
|---|---|---|
| `RESERVATION_AUTHORITY` | `local` | 予約の System of Record（`local` / `peak_manager` / `salon_board`） |
| `EXTERNAL_RESERVATION_GATEWAY` | `null` | 外部予約ゲートウェイ実装（`null` / `peak_manager` / `salon_board`） |
| `STRIPE_KEY` / `STRIPE_SECRET` | Test キー | **Live キー禁止**。`APP_ENV=local` で Live キーを検出したら起動時に例外 |
| `RESERVATION_SLOT_MINUTES` | `5` | 予約スロット粒度（`docs/OPEN_QUESTIONS.md` #9） |
| `RESERVATION_HOLD_MINUTES` | `10` | 仮予約（pending_payment）の枠 HOLD 時間 |

---

## よく使うコマンド

```bash
make up          # sail up -d
make down        # sail down
make migrate     # sail artisan migrate
make test        # sail artisan test（Pest/PHPUnit）
make fresh       # migrate:fresh --seed
make probe       # お名前.com サーバー能力プローブの使い方を表示
```

`make` を使わない場合は `./vendor/bin/sail ...` を直接叩く。

### フロントエンドの自動テスト（Vitest）

```bash
./vendor/bin/sail npm run test        # 1回実行（CI向け）
./vendor/bin/sail npm run test:watch  # 監視モード
```

### 予約台帳のリアルタイム通知（Laravel Reverb）

`.env` の `BROADCAST_CONNECTION=reverb` を有効にした状態で、別ターミナルで以下を起動しておくと、
予約台帳のオンライン予約通知が即時pushされる（未起動でも低頻度ポーリングへ自動フォールバックする）。

```bash
./vendor/bin/sail artisan reverb:start
```

---

## Phase 進行

`docs/OPEN_QUESTIONS.md` と各 Phase の受け入れ条件は設計プランに準拠。
Phase 1 以降は実装タスクを Codex に渡し、Claude Code がレビューする運用。
