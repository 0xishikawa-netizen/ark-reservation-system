# OPEN_QUESTIONS

未確定事項。決まったら本ファイルを更新し、関連 docs / config へ反映する。
「ブロッカー」＝そのフェーズ着手前に確定が必要。

| # | 事項 | 既定 / 暫定 | 確認先 | ブロッカー |
|---|---|---|---|---|
| 1 | ホスティング：共有お名前.com か VPS/PaaS か（Staging/Prod） | VPS 推奨（要判断） | インフラ担当 | Staging/Prod |
| 2 | サーバー実測：Composer / SSH / Node・npm / PHP 8.3 拡張 / cron 最小間隔 / mod_rewrite / memory_limit / max_execution_time / supervisor / **MySQL バージョン** | 未測定 | `tools/server-probe.*` を実行 | **Phase 0** |
| 3 | 保持期間：決済/返金/契約データの法定保存年数、`audit_logs` / `webhook_events` / `sync_logs` / `reservation_resource_slots` の日数 | 90/30/180/365/14 日（暫定） | 会計士・店舗 | Phase 5 前 |
| 4 | 予約の System of Record：当面 `local` で確定してよいか | `local` | 店舗・インフラ | Phase 10 |
| 5 | SALON BOARD 外部 API：存在？ 空き枠 / 予約 CRUD / 顧客連携 / webhook | 不明（問い合わせ中） | リクルート | Phase 10 |
| 6 | Peak Manager 外部 API：同上 + ARK 側予約が Hot Pepper/EPARK の空き枠へ反映されるか | 不明（問い合わせ中） | Peak Manager | Phase 10 |
| 7 | API 利用料（該当 Gateway） | 不明 | 各社 | Phase 10 判断 |
| 8 | 双方向同期仕様：コンフリクト時どちらが勝つか / 重複判定キー / 許容遅延（準リアルタイム必須か 5〜15 分ポーリング可か） | 未定 | 各社 | Phase 9-10 |
| 9 | 予約スロット粒度：15 分で確定か。**管理者の任意時刻予約を許可するか**（許可なら floor/ceil で占有スロット生成）。顧客予約は境界限定 | 15 分 / 許可しない | 店舗 | Phase 3 |
| 10 | Stripe webhook raw payload の暗号化オフ DB 短期保管（案 C）を採用するか | off | — | Phase 5 |
| 10b | 与信二段階：`authorize → 外部登録 → capture` が使える決済手段か（Stripe 手動 capture の対象・与信保持期間の制約）。不可なら「即時 capture → 失敗時自動返金」に縮退 | `capture_method=automatic` | Stripe ドキュメント / 決済手段確認 | Phase 10 |
| 11 | サブドメイン運用：`member.ark-conditioning.com` の DNS 権限 / SSL（Let's Encrypt か提供か）/ 別 docroot・VPS へ向けられるか | 未確認 | ドメイン管理者 | Phase 0 |
| 12 | 業務ルール：キャンセル期限・料金、no-show 扱い、利用権の未消化繰越、回数券の有効期限（商品別）、返金ポリシー、税（内税/外税・インボイス）、仮予約 HOLD 時間 | 繰越なし / HOLD 10 分 | 店舗 | Phase 3-6 |
| 13 | 予約確認・リマインドのチャネル（初期メールのみ）と送信基盤（Amazon SES / SendGrid 等） | メール | 店舗・インフラ | Phase 3 |
| 14 | データ移行：既存顧客 / 回数券残 / 稼働中利用権を Peak Manager 等から取り込むか | 未定 | 店舗 | 本番前 |
| 15 | 複数店舗：当面は単一店舗（`store_id` を作らない）で確定してよいか | 単一店舗 | 経営 | Phase 0 |
| 16 | PCI：Stripe Elements（SAQ A、PAN 非通過）で問題ないか | 問題なし想定 | — | Phase 5 |
| 17 | スタッフシフトの管理元：本システムか外部取込か | 本システム | 店舗 | Phase 2 |
| 18 | Phase 11 の集計項目・LINE/Slack 送信要否（LINE 依存実装は API 仕様確定まで着手しない） | 未定 | 店舗 | Phase 11 |
| 19 | Codex CLI の利用可否（インストール・認証状況）。未整備なら Phase 1 着手前に整備が必要 | 未確認 | 開発者 | Phase 1 |
| 20 | **SMS provider の選定**：国内直収接続（空電プッシュ等）か Twilio（ソフトバンク取扱）か。到達率＞価格で判断。SMS 不達は「管理者がログインできない」可用性リスク | 国内直収を推奨・未契約 | 経営 / 開発者 | Phase 5.5 以降 |
| 21 | 移行完了後の最終 MFA ポリシー：`Passkey OR TOTP` 必須 + SMS はフォールバック専用で確定してよいか | 左記で実装済み | 経営 | Phase 6 前 |
| 22 | admin を 2 名以上にできるか（自己ロックアウト対策）。不可なら Recovery Code の保管方法を決める | 未定 | 経営 | Phase 6 前 |
| 23 | Staging / 本番で Passkey が別登録になる運用を受容できるか（RP ID がドメイン依存のため） | 受容 | 店舗 | Phase 5.5 |
| 24 | スタッフ電話番号の収集・本人確認の業務手順（SMS OTP の前提） | 未定 | 店舗 | SMS provider 契約後 |

## 決定ログ

- 2026-09-07: スタック確定（Laravel 13 + Inertia + Vue3 + TS + Vuetify、モジュラーモノリス、web guard 1 つ）。
- 2026-09-07: 予約の二重防止は `reservation_resource_slots` の UNIQUE 制約を DB レベルの正とする。
- 2026-09-07: 回数券・利用権はどちらも追記型台帳。`available = SUM(delta)`、二重減算しない。
- 2026-09-07: `NullExternalReservationGateway` は no-op 成功にせず `UnsupportedOperationException` で fail-fast。
- 2026-09-08: Phase 5 で Idempotency-Key を `payment_operation_id` / `refund_operation_id` ベースへ変更（rev.5 の `:{attempt}` / `:{reason_hash}` 案は二重課金・返金漏れを招くため撤回）。
- 2026-09-08: Phase 5 で Cashier を導入しない（subscription は Phase 6）。`stripe/stripe-php` のみ。
- 2026-09-08: Phase 5.5 で MFA を Passkey 標準へ。判定は `MfaPolicy` に集約し、SMS 単独では要件を満たさない。
- 2026-09-08: SMS provider 未契約のため `SmsSender` interface + log/fake のみ実装。実 provider へは接続しない。
