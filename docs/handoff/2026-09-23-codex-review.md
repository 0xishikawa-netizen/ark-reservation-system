# Codex 依頼：2026-09-23 セッション変更の総点検・コードレビュー

あなた（Codex）は本リポジトリの実装担当。まず `AGENTS.md` を読むこと（作業ルール・禁止事項・メッセージ集約ルールあり）。
コミット・push はしない。変更はワークツリーに残し、最後に「変更ファイル一覧・実行コマンドと結果・指摘と対応・残課題」を短くまとめる。

## 目的

2026-09-23 に Claude Code が入れた変更（未コミット・ワークツリー上）を、機能面とコード品質の両面で点検し、
問題があれば最小限の修正を入れる。仕様変更・大規模リファクタはしない（気になる点は「残課題」に書く）。

## 必須の確認コマンド（すべて Sail 経由）

- `./vendor/bin/sail artisan test`（全件。**他のテストと同時に流さない**＝同じ testing DB を使うため衝突する）
- `npx vue-tsc --noEmit`（型エラー 0）
- `./vendor/bin/sail bin pint --test`（変更した PHP のみでよい）
- 参考：vitest はホストの rolldown ネイティブバインディング不備で起動しない既知問題あり（直せるなら直す、無理なら残課題へ）

## 今回の変更点と、重点的に見てほしいところ

### 1. ブッキングボード（`resources/js/Pages/Admin/Schedule/Index.vue`）
- 左パネルの高さ：右側 `.board-layout__main` の高さ（本日の集計の下端）に ResizeObserver で合わせる（`recalcPanelMaxHeight`）。狭い画面（≤1023px, オーバーレイ）では inline height を当てない。
  - 確認：ブース表示・週表示・パネル種別切替・画面リサイズで高さが追従するか。中身が長い時にパネル内スクロールになるか。
- 再予約（引用）の「日時を選ぶモード」：`slotPickActive` / `slotPickGhostVisible` / `fillCreatePanelFromSlot` / `releaseSlotPick`。
  - 再予約時に「メニューで空きを確認」（`previewServiceId`）を自動セット、日時確定時に自動解除。
  - 解除は画面下中央のバー（`.slot-pick-bar`）と Esc。日付跨ぎ移動も同形の別色バー（`.slot-pick-bar--move`）。
  - 確認：日付移動しても pf_customer_id / pf_service_id が URL に残りモードが継続するか。解除後に新規予約パネルが手入力モードに戻り、draft（顧客・メニュー）が残るか。Esc の優先順位（日付跨ぎ移動 > 枠選択 > パネル閉じ）。
- 新規予約パネルは `:key="createPanelKey"`（prefill の日時・担当・ブース）で作り直す。空き枠クリックで開始時間が確実に反映されるか。取れない時刻は「HH:MM はこのメニュー・担当では空いていません」表示。
- ドラッグ移動・予定移動の onError は `errorBag: "reservation"` + `firstErrorMessage()`（`resources/js/composables/inertiaErrors.ts`）。生 JSON が出ないこと。
- 「メニューで空きを確認」は `with_booths=1` でブースも考慮（スタッフ行＝そのスタッフ空き＋空きブース1つ以上、ブース行＝そのブース空き）。

### 2. パネル共通（`resources/js/components/admin/PanelShell.vue`）
- `overscroll-behavior: contain` を本文が実際にスクロールできる時だけ付与（ResizeObserver で判定）。スクロール不要時にホイールがページへ伝わるか。

### 3. 予約詳細（`resources/js/components/admin/ReservationDetailPanel.vue`）
- 「さらに詳細を見る」を廃止し、経路・支払い・金額を常時表示。
- キャンセル/無断キャンセル/完了の失敗時に `actionError` をパネル上部に表示（以前は無表示）。

### 4. 空き時間（`app/Domain/Reservation/AvailabilityService.php`）
- `openStartTimes(..., bool $withBooths = false)`：true の時、全有効ブースを候補に `available_booth_ids` を返し、空きブースが無い時刻は除外。occupied/blocked の取得をブース配列対応にした。
  - 確認：既存呼び出し（withBooths=false）の挙動が変わっていないか。N+1 が無いか。
- `app/Http/Controllers/Admin/ReservationController.php@availability` に `with_booths` パラメータ追加。

