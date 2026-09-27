# ARK 全面検証（2026-09-27）

予約 → ブース/スタッフ判定 → 来店 → 実施施術 → 会計 → 決済 → 日計 → 月計 → 各種分析 → Excel が、同じFactを使って一貫して動くことを確認した記録。新機能は追加していない。

- 開始 commit: `ecde6a3`（`origin/main` と一致、作業ツリー clean）
- migration: Pending 0（直前に `2026_09_27_000006`〜`000009` を通常の `migrate` で適用済み。destructive 操作なし）
- 自動テストは `testing` DB（`RefreshDatabase`）で実行した。開発DB `ark_app` は読み取り専用の整合性確認と、ブラウザ ST 用の `ST_TEST_` データだけに使う。
- 本番DB・外部サービス・Stripe Live には接続していない。

## 凡例

- Layer: Unit（Domain / Service / Query 単位。Laravel の Feature テストで DB を使うものを含む）/ Integration（HTTP・権限・DB・集計をつなぐ）/ System（実ブラウザ）
- Result: PASS / FAIL / BLOCKED
- 「既存」は今回より前からあるテスト、「追加」は今回追加したテスト。

## 1. Unit

### Availability / Booth

| ID | 対象 | 前提 | 操作 / Input | Expected | Actual | Result | テスト |
|---|---|---|---|---|---|---|---|
| UT-B01 | 空き枠 | パーソナル＝トレーニングA/B紐付け、両方空き | スタッフBで14:00の空き | 14:00あり | 14:00あり | PASS | 追加 `BookingVerificationMatrixTest::test_ut_b01_to_b03_*` |
| UT-B02 | 空き枠 | Aのみ使用中 | 同上 | 14:00あり（Bで取れる） | 14:00あり | PASS | 同上 / 既存 `BookingResourceTest::test_case_a_*` |
| UT-B03 | 空き枠 | A・B両方使用中 | 同上 | 14:00なし、15:00はあり | 同左 | PASS | 同上 / 既存 `test_case_b_*` |
| UT-B04 | ブース確定 | 紐付けあり、ブース未指定、終了後5分 | 14:00予約 | `booth_id`＝A、`reservation_resource_slots` の booth 枠13件がすべてA、staff 枠13件 | 同左 | PASS | 追加 `test_ut_b04_*` |
| UT-B05 | 二重予約禁止 | Aを14:00〜15:00で使用中 | 別スタッフでA指定14:00／14:30 | 拒否 | 拒否 | PASS | 追加 `test_ut_b05_b06_*`、既存 `ReservationConcurrencyTest::test_same_booth_*` |
| UT-B06 | 別ブース同時刻 | 同上 | 別スタッフでB指定14:00 | 予約可 | 予約可（同時刻2件） | PASS | 追加 `test_ut_b05_b06_*` |

### Staff capability / Qualification

| ID | 対象 | 前提 | 操作 / Input | Expected | Actual | Result | テスト |
|---|---|---|---|---|---|---|---|
| UT-S01 | 施術可能スタッフ | `service_staff` にA/B | 予約パネル選択肢 | `staff_ids`＝[A,B]、`booth_ids`＝[A,B] | 同左 | PASS | 追加 `test_ut_s01_to_s03_*` |
| UT-S02 | 候補除外 | Cは勤務中・施術不可 | 空き枠（スタッフ未指定） | 候補にCが出ない | 出ない | PASS | 同上 |
| UT-S03 | 予約不可 | 同上 | Cで予約 | `staff_id` 422 | 同左 | PASS | 同上 / 既存 `test_case_d_*` |
| UT-Q01 | 資格あり | はり＝はり師必須、Aが保有 | Aではり予約 | 予約可、ベッド自動確定 | 同左 | PASS | 追加 `test_ut_q01_to_q03_*` |
| UT-Q02 | 資格なし | Bは未保有 | Bではり予約 | `staff_id` 422、候補にも出ない | 同左 | PASS | 同上 / 既存 `test_case_e_*` |
| UT-Q03 | 資格不要メニュー | パーソナルは必要資格なし | 資格のないBで予約 | 予約可 | 予約可 | PASS | 同上 |
| UT-Q04 | 複数資格 | はりに資格2つ | 1つだけ保有／両方保有 | 全部保有者だけ可 | 同左 | PASS | 追加 `test_ut_q04_*` |

### Buffer / Interval

