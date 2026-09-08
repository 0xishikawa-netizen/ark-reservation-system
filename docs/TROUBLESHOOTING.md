# TROUBLESHOOTING

症状から入る早見表。復旧コマンドの詳細は OPERATIONS.md。

---

## 予約

### 同じ枠に 2 件入った（二重予約）
- 本来 `reservation_resource_slots` の `UNIQUE(resource_type, resource_id, slot_start)` で防がれる。
- 発生したら：
  1. `reservation_resource_slots` に該当スロット行が 2 つあるか（＝制約が効いていない）確認。無ければ「スロット行を作らない経路」から予約が作られている → その経路を `ReservationService` 経由に直す（Controller / Vue から直接 INSERT していないか）。
  2. `php artisan reservations:reconcile-slots --dry-run` で予約とスロットの不整合を洗い出す。
  3. どちらの予約を残すか **人間判断**。片方を `canceled` にしてスロット解放。

### 予約が `pending_external_sync` から進まない（authority=外部）
- `sync_logs` を確認 → OPERATIONS.md §6。
- Gateway の `capabilities()` が想定どおりか（`EXTERNAL_RESERVATION_GATEWAY` の設定ミスで `null` のまま等）。

### 予約が `pending_payment` のまま / 枠が塞がったまま
- OPERATIONS.md §7。`reservations:expire-pending` を実行。

### 台帳の D&D で「保存できません」と出る
- `reservations.version` の楽観ロック衝突（他スタッフが同時編集）。画面リロードで最新化。
- 移動先スロットが既に埋まっている（UNIQUE 制約）。正しい挙動。別スロットへ。

---

## 決済

### Stripe では成功しているのにアプリで「未決済」
- OPERATIONS.md §5。`webhook_events` 確認 → `stripe:replay` or `reservations:reconcile-payments`。

### 「決済完了」と出たのに後から返金された
- `authority=外部` の補償 Saga：外部予約登録に失敗し capture 済みだったため自動返金。
- `payments.status = refunded` / `reservations.status = expired` / `payment_refunds` に記録があるはず。顧客へ連絡。

### authorize したまま capture されない
- 補償 Saga のステップ 3（capture）が失敗して止まっている可能性。`failed_jobs` を確認。
- 与信保持期間（Stripe は概ね 7 日）を超えると authorization は自動失効する。超過分は `voided` として扱い、必要なら再決済。

### 二重に課金された
- `payments.idempotency_key` が同一予約で複数の PaymentIntent を作っていないか。
- Idempotency-Key テンプレート（`config/stripe.php`）どおりに生成されているか。
- 実害があれば **人間判断**で片方を返金し、原因（キー生成のバグ）を修正。

---

## 回数券 / 利用権

### 残数がマイナス / 合わない
- `php artisan tickets:reconcile --dry-run`（利用権は `memberships:reconcile --dry-run`）。
- 原因の典型：
  - キャッシュ（`ticket_wallets.balance` / `memberships.period_available`）の更新が台帳追記と別 transaction になっている。
  - `dedupe_key` が付いておらず retry で二重追記された（`ticket_transactions` / `membership_usage_transactions` の重複を確認）。
- `--fix` で `ADJUST` 補正（manager 承認 + 監査）。

### キャンセルしたのに回数が戻らない
- `RESERVE_RELEASE` / `RELEASE` 追記が走ったか台帳を確認。キャンセル処理が `ReservationService` 経由か。

---

## Queue / スケジュール

### ジョブが動かない
- OPERATIONS.md §4。`schedule:list` / `supervisorctl status` / `queue:failed`。

### `model:prune` で消えてはいけないデータが消えた
- 業務データ（顧客・予約・台帳・決済・返金）は `Prunable` を実装しない設計。
  もし実装されていたら即削除。`config/retention.php` の `keep_categories` / 対象テーブルを確認。
- バックアップからリストア（OPERATIONS.md §2）。

---

## 認証 / MFA

### Passkey だけ登録したのに MFA 設定画面へ飛ばされる

Phase 5.5 以前の判定（`two_factor_confirmed_at` 直参照）が残っている疑い。
判定は `App\Domain\Auth\MfaPolicy` に集約されている。`grep -rn "two_factor_confirmed_at" app/` で
`MfaPolicy` 以外に判定ロジックが無いか確認する。

### Passkey を削除できない

**最後の MFA 手段は削除できない**仕様（自己ロックアウト対策）。
別の Passkey を追加するか、認証アプリ（TOTP）を設定してから削除する。

### Staging で登録した Passkey が本番で使えない

**仕様どおり。** WebAuthn の RP ID はドメインに紐づくため、環境ごとに登録が必要。
`PASSKEYS_RELYING_PARTY_ID` が各環境のホスト名と一致しているか確認する。

### Passkey 登録・ログインが必ず失敗する

- `PASSKEYS_RELYING_PARTY_ID` と実際のホスト名が一致しているか。
- `fortify.passkeys.allowed_origins` に実際のオリジン（スキーム込み）が含まれているか。
- **本番は HTTPS 必須。** http では WebAuthn が動作しない（localhost は例外）。
- ブラウザが対応しているか（未対応なら TOTP へ誘導される）。

### SMS の認証コードが届かない

現在 SMS provider は**未契約**で `MFA_SMS_DRIVER=log`。実送信されない。
local では `storage/logs` に送信記録（`mfa.sms.dispatched`）だけが残る。
**コード本体はログに出力しない**設計のため、ログからコードは取得できない。

### 「認証コードの再送は N 秒後に可能になります」

再送レート制限。`config/mfa.php` の `sms.resend` で調整する。
連打による SMS 費用の濫用を防ぐための意図的な制限。

## 環境 / 起動

### 起動時に「Stripe Live キーが検出されました」と例外
- 意図どおりのガード（`APP_ENV in [local,testing]` で Live キー禁止）。`.env` を Test キーに戻す。

### `.env` の値が効かない
- `php artisan config:clear`。本番は `config:cache` を再実行。

### WordPress DB に接続してしまった
- **即停止**。`DB_DATABASE` が `64ssq_ark_db` になっていないか。専用 DB に戻す。
- CI のガード（`.github/workflows/ci.yml`）が `64ssq_ark_db` 文字列を検出する。

---

## 迷ったら

1. まず影響範囲を確認（顧客に見えているか / お金が動いたか）。
2. お金が動く操作は勝手に補正しない。`audit_logs` と Stripe ダッシュボードで事実確認。
3. 手順が無い事象は、対応後にこのファイルへ追記する。