### 5. 通知音（新規）
- 設定：`app/Domain/Notification/NotificationSettings.php`（enabled/type/volume/repeat、settings テーブル）、`app/Http/Controllers/Admin/NotificationSettingsController.php`（`/admin/settings/notifications`、`can:settings.manage`、監査ログ）、ルート `routes/web.php`、メニュー `resources/js/layouts/AdminLayout.vue`（設定 > 予約設定 > 通知設定）。
- 画面：`resources/js/Pages/Admin/Settings/Notifications.vue`、音の合成：`resources/js/composables/notificationSound.ts`（Web Audio、FM＋エコー＋コンプレッサー）。
- 鳴動：`resources/js/components/admin/ScheduleNotifications.vue`（初回取得分は鳴らさない、seenIds で二重鳴動防止、repeat=three/until_ack、他端末で既読になったら停止、unmount でタイマー解除）。
  - 確認：タイマーのリーク、until_ack の停止条件（最大5分）、自動再生制限（最初のユーザー操作で unlock）。
  - サーバーの SOUND_TYPES とフロントの NotificationSoundType が一致しているか。
- テスト：`tests/Feature/Admin/NotificationSettingsTest.php`。

### 6. メッセージ集約（新規ルール）
- サーバー：`lang/ja/messages.php` に利用者向けメッセージ約178種を集約し、`app/` 内の直書き 239 箇所を `__('messages.…')` に置換（文言は不変）。開発者向け例外（Logic/Runtime/設定検出・Stripe 内部エラー等）は意図的に対象外。
- 画面：`resources/js/constants/messages.ts`（`MESSAGES` 定数＋確認メッセージ関数2つ）に約110種を集約し、41 ファイル 122 箇所を置換。
  - 確認：置換漏れ・誤置換（テンプレート内の `{{ MESSAGES… }}` が正しく描画されるか、import 位置）、未使用キー、`as const` による ref の型狭まり（`ref<string>(MESSAGES…)` が必要な箇所）。
  - `phpunit.xml` に `APP_LOCALE=ja` を追加。
- 英語表示の解消：Fortify の status コード（`verification-link-sent` 等）を `FortifyServiceProvider::statusMessage()` で日本語化、`lang/ja.json` にメール定型文・status の訳、`lang/ja/validation.php` に不足 attributes を追加。
  - 確認：画面・メール（確認メール/パスワード再設定）に英語が残っていないか（`lang/ja.json` のキーが Laravel 13 の実際の文言と一致しているか）。

### 7. データ
- `database/seeders/DemoScenarioSeeder.php`：ARK 公式サイトの料金表に合わせたメニュー9・回数券18・月額8、スタッフ3名、ブース4（パーソナルA/B・ベットA/B）、営業時間 10:00〜21:00、シフト内・非重複の予約生成、**reservation_resource_slots も生成**（無いと DB 一意制約の重複防止が効かない）。
  - 確認：active な予約に必ずスロット行があり、ブース/スタッフ重複 0 件になるか（テスト DB で `APP_ENV=local` 指定して seed → SQL で確認）。
- ローカル DB は上記で作り直し済み。バックアップ：`storage/app/backups/before-reset-20260923.sql`（git 管理外）。

## 既知の設計判断（変えないこと）
- 予約の重複防止は `reservation_resource_slots` の DB 一意制約が最終防衛線（`GuardIsDbUniqueTest` / `ReservationConcurrencyTest`）。予約テーブルでの事前重複チェックは入れない。
- 競合例外は `bootstrap/app.php` で名前付きエラーバッグ `reservation` に入る（`ReservationServiceTest::test_conflict_exception_returns_inertia_request_with_named_error_bag`）。フロント側は errorBag 指定 or `firstErrorMessage()` で受ける。
- 予約受付期間（BookingWindow）の設定は SettingsSeeder に入れない（オプトイン）。

## 残課題の候補（余力があれば調査のみ）
- 新規予約パネルの開始時間候補はブースを考慮していない（全ブース埋まりでもブース無し予約になり得る）。
- 月額プランの利用条件（平日12〜18時・指名不可・1日1回）はシステム未対応。
- 回数券の有効期限はサイト未記載のため仮値。