| ID | 対象 | 前提 | 操作 / Input | Expected | Actual | Result | テスト |
|---|---|---|---|---|---|---|---|
| UT-I01 | 後ろバッファ | 60分・バッファ5 | 14:00予約 | 開始14:00、`ends_at`15:05、予約60分、次は15:05〜（15:00不可） | 同左 | PASS | 追加 `test_ut_i01_to_i03_*`、既存 `ExtensionAndCompositionTest::test_interval_*` |
| UT-I02 | バッファ0 | 11:00予約 | 次の空き | 12:00から可 | 同左 | PASS | 追加 `test_ut_i01_to_i03_*` |
| UT-I03 | バッファ15 | 17:00予約 | 次の空き | 18:10不可、18:15可 | 同左 | PASS | 同上 |
| UT-I04 | 時間変更 | バッファ10＋延長30 | 編集（PUT）で16:00へ、ドラッグで18:00へ | 予約90分・バッファ10を保持、ブース再確定、旧枠解放 | 同左 | PASS | 追加 `test_ut_i04_*`、既存 `test_reschedule_keeps_the_buffer_*` |
| UT-I05 | 経路の一致 | 15:00に予約あり | バッファ5で14:00／13:55 | 空き枠と作成の判定が一致（14:00不可、13:55可） | 同左 | PASS | 追加 `test_ut_i05_*`。編集は `reschedule` を通るため create / update / reschedule / extend の計算は同じ |
| UT-I06 | 表示 | バッファ5 | 予約詳細・ボード | 「10:00〜11:00（終了後 5分）」、ボードはカード＋斜線区間 | 同左 | PASS | 既存 `ReservationDetailPanel.spec.ts`。ボードはST-04で確認 |

### Reservation segment / Extension

| ID | 対象 | 前提 | 操作 / Input | Expected | Actual | Result | テスト |
|---|---|---|---|---|---|---|---|
| UT-RS01〜03 | 構成 | コンディショニング60 | T30+M15+A15 / T45+M15 / T60 | 保存可 | 保存可 | PASS | 既存 `test_actual_composition_*` |
| UT-RS04 | 合計55 | 同上 | 55分 | 保存可（実施が予約より短いのは実績として正しい。仕様：予約時間内で自由に組み替え） | 保存可 | PASS | 追加 `test_ut_rs04_rs05_*` |
| UT-RS05 | 合計65 | 延長なし | 50+15 | `treatments` 422 | 同左 | PASS | 同上 |
| UT-RS06 / 07 | A＋資格 | Aを含む構成 | 資格なし／あり | 不可／可 | 同左 | PASS | 既存 `test_actual_composition_*` |
| UT-E01 | 延長 | 14:00〜15:00 | A30延長 | 14:00〜15:30 | 同左 | PASS | 既存 `test_extension_adds_*` |
| UT-E02 | スタッフ競合 | 後続予約あり | 延長 | 不可、予約不変 | 同左 | PASS | 既存 `test_extension_is_refused_*` |
| UT-E03 | ブース競合 | スタッフ空き、ブース後続あり | 延長 | 不可 | 同左 | PASS | 追加 `test_ut_e03_e04_*` |
| UT-E04 | 両方空き | 後続予約をキャンセル | 延長 | 可 | 同左 | PASS | 同上 |
| UT-E05 | 延長後のバッファ | バッファ5 | A30延長 | `ends_at`15:35、15:30は他予約不可 | 同左 | PASS | 既存 `test_extension_adds_*` |

### Checkout / Visit completion

| ID | 対象 | Expected | Result | テスト |
|---|---|---|---|---|
| UT-C01 | 予約から下書き・prefill（施術・担当・指名・時間・ブース） | 予約内容で初期表示、同じ予約は同じ下書き | PASS | 既存 `CheckoutEntryTest::test_opening_a_reservation_prefills_*`、追加 `StoreFlowVerificationTest` |
| UT-C02 | 実施施術を予約から変更 | T45+M15 等に置換 | PASS | 既存 `test_actual_composition_*`、追加 `StoreFlowVerificationTest` |
| UT-C03 / 04 | 複数担当・複数指名 | 担当・指名を別に保存 | PASS | 既存 `test_reservation_visit_with_multiple_*` |
| UT-C05 / 06 / 07 | 施術＋物販、分割払い、支払配分 | 12,100＝現金5,000＋PayPay7,100（うち物販1,100） | PASS | 追加 `StoreFlowVerificationTest`、既存 `test_mixed_checkout_*` |
| UT-C08 | 税額snapshot | 明細ごと切り捨て、税率保存 | PASS | 既存 `test_tax_is_split_*`、`CheckoutServiceTest` |
| UT-C09 | 確定の冪等性 | 二重送信で1件 | PASS | 既存 `CheckoutServiceTest::test_total_mismatch_rolls_back_*`、`test_visitless_sale_*`（二重確定）、追加（完了の二重送信） |
| UT-C10 | void | 理由必須・権限・監査 | PASS | 既存 `test_finalized_checkout_is_locked_*` |
| UT-C11 | 会計不要 | 理由なし／不正は422、理由を保存、会計を作らない | PASS | 既存 `AdminReservationManagementTest::test_completion_without_checkout_*` |
| UT-C12 | Visitなし物販 | 来店数0、売上あり | PASS | 追加 `test_store_sale_without_visit_*` |
| UT-V01 | 次回予約snapshot | 後日の予約作成・取消で変わらない | PASS | 既存 `VisitCompletionServiceTest::test_snapshot_never_changes_*` |

