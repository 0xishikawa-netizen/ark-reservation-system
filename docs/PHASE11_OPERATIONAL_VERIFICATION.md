# Phase 11 ローカル管理画面・E2E検証（2026-09-25）

この記録は Task 11-14 のローカル検証であり、旧Excel実績との照合証跡ではない。`PHASE11_TEST_` の事実は架空の**検証用**で、実営業実績として扱わない。本番DB・外部サービスには接続していない。

開始時のbranchは`main`、HEADは`f07b020`（石川達哉、2026-09-25 19:08 JST、`Update current changes`）。同commitはPhase 11の集計・会計・Excel・migration・画面・テスト・設計文書を中心に204ファイルを変更している。今回の変更はその上の未コミット差分であり、開始時から存在した`docs/PHASE11_RECONCILIATION.md`の差分は保持した。reset / revert / commit / pushは行っていない。

## 403の原因と修正

- ローカルDBの `permissions` / `role_has_permissions` はPhase 11以前の20件のままだった。ログイン中の開発管理者は `admin` roleを持つ一方、`reports.view` / `sales.view` / `reports.export` 等は未登録で `can()` がfalseだった。`model_has_permissions` に直接付与はなく、permission cacheも存在した。
- `RolePermissionSeeder` で `admin` にweb guardの全permissionを同期し、`DevelopmentAdminSeeder` 単体再実行時も権限定義を最新化する。manager / staff / customerには新たな全権限を与えない。`can` middlewareは維持した。
- ローカルでは `RolePermissionSeeder` を実行し、`permission:cache-reset` を実行済み。開発管理者の25権限を確認し、権限なしstaffのReports 403を自動テストで確認した。
- 管理画面の「集計」導線はInertia共有値のnamed routeを使用し、`reports.view`（月計・年間集計は加えて`sales.view`）で表示を分ける。

## 来店完了時の時刻整合

E2Eレビューで、予約台帳の予約日時（JSTの壁時計値）を来店完了時の自動施術生成がUTC instantとしてそのまま保存するずれを発見した。11:30〜12:30の予約を自動完了すると、従来は施術実績がUTC 11:30〜12:30となり、時間帯別稼働率が9時間ずれる。`VisitCompletionService` の**新規自動生成分のみ**JSTからUTCへ変換し、10〜12と12〜15に30分ずつ配分される回帰テストを追加した。既に完了した施術実績は不変のため、推測backfillは行わない。過去の自動生成実績については、原資料が揃った際に予約時刻との照合対象とする。

## ローカルDBと検証データ

`2026_09_25_000004` と `000005` の未適用の追加型migrationだけを `migrate --no-interaction` で適用した。`migrate:fresh`・既存データ削除は行っていない。`Phase11MasterSeeder` の既存マスタと、手動専用 `Phase11OperationalE2ESeeder` をローカルで実行した。後者はlocal/testing以外で拒否され、トランザクション内で作成し、同じ識別子があれば再作成しない。`DatabaseSeeder` には登録しない。

再現コマンド（ローカルのみ）:

```sh
./vendor/bin/sail artisan db:seed --class=Phase11OperationalE2ESeeder --no-interaction
```

検証日: 2026-09-15（顧客統計の観察基準日: 2026-09-25）。専用の顧客A〜E、担当A/B、メニュー、ブース、税区分・税率を作成し、予約→Visit→施術担当→Checkout→支払内訳→完了の既存サービスを通して確定。顧客Aの次回予約は2026-10-02。税率1000bpは検証用TaxRateで、業務税率マスタを確定・変更する値ではない。予約台帳は既存仕様のJST壁時計文字列、施術実績はUTC instantで記録する。

| シナリオ | 検証値 |
|---|---|
| A初診・指名・次回予約 | 45分、来店・新規・初診・次回予約・初診予約・担当A人数が各+1 |
| Bロング | 75分、ロング+1 |
| C複数担当・分割決済 | 11:30〜12:30の60分をA30分/B30分、人数は主担当Aのみ+1。11,000円 = 現金5,000円 + PayPay6,000円 |
| D再診・離反 | 7月来店あり、8月なし、9月来店あり。再診・離反各+1 |
| E2回目到達 | 9月に初回と2回目の完了来店 |
| 担当A勤怠・予約不可 | 9/15実勤怠09:00〜18:00、休憩12:00〜13:00で出勤480分。15:00〜16:00と12:30〜13:30の予約不可を重複控除せず、店舗営業時間を加味した予約可能時間330分 |

検証データのみでは9月の完了Visit6件、ロング1件、決済日売上49,500円（現金43,500円、PayPay6,000円）、税4,500円、新規4人、再診1人、離反1人、2回目到達1人。9/15単日では完了Visit4件、ロング1件、次回予約1件、初診3件、初診予約1件、M分類4件、売上38,500円。担当Aは9月主担当5人・稼働165分、担当Bは主担当1人・稼働105分。時間帯境界を跨ぐCの30分ずつは、10〜12にA30分、12〜15にB30分として計上される。

ローカルDBには開始前から別の完了Visitが1件ある。したがって画面の9月合計は検証データのみの値に+1件され、月計では来店7件／新規5人になる。既存値を消したり検証値へ合わせたりしていない。

cleanupは自動実行しない。必要時はバックアップ取得後、`phase11_test_*@example.invalid` のuser、`PHASE11_TEST_` の予約notes / operation key / マスタ名など対象IDを読み取り確認し、依存関係と完了事実の削除制約をレビューした専用手順を別途承認する。実顧客や実予約を含む広域deleteは禁止。

## 確認区分

| 区分 | 結果 |
|---|---|
| 自動テスト | 開発管理者の5画面・5API 200、権限なしstaffの403、ナビ表示条件、新permission同期、上記E2Eの39 assertion、予約から自動生成する施術時刻の時間帯分割を確認。既存Phase 11集計・Excelテストを維持。 |
| ブラウザ実操作 | ローカル開発管理者でログイン。管理ナビ「集計」を開き月計へ遷移し、月計・顧客統計・スタッフ稼働・時間帯別稼働・年間集計のVue表示を確認。予約台帳9/15に `PHASE11_TEST_` の完了予約4件と日次売上38,500円が表示。 |
| Excel実ダウンロード | 月計画面で `as_of_date=2026-09-15` の `ARK自由が丘店2026.09.xlsx` をダウンロード。6シート名、ZIP整合性、結合セル数、既存列幅・行高、代表セルの数値形式、月計9/15の38,500円・来店4・ロング1、翌16日の実績空欄を確認。原本とリポジトリ内テンプレートのSHA-256は共に`739c6dd1655ced0e4f7c47c3cb7e595a2f52d78ba3a8480fe55d2f438268c9d6`のまま。 |
| 未確認 | 旧Excel/Google Sheetsの入力済み実績と、同期間ARK実績の実数値照合。提供2026.10原本には実績がなく、Google Sheetsも未提供。Task 11-13の該当項目はBLOCKEDのまま。 |

最終検証: PHP全回帰 `1,079 tests / 6,631 assertions` 成功。Frontend全テスト `15 files / 66 tests` 成功。`npm run build` に含まれる `vue-tsc --noEmit` とproduction Vite build成功。Pint成功、`git diff --check`成功。Phase 11関連の来店完了・時間帯別・検証Seederを対象にした集中テストは `23 tests / 140 assertions` 成功。Google Sheets書込み、SALON BOARD接続、コミット、pushは行っていない。
