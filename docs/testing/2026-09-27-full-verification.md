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

アプリ内ブラウザ（Chromium）を PC 幅（1422px 実表示。1280 / 1920px はエミュレーション）で実操作した。ログインはユーザーが開発管理者で行った。データは開発DBに `ST_TEST_` 始まりのスタッフ3名・ブース3（トレーニングA/B・ベッド）・メニュー6（パーソナル60、長い名前のコンディショニング60、T30/M15/A15、はり30。A とはり30は「はり師」必須、スタッフAだけ保有）・商品・回数券商品・顧客8名を追加して使った（既存データは変更していない。税区分は既存の `PHASE11_TEST_TAX` 10%）。対象日は 2026-10-02。

| ID | シナリオ | Result | 実際の操作と結果 |
|---|---|---|---|
| ST-01 | 通常予約 | PASS | 顧客検索→メニュー→日付→時刻→担当（指名）→ブース自動提案（トレーニングA）→インターバル5分→作成。確認欄「14:00〜15:00・スタッフA（指名）・トレーニングA・終了後5分」。ボードに「14:00–15:00」＋斜線の終了後区間。DB: 14:00〜15:05・buffer 5・枠26件 |
| ST-02 | 夫婦同時パーソナル | PASS（不具合1を修正後） | スタッフB 14:00 でトレーニングBへ自動確定し作成、同時刻に2件表示。**修正前は1件目作成後もパネルの下書き（顧客・メニュー・指名・ブースA）が残り、スタッフBの14:00が「空いていません」と誤表示された（不具合1）** |
| ST-03 | ブース不足 | PASS（改善候補あり） | スタッフC（施術可・勤務中）では14:00〜14:55が時刻候補に出ず15:00から選べる。空き枠クリックでは「14:15 はこのメニュー・担当では空いていません」。理由（ブース不足）までは示さない（改善候補2） |
| ST-04 | インターバル | PASS | スタッフAの次の空きは15:05。15:00は出ない。14:05開始にならない |
| ST-05 | メニューで空きを確認 | PASS | 長いメニュー名は候補一覧・選択欄とも折り返して全文表示。緑の空き枠クリックで、メニュー・スタッフC・時刻が入った予約パネルが直接開く（ブースも自動提案） |
| ST-06 | 左予約パネル | PASS | 顧客→メニュー→日付→開始時間→担当・指名→ブース→インターバル→備考。「スタッフ予定を追加」は右寄せの小テキスト、「予約を作成」は塗りの主ボタン。空き枠選択でも「予約を作成」が主、「スタッフ予定（休憩など）」は補助 |
| ST-07 | 行高 | PASS | スタッフ／ブース／両方とも行 80px、台帳下端→本日の集計 22px |
| ST-08 | はり資格 | PASS | 実施施術で資格なしスタッフを担当にすると保存不可「ST_TEST_スタッフB さんは『ST_TEST_はり30』に必要な資格が登録されていないため担当できません。」。資格ありは保存可。担当候補自体は絞らない（保存時に検証） |
| ST-09 | コンディショニング構成 | PASS | 同じ来店で T60 → T45+M15 → T30+M15+A15（Aは資格ありスタッフA）の順に保存成功。合計が予約を超えると即時に「予約の時間を超えています…延長してから」 |
| ST-10 | 延長 | PASS | 三点メニュー「延長」+30分・はり30 → 14:00〜15:30（終了後5分）。後続予約（15:40）がある状態で再延長 → 「指定の時間帯は既に予約されています」、DB不変（90分） |
| ST-11 | 来店・会計導線 | PASS | 予約詳細の主ボタンが「来店・会計」、副が「予約編集」「…」。会計なし完了は「…」内 |
| ST-12 | 通常会計 | PASS | 施術1をA30/B30の2名、はり30（A）、指名A、物販1,100、担当時間按分 4,400/4,400、現金5,000＋PayPay4,900（うち物販1,100）→保存→完了。DB: 9,900＝支払合計、支払配分（現金: 施術5,000／PayPay: 施術3,800・物販1,100）、税 800+100、施術ブース＝トレーニングA、Bの開始14:30連続 |
| ST-13 | 会計不要 | PASS（不具合3を修正後） | 「会計なしで来店完了」→理由必須（無料施術）→完了。checkout 0件、理由保存、監査あり。**修正前は来店の営業日が完了操作日（9/27）になり、予約日（10/2）の日計に入らなかった（不具合3）**。修正後に別予約で再実施し 10/2 で計上 |
| ST-14 | 予約なし来店 | PASS | 一覧「予約なし来店」→顧客→施術T30（B）→施術料→按分→現金5,500→完了 |
| ST-15 | 来店なし物販 | PASS | 「店頭販売」→プロテイン×2 2,200→確定。日計明細の「店頭販売（来店なし）」に出て来店数は増えない |
| ST-16 | 回数券 | 購入 PASS／利用 BLOCKED | 店頭販売で回数券4回 33,000 を確定→台帳に+4付与（明細IDで冪等）。管理画面の予約は常に現地払いで、店頭で回数券を使う操作が画面に無い（利用は回数券払いのオンライン予約か、パスワード再確認付きの回数券調整）。自動テストで予約消化は確認済み。台帳種別が `PURCHASE` でなく `GRANT`（観察4） |
| ST-17 | 月計 | PASS | 10月2日行：施術等 現金54,500／PayPay3,800、施術等計（税抜）53,000、物販 現金2,200／PayPay1,100、税抜56,000・税5,600・税込61,600、ロング1、来店は修正後4 |
| ST-18 | 日計明細 | PASS | 「R8.10」相当の1来店1行（予約メニュー／実施施術／主担当／実担当／指名／物販／支払方法／次回予約／新規／性別／年代）、会計不要は「会計なし（回数券・月額の利用）」、店頭販売は別表。入力不要 |
| ST-19 | 顧客統計 | PASS | 観察基準日10/31で新規4（男3・女1、40代）、初回担当 A1・B1・C2、次回予約なし4 |
| ST-20 | スタッフ売上 | PASS | 30/30 → 4,400/4,400、45/15 → 8,250/2,750。合計25,300＝施術明細合計。指名売上4,400（指名A） |
| ST-21 | 稼働率 | PASS | 10/2 A 75分・1人・指名1、B 60分・1人、C 105分・2人 |
| ST-22 | 時間帯 | PASS | 10/2 12–15: 120分、15–18: 90分、18–21: 90分（施術の実時刻どおり。12–15 には不具合3の影響を受けた旧来店の60分を含む）。単一区間の跨ぎ（14:45〜15:45）は IT で確認 |
| ST-23 | 年間 | PASS（観察5あり） | 年度（4月〜翌3月）の10月: 決済日売上61,600・来店4・ロング1・初診4・新規4。施術日売上は14,300（観察5） |
| ST-24 | Excel | PASS | 画面のダウンロードは200・xlsx・`ARK自由が丘店2026.10.xlsx`。同じ出力サービスで6シート、月計表2日行 C=49,000・D=3,800・J=48,000・K=2,200・P=3,000・Q=51,000、数値K5=51,000（税抜）が void 後の Web 月計と一致 |
| ST-25 | キャンセル・無断キャンセル | PASS | 理由付きキャンセル、無断キャンセルを画面で実行。来店・売上に入らず、予約分析 canceled 1・no_show 1 |
| ST-26 | void | PASS | 確定会計を理由付きで取消→売上 61,600→56,100、スタッフB 9,900→4,400、来店数は残る、監査 `checkout.voided` |
| ST-27 | 権限（一般スタッフ） | BLOCKED | 一般スタッフでのブラウザログインが必要（パスワードを扱えないため未実施）。`AdminRouteAccessMatrixTest`・`CheckoutEntryTest`・`ExtensionAndCompositionTest` で 403 を確認済み |
| ST-28 | セッション | 静的確認 PASS／明示ログアウト BLOCKED | 無操作ログアウトのコードなし（`sessionKeepAlive.ts` のみ、401/419 でログインへ）。明示ログアウトはユーザーのセッションを切るため未実施。`AdminAccessTest`（10時間後もログイン維持）で確認済み |
| ST-29 | 戻る | PASS（観察6） | ボード（ブース軸・10/2・予約詳細）→月計→戻るで同じ状態に復元。月計はURL正規化で履歴が1件増え、戻るに2回必要 |
| ST-30 | Reports 移動 | PASS | 月次レポートのタブ（概要〜コース・物販）が対象年月を保って移動 |
| ST-RESP | 1280 / 1440 / 1920px | PASS（不具合4を修正後） | 主要画面（ボード・会計入力・月計・日計明細）で横はみ出しなし。**1280px ではヘッダーの「システム」メニューがタブ列の外に押し出され操作不能だった（不具合4）** |
| ST-ERR | エラー表示 | PASS | 検証エラー・資格エラー・支払不一致・予約競合（409）はすべて日本語メッセージ。存在しない来店は日本語404。**Referer の無い遷移の検証エラーで生の JSON 画面へ戻る不具合を発見（不具合2）** |

