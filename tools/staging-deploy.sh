#!/usr/bin/env bash
# ARK 予約システム — お試し環境（Staging）をサーバー上で展開・更新する（Task 11-36）
#
# 使い方（サーバーに SSH して実行）:
#   bash staging-deploy.sh <パッケージ.tar.gz> <アプリの置き場所> <サブドメインの公開フォルダ> [--seed]
#   例: bash staging-deploy.sh ~/ark-staging.tar.gz ~/ark-stg ~/public_html/stg.ark-conditioning.com --seed
#
# - アプリ本体は公開フォルダの外（<アプリの置き場所>）に置き、公開フォルダは public/ へのリンクにする。
# - .env は <アプリの置き場所>/.env を使う（初回は .env.staging.example から作って値を埋める）。
# - WordPress のフォルダ（wp-config.php がある所）には絶対に触らない。見つけたら止まる。
# - --seed は初回だけ。DB を作り直してデモデータを入れる（既存データは消える）。
set -euo pipefail

PACKAGE=${1:?パッケージ（.tar.gz）を指定してください}
APP_DIR=${2:?アプリの置き場所を指定してください}
DOCROOT=${3:?サブドメインの公開フォルダを指定してください}
SEED=${4:-}
PHP=${PHP:-php}

fail() { echo "停止: $*" >&2; exit 1; }

# --- 安全確認（WordPress を巻き込まない） --------------------------------
for dir in "$APP_DIR" "$DOCROOT"; do
    [ -e "$dir/wp-config.php" ] && fail "$dir に wp-config.php があります（WordPress のフォルダです）。"
    [ -e "$dir/wp-login.php" ] && fail "$dir に wp-login.php があります（WordPress のフォルダです）。"
done
case "$DOCROOT" in
    */stg.*|*/stg|*stg-*) ;;
    *) fail "公開フォルダ $DOCROOT がお試し用サブドメインに見えません（名前に stg を含めてください）。" ;;
esac
[ -f "$PACKAGE" ] || fail "パッケージ $PACKAGE が見つかりません。"

"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
    || fail "PHP 8.3 以上が必要です（今: $("$PHP" -r 'echo PHP_VERSION;')）。PHP=/usr/bin/php8.3 のように指定して再実行してください。"

# --- 展開（.env と storage は残す） ---------------------------------------
mkdir -p "$APP_DIR"
tar -xzf "$PACKAGE" -C "$APP_DIR"
cd "$APP_DIR"

if [ ! -f .env ]; then
    cp .env.staging.example .env
    chmod 600 .env
    fail ".env を作りました。$APP_DIR/.env の空欄（DB・パスワード類・Stripe Test キー）を埋めてから、もう一度実行してください。"
fi
chmod 600 .env
grep -qE '^APP_ENV=staging' .env || fail ".env の APP_ENV が staging ではありません。"
grep -qE '^DB_DATABASE=64ssq_ark_db' .env && fail ".env の DB が WordPress の DB（64ssq_ark_db）になっています。"

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R u+rwX storage bootstrap/cache

grep -qE '^APP_KEY=.+' .env || "$PHP" artisan key:generate --force
if ! grep -qE '^PII_LOOKUP_KEY=.+' .env; then
    key="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
    sed -i "s#^PII_LOOKUP_KEY=.*#PII_LOOKUP_KEY=${key}#" .env
fi

# --- DB と キャッシュ -------------------------------------------------------
"$PHP" artisan config:clear
if [ "$SEED" = "--seed" ]; then
    "$PHP" artisan migrate:fresh --force --seed
    "$PHP" artisan db:seed --class=DemoScenarioSeeder --force
else
    "$PHP" artisan migrate --force
fi
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

# --- 公開フォルダを public/ へ向ける ---------------------------------------
if [ -L "$DOCROOT" ]; then
    ln -sfn "$APP_DIR/public" "$DOCROOT"
elif [ -d "$DOCROOT" ]; then
    # 中身が空か、お名前.com の初期ファイルだけなら退避してリンクに置き換える
    leftover=$(find "$DOCROOT" -mindepth 1 -maxdepth 1 ! -name 'index.html' ! -name '.htaccess' | head -1)
    [ -n "$leftover" ] && fail "$DOCROOT に既存のファイルがあります（$leftover）。中身を確認してから空にしてください。"
    mv "$DOCROOT" "${DOCROOT}.bak.$(date +%Y%m%d%H%M%S)"
    ln -s "$APP_DIR/public" "$DOCROOT"
else
    ln -s "$APP_DIR/public" "$DOCROOT"
fi

echo "完了: $(grep -E '^APP_URL=' .env | cut -d= -f2-)/dev-menu を開いてください。"