### Reporting

| ID | 定義 | Result | テスト |
|---|---|---|---|
| UT-RP01 | 来店数：1 Visit＝1、複数担当でも1 | PASS | 既存 `VisitFactServiceTest::test_long_visit_boundary_*`、`StaffUtilizationServiceTest::test_primary_visit_counts_*` |
| UT-RP02 | ロング：実施合計60はfalse、61はtrue | PASS | 既存 `VisitFactServiceTest`、`VisitCompletionServiceTest::test_preentered_multiple_*` |
| UT-RP03 | 初診・新規・再診・離反 | PASS | 既存 `CustomerAnalyticsServiceTest` |
| UT-RP04 | 継続 2 / 6 / 10回 | PASS | 既存 `VisitCompletionServiceTest::test_visit_sequence_*`、`CustomerAnalyticsServiceTest::test_cohort_reach_*` |
| UT-RP05 | 売上 gross / net / tax、決済日／施術日 | PASS | 既存 `AnnualReportServiceTest::test_payment_and_treatment_basis_*`、追加 `StoreFlowVerificationTest`（salesSplit） |
| UT-RP06 | 時間帯：重なり分数 | PASS | 既存 `TimeBandUtilizationServiceTest`（半開区間・日跨ぎ） |
| UT-RP07 | 稼働率 legacy / operational | PASS | 既存 `StaffUtilizationServiceTest::test_480_work_minutes_*` ほか |

## 2. Integration

| ID | 対象 | 操作 / Input | Expected | Actual | Result | テスト |
|---|---|---|---|---|---|---|
| IT-R01 | 予約作成API | 14:00 パーソナル スタッフA トレーニングA | 作成 | 作成 | PASS | 追加 `test_it_r01_to_r06_*` |
| IT-R02 | 夫婦同時 | 14:00 スタッフB トレーニングB | 作成（同時刻2件） | 同左 | PASS | 同上 |
| IT-R03 | ブース使用中 | スタッフD（空き・可）でB指定 | 失敗 | 409「指定の時間帯は既に予約されています」。ブース未指定なら422「ブースが空いていません」 | PASS | 同上 |
| IT-R04 | スタッフ使用中 | スタッフBでトレーニングC（空き） | 失敗 | 409 | PASS | 同上 |
| IT-R05 | 施術不可 | スタッフC・Cブース空き | 失敗 | 422 `staff_id` | PASS | 同上 |
| IT-R06 | 資格なし | 資格のないスタッフではり | 失敗 | 422 `staff_id` | PASS | 同上 |
| IT-CC | 同時リクエスト | 同スタッフ／同ブース／重なり時間 | 1件だけ成功 | 同左 | PASS | 既存 `ReservationConcurrencyTest`（6件）、`VisitCompletionConcurrencyTest` |
| IT-AV | 空き枠API | メニューのみ／＋スタッフ／＋ブース／＋両方、`with_booths` | 紐付けを使う。1つ目のブースだけで判定しない。紐付け外のベッドは不可 | 同左 | PASS | 追加 `test_availability_api_combinations_*` |
| IT-RSCH | 時間変更 | 編集・ドラッグ | スタッフ・ブース・バッファ・延長分・空きの再計算 | 同左 | PASS | 追加 `test_ut_i04_*` |
| IT-PM | 権限 | migration後の既存ロール | admin に全権限、`reservations.manage` を持つロールへ `checkouts.manage`。staff には付与しない。seeder 再実行不要 | 同左 | PASS | 既存 `CheckoutPermissionProvisioningTest`、`RolePermissionSeederTest`、`AdminRouteAccessMatrixTest` |
| IT-CO | 会計一気通貫 | 予約→来店→施術（T45 A／M15 B、ブース）→明細→支払→配分→確定 | 全FK・金額・status 整合（会計＝支払合計、支払＝配分、明細＝担当配分） | 同左 | PASS | 追加 `StoreFlowVerificationTest` |
| IT-RP | 集計反映 | 上の1会計＋キャンセル＋無断キャンセル＋来店なし物販、のち void | 日計・月計・予約分析・顧客・スタッフ売上（75/25）・時間帯（12〜15に15分、15〜18に30分＋15分）・年間（暦年／年度）・Excel（6シート、月計表C17＝5,000、J17＝10,000、数値K18＝13,000 税抜）。void後は売上・スタッフ売上から外れ、来店数は残る | 同左 | PASS | 追加 `StoreFlowVerificationTest` |
| IT-AU | 監査 | 予約作成・変更・延長・資格/ブース紐付け変更・施術保存・確定・取消 | 既存の監査アクションが記録される | 同左 | PASS | 既存 `AdminReservationManagementTest`、`BookingResourceSettingsTest`、`ExtensionAndCompositionTest`、`CheckoutEntryTest`、追加 `StoreFlowVerificationTest` |
| IT-DI | 開発DBの整合性 | `ark_app` に読み取り専用SQL | 孤立施術・支払不一致・配分不一致・存在しないブース・紐付け外ブース・資格違反予約・同一リソース重複・施術と担当分数の不一致が0 | すべて0 | PASS | 下記「データ整合性」 |

