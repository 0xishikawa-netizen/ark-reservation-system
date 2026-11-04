#!/usr/bin/env bash
# ARK 予約システム — お試し環境（Staging）用のアップロードパッケージを作る（Task 11-36）
#
# 使い方（開発環境のリポジトリ直下で）:
#   npm run build            # 先に画面をビルドしておく（public/build）
#   bash tools/build-staging-package.sh [出力先.tar.gz]
#
# - コミット済みの HEAD を元にする（作業中の未コミット変更は入らない）。
# - vendor は本番用（--no-dev）。node_modules は入れない。
# - .env は入れない（サーバー側で .env.staging.example から作る）。
set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
OUT=${1:-"$ROOT/ark-staging.tar.gz"}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/ark-staging.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

cd "$ROOT"
[ -f public/build/manifest.json ] || { echo "public/build がありません。先に npm run build を実行してください。" >&2; exit 1; }
git diff --quiet HEAD -- resources/js resources/css vite.config.* package.json \
    || echo "注意: 画面のソースに未コミットの変更があります。public/build と HEAD がずれていないか確認してください。" >&2

git archive HEAD | tar -x -C "$WORK"
cp -R public/build "$WORK/public/build"

# .env が無い状態で artisan を起動させない（--no-scripts。パッケージ一覧はサーバー初回起動時に自動生成される）
(cd "$WORK" && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --no-progress --prefer-dist)

# 開発専用のファイルは入れない
rm -rf "$WORK/tests" "$WORK/node_modules" "$WORK/.github" "$WORK/compose.yaml" "$WORK/docker-compose.yml"
rm -f "$WORK/.env" "$WORK/bootstrap/cache/"*.php
# ネットワーク事情で composer が git から取得した場合の履歴（.git）を除く
find "$WORK/vendor" -name .git -type d -prune -exec rm -rf {} +

tar -czf "$OUT" -C "$WORK" .
echo "作成: $OUT（$(du -h "$OUT" | cut -f1)）"
