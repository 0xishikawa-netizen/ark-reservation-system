# お試し環境（Staging）デプロイ手順 — お名前.com レンタルサーバー RS プラン（Task 11-36）

関係者だけが触れるお試し環境を `https://stg.ark-conditioning.com` に置く手順。
**WordPress（`64ssq_ark_db` と HP のフォルダ）・本番ドメインの設定・DNS には触れない。**
決済は Stripe のテストモードだけ（実際のお金は動かない）。メールは送らずログに残す。

## 0. 前提（2026-11-04 確認済み）

| 項目 | 値 |
|---|---|
| Web サーバー | `www1008.onamae.ne.jp`（PHP 8.3 選択済み） |
| DB サーバー | `mysql1002.onamae.ne.jp`（MySQL 5.7.44、`explicit_defaults_for_timestamp=OFF`。Task 11-35 で対応済み） |
| お試し用 DB | `64ssq_ark_test`（WordPress 用 `64ssq_ark_db` とは別） |
| SSH | 鍵認証のみ。`www1008.onamae.ne.jp` ポート `8022`、ユーザー `r2630422`（2026-11-05 接続確認。CLI は PHP 8.3.35、拡張は揃っている、git あり・composer なし、`public_html` 自体は書き込み禁止） |

MySQL 5.7 の制約：外部予約連携の送信（`SKIP LOCKED`）は動かない、DB の CHECK 制約は効かない。お試し環境では外部連携を使わないので影響なし。本番は MySQL 8 系が前提。

## 1. お名前.com のコントロールパネルでやること（初回だけ）

1. **SSH の鍵を作る**：「サーバー」→「SSH」→「はじめる」→「key の新規自動作成」。
   ダウンロードされた秘密鍵は自分の PC に保存し、誰にも送らない。画面に出る**ホスト名・ポート・ユーザー名**を控える。
2. **サブドメインを作る**：「ドメイン」→ サブドメインの追加で `stg.ark-conditioning.com` を作る。作成後、そのサブドメインの**公開フォルダの場所**（例：`/home/<ユーザー>/public_html/stg.ark-conditioning.com`）を控える。
3. **無料 SSL を有効にする**：「セキュリティ」で `stg.ark-conditioning.com` の SSL を有効化（反映に時間がかかることがある）。
4. **お試し用 DB のユーザーを用意する**：「データベース」→「ユーザー一覧」で `64ssq_ark_test` に使うユーザーを確認する。
   - そのユーザーが **WordPress 用 DB（`64ssq_ark_db`）にも使われている場合は、パスワードを変えない**（HP が止まる）。その場合はお試し専用のユーザーを新しく作り、`64ssq_ark_test` だけに権限を付ける。
   - お試し専用なら「ユーザー情報変更」で新しいパスワードを決める。

## 2. パッケージを作る（開発側）

```bash
npm run build
bash tools/build-staging-package.sh        # → ark-staging.tar.gz
```

## 3. サーバーに上げる（Mac のターミナル）

```bash
# <鍵> <ポート> <ユーザー> <ホスト> は 1-1 で控えた値
chmod 600 <鍵>
scp -i <鍵> -P <ポート> ark-staging.tar.gz tools/staging-deploy.sh <ユーザー>@<ホスト>:~/
ssh -i <鍵> -p <ポート> <ユーザー>@<ホスト>
```

## 4. サーバーで展開する（SSH でつないだ状態で）

```bash
php -v     # 8.3 以上か確認。古い場合は which php8.3 で場所を探し、以下の先頭に PHP=<その場所> を付ける

# 1 回目：.env を作って止まる
bash ~/staging-deploy.sh ~/ark-staging.tar.gz ~/ark-stg <公開フォルダ> --seed
```

止まったら `~/ark-stg/.env` を編集して空欄を埋める（`nano ~/ark-stg/.env` など）。

| 項目 | 入れる値 |
|---|---|
| `DB_USERNAME` / `DB_PASSWORD` | 1-4 で用意したお試し用 DB のユーザー |
| `STAGING_BASIC_AUTH_USER` / `STAGING_BASIC_AUTH_PASSWORD` | 関係者に配る共通 ID / パスワード |
| `STAGING_DEMO_PASSWORD` | テスト用アカウント共通のパスワード（12 文字以上） |
| `ADMIN_ALERT_EMAIL` | 自分のメールアドレス（必須。空だと起動しない。お試しでは実際には送られない） |
| `STRIPE_KEY` / `STRIPE_SECRET` | Stripe ダッシュボード（テストモード）の `pk_test_...` / `sk_test_...` |
| `APP_KEY` / `PII_LOOKUP_KEY` | 空のままで OK（スクリプトが自動で作る） |

```bash
# 2 回目：DB を作り、デモデータを入れ、公開フォルダに ARK の入口ファイルを置く
bash ~/staging-deploy.sh ~/ark-staging.tar.gz ~/ark-stg <公開フォルダ> --seed
```

`完了: https://stg.ark-conditioning.com/dev-menu を開いてください。` と出れば成功。

アプリ本体は `~/ark-stg`（公開フォルダの外）に置かれ、公開フォルダには入口の `index.php`（本体を読み込むだけ）と画面の部品（`build/` など）だけがコピーされる。お名前.com の初期ページ `index.html` は `index.html.bak` に退避される。

## 5. 確認

1. `https://stg.ark-conditioning.com/dev-menu` を開く → 共通 ID / パスワードを聞かれる → 開発用メニューが出る。
2. 「予約する」→ メニュー・日時を選んで、テストカード `4242 4242 4242 4242`（期限は未来・コードは任意 3 桁）で支払う。
3. 「管理画面」→ `dev-admin@ark.test` と `STAGING_DEMO_PASSWORD` でログイン → ブッキングボードに予約が出る。
4. 今の HP（`https://ark-conditioning.com`）が普段どおり表示されることを確認する。

## 6. 更新するとき

```bash
# 開発側で 2・3 をやり直してから、サーバーで（--seed なし＝データは残る）
bash ~/staging-deploy.sh ~/ark-staging.tar.gz ~/ark-stg <公開フォルダ>
```

## 7. 任意：cron（仮予約の期限切れ処理など）

お試しでは無くても画面は動く。入れる場合はコントロールパネルの cron 設定で、毎分（不可なら最短間隔）に次を登録する。

```
cd ~/ark-stg && php artisan schedule:run >> /dev/null 2>&1
```

## うまくいかないとき

| 症状 | 確認すること |
|---|---|
| 500 エラー | `~/ark-stg/storage/logs/` の最新ログ。`.env` の DB 設定。 |
| 共通 ID / パスワードを入れても何度も聞かれる | サーバーが認証ヘッダーを PHP に渡していない可能性。`.env` の `STAGING_BASIC_AUTH_*` を空にして `php artisan config:cache` し、代わりにコントロールパネルのアクセス制限（Basic 認証）機能を使う。 |
| 画面が崩れる・真っ白 | パッケージ作成前に `npm run build` したか。 |