## データ整合性（開発DB `ark_app`、読み取りのみ）

すべて0件：VisitなしのVisitTreatment、確定会計の合計と支払合計の不一致、支払と支払配分の不一致、明細と担当売上配分の不一致、存在しないブース参照（予約・施術）、紐付け外ブースの有効予約、資格条件違反の有効予約、同一リソース・同一枠の重複、施術分数と担当分数の不一致、施術なしのcompleted visit（会計不要理由なし）。

「完了予約なのにVisitなし」は180件ある。`DemoScenarioSeeder` の旧デモデータ（Visit Fact 導入前に作成）で、`docs/DB_SCHEMA.md` の legacy completed（visitなし、再完了しない）に当たる。不整合ではない。

## 発見事項

### 不具合（修正済み・再発防止テストあり）

| # | 重大度 | 内容 | 原因 | 修正（責務） | 再発防止テスト |
|---|---|---|---|---|---|
| 1 | 高 | 予約作成後も新規予約パネルに前回の顧客・メニュー・指名・ブースが残る。次の予約で前回のブースが空き判定に使われ、空いているスタッフが「空いていません」になる。別の顧客で誤って予約する恐れ。予定追加パネルも同じ | 作成成功でサーバーが台帳へリダイレクトし、パネルは `onSuccess` より先にアンマウントされる。完了を `emit('created')` で親へ伝えていたが、Vue はアンマウント後の emit を捨てるため、下書きのクリアと戻る履歴のクリアが一度も実行されていなかった | 完了処理を emit ではなく関数 prop `afterCreate` で受け取り、アンマウント後でも親の処理を呼ぶ（`NewReservationPanel.vue` / `ScheduleBlockCreatePanel.vue` / `Schedule/Index.vue`） | `resources/js/components/admin/createPanelCompletion.spec.ts`（アンマウント後の onSuccess で afterCreate が呼ばれる。修正前 FAIL を確認） |
| 2 | 中 | 予約パネル等を開いた後、Referer の無い画面遷移（URL直接入力・ブックマーク）が検証エラーで `back()` すると、生の JSON 画面（`/admin/reservations/{id}/panel`）へ戻され、他の URL へ移動しても戻され続ける | 画面の `fetch()` は `X-Requested-With` を付けないため、Laravel が JSON の GET を「直前の URL」としてセッションに保存していた | サーバー側で、JSON を求める GET（`expectsJson()`）を直前の URL に記録しない `App\Http\Middleware\StartSession` に差し替え（`bootstrap/app.php`）。20ファイルの fetch を個別に直すより確実 | `tests/Feature/Security/PreviousUrlIgnoresJsonTest.php`（修正前 FAIL を確認） |
| 3 | 高 | 「会計なしで来店完了」（下書きなしの完了）で、来店の営業日が予約日ではなく完了操作の日になる。閉店後・翌日にまとめて完了した来店が翌日の日計・月計に入り、施術実績（予約日時刻）と日付が食い違う。同じ予約でも「来店・会計」を先に開いたかで営業日が変わっていた | `VisitCompletionService` は `businessDate(now)`、`CheckoutEntryService` は予約日を使っていた | 予約ありの来店は予約日（JST 壁時計）に統一（Domain）。仕様を `docs/CHECKOUT_ENTRY.md` に追記。既存テスト2件の期待値を新しい規則へ更新（1件は日付跨ぎの年齢計算の意図を保つよう予約日を調整） | `VisitCompletionServiceTest::test_late_completion_keeps_the_reservation_date_like_the_checkout_entry_path`（修正前 FAIL を確認） |
| 4 | 中 | 1280px でヘッダーの「システム」（システム状態・失敗ジョブ・監査ログ・外部予約連携）がタブ列の外に押し出され、スクロールバー非表示のため操作できない | 8タブ＋右側（時計・氏名・ログアウト）が 1280px に収まらない | 1440px 未満はタブの左右余白を 16px→8px（`AdminLayout.vue`） | レイアウト計算はjsdomで検証できないため、ブラウザ実測を回帰手順とする：1280pxで `nav.ark-topnav` の scrollWidth＝表示幅（653px）、「システム」中央の要素が当該ボタン |