## 3. System（実ブラウザ）

ブラウザ ST は開発管理者でのログインが必要。自動化側から `.env` のパスワードを読むことは権限上許可されなかったため、ユーザーがブラウザペインでログインするまで BLOCKED とする。

| ID | シナリオ | Result | 備考 |
|---|---|---|---|
| ST-01〜ST-30 | 予約・夫婦同時・ブース不足・バッファ・メニュー空き・予約パネル・行高・はり資格・構成・延長・来店会計・通常会計・会計不要・予約なし来店・物販・回数券・月計・日計・顧客・スタッフ売上・稼働率・時間帯・年間・Excel・キャンセル/無断・void・権限・セッション・戻る・Reports移動 | BLOCKED | ログイン待ち。サーバー側の同等経路は Integration で PASS |

## データ整合性（開発DB `ark_app`、読み取りのみ）

すべて0件：VisitなしのVisitTreatment、確定会計の合計と支払合計の不一致、支払と支払配分の不一致、明細と担当売上配分の不一致、存在しないブース参照（予約・施術）、紐付け外ブースの有効予約、資格条件違反の有効予約、同一リソース・同一枠の重複、施術分数と担当分数の不一致、施術なしのcompleted visit（会計不要理由なし）。

「完了予約なのにVisitなし」は180件ある。`DemoScenarioSeeder` の旧デモデータ（Visit Fact 導入前に作成）で、`docs/DB_SCHEMA.md` の legacy completed（visitなし、再完了しない）に当たる。不整合ではない。

## 発見事項

| # | 重大度 | 内容 | 原因 | 対応 | 再発防止テスト |
|---|---|---|---|---|---|
| 1 | 低（ローカル環境） | `AdminAccessTest::test_admin_routes_have_no_idle_timeout_middleware` がエラー | `optimize-autoloader` のクラスマップに削除済み `AdminIdleTimeout.php` が残っていた（ローカル `vendor` が古い）。コードの不具合ではない | `sail composer dump-autoload -o`（`vendor` は git 管理外） | 既存テストがそのまま検知する |
| 2 | 情報 | ブースを明示指定して他予約と重なる場合は409（DB一意制約）、自動確定で空きなしは422 | 設計どおり（重複はDB制約で保証）。メッセージは「指定の時間帯は既に予約されています」で、スタッフとブースのどちらが原因かは区別しない | 変更なし（UI改善候補） | 追加 `test_it_r01_to_r06_*` で挙動を固定 |
| 3 | 情報 | `GoogleAuthController` のコメントに廃止済み「Idle Timeout」の名前が残る | コメントのみ | 変更なし | — |

## 回帰

`docs/testing` 追記時点の結果は末尾「最終回帰」に記録する。

## 最終回帰（ST前・テスト追加後の作業ツリー、ベース `ecde6a3`）

| 項目 | 結果 |
|---|---|
| PHP 全テスト（`sail artisan test`、最初から完全実行） | 1,160 tests / 1,160 passed / 0 failed（173,551 assertions、約23.6分） |
| Frontend（`sail npm run test`） | 112 / 112 passed（27 files） |
| 型チェック＋本番ビルド（`sail npm run build`＝`vue-tsc --noEmit && vite build`） | GREEN |
| Pint（`sail pint --test`） | GREEN |
| `git diff --check` | GREEN |

ブラウザ ST 後にコード修正が発生した場合は、PHP 全テストを最初から再実行してここを更新する。