### 観察・改善候補（今回は修正していない）

| # | 内容 | 影響 | 次の対応 |
|---|---|---|---|
| 観察1 | ブースを明示指定して他予約と重なる場合は409「指定の時間帯は既に予約されています」、自動確定で空きなしは422「ブースが空いていません」 | 二重予約はDB制約で防止済み。文言がスタッフ／ブースの別を示さない | 必要なら競合リソース別の文言（仕様判断） |
| 改善候補2 | 空き枠クリックで取れない時刻の文言が「このメニュー・担当では空いていません」のみで、ブース不足という理由が分からない（ST-03） | 受付担当が原因を探す手間 | 空き判定APIが不可理由を返す拡張（新機能のため今回は見送り） |
| 観察3 | 会計不要で完了した来店が「来店・会計」一覧（日別）に出ない（日計明細・月計には出る） | 当日の来店一覧として見ると1件少なく見える | 一覧の対象を「来店＋店頭販売」にするか仕様判断 |
| 観察4 | 店頭で購入した回数券の台帳種別が `PURCHASE` ではなく `GRANT`（理由「会計#n」で追跡可） | 無償付与と購入の区別を種別だけで行う分析ができない | 種別の使い分けを仕様判断（既存台帳の意味に関わるため今回は変更しない） |
| 観察5 | 施術料明細で「対象施術」を外して全施術で按分すると、その明細は施術日売上（Phase 11 定義：施術直結明細のみ）から外れ、不明としても表示されない（ST-23 で 11,000円） | 施術日基準の売上が過少。決済日基準は正しい | 対象施術を必須にする（UI/Domain）か、来店の営業日で認識するか、定義の判断が必要 |
| 観察6 | 月計はURLの並び替えで履歴が1件増え、戻るに2回必要 | 軽微 | `replace` での正規化 |
| 観察7 | 店頭（管理画面）予約は常に現地払いで、店頭で回数券を使う操作が画面に無い（ST-16） | 回数券利用は手動調整（パスワード再確認）が必要 | 店頭の回数券利用導線（新機能） |
| 観察8 | 新規予約パネルの日付は台帳の表示日ではなく今日が初期値。実施施術の追加行は開始時刻が予約開始のまま、メニューは先頭の実メニュー | 入力の手間 | UI改善候補 |
| 観察9 | 開発DBの実メニュー・回数券商品は税区分未設定、`standard` 税率も未登録のため、実メニューでは会計確定できない | 開発環境のみ。本番前に税率マスタ確定が必要 | 税率マスタの確定（店舗判断） |
| 観察10 | 既存の開発データで来店の営業日と予約日が異なる来店が2件（id=1 既存、id=12 は本検証で不具合3の修正前に作成）。完了済み来店は変更不可のため補正していない | 当該2件のみ | 必要なら原資料で確認 |
| 観察11 | `GoogleAuthController` のコメントに廃止済み「Idle Timeout」の名前が残る | なし | — |
| 環境 | ベースライン回帰の1件（`AdminAccessTest`）はローカル `vendor` の古いクラスマップ（削除済み `AdminIdleTimeout.php`）が原因。`composer dump-autoload -o` で解消。17件は検証者がテストを同時実行して `testing` DB を奪い合った手順ミスで、単独再実行で PASS | コード不具合ではない | — |

## 回帰

| 実行 | 対象 | 結果 |
|---|---|---|
| ベースライン | `ecde6a3` | 1,145 tests / 1,127 passed / 18 errors（すべて環境要因。上表「環境」） |
| ST 前 | テスト追加後（`898c304`） | PHP 1,160 / 1,160 passed、Frontend 112 / 112 |
| **最終** | 不具合1〜4の修正・再発防止テスト追加後の作業ツリー（このコミット群） | 下表 |

| 項目 | 結果 |
|---|---|
| PHP 全テスト（`sail artisan test`、最初から完全実行） | **1,162 tests / 1,162 passed / 0 failed**（173,561 assertions、約22.5分） |
| Frontend（`sail npm run test`） | **114 / 114 passed**（28 files） |
| 型チェック＋本番ビルド（`sail npm run build`＝`vue-tsc --noEmit && vite build`） | GREEN |
| Pint（`sail pint --test`） | GREEN |
| `git diff --check` | GREEN |

関連テスト（修正ごと）: 不具合1 `createPanelCompletion.spec.ts` 2件、不具合2 `PreviousUrlIgnoresJsonTest` 1件、不具合3 Visit／BusinessFacts／Checkout／Reporting／Admin予約／延長 236件。
