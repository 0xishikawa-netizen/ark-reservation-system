# docs — 索引

本システムの設計・運用ドキュメント一式。設計の正本はこの `docs/` 配下。

| ファイル | 内容 |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | 全体アーキ / System of Record 切替 / WordPress 分離 / モジュール構成 / 外部予約ゲートウェイ / Stripe / 認証 / セキュリティ / 整合性設計 |
| [DB_SCHEMA.md](DB_SCHEMA.md) | テーブル定義 / ER 概要 / 回数券・利用権の台帳セマンティクス / 保持期間 / 容量方針 |
| [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) | 未確定事項（担当・ブロッカー・確認先） |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Local / Staging / Production の構成 / 依存パッケージ / ビルド / デプロイ / ロールバック |
| [OPERATIONS.md](OPERATIONS.md) | バックアップ・リストア・Queue・Webhook・同期失敗・DB 容量・ログ / 自動復旧 vs 人間判断 |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | 症状 → 原因切り分け → 復旧手順 |
| [PHASE0_REPORT.md](PHASE0_REPORT.md) | Phase 0（環境確認・scaffold）の実測結果と判断 |
| [REPORTS_UI.md](REPORTS_UI.md) | Reports・ブッキングボード・設定メニューの表示ルール（null表示・共通Filter/DatePicker/Table・スタッフ表示仕様） |
| [handoff/2026-09-26-cloud-handoff.md](handoff/2026-09-26-cloud-handoff.md) | Claude Cloud への引継ぎ（現在の状態・未解決事項・最初に比較すべき帳票） |

## 進め方

- Phase 単位で実装。Phase 1 以降は実装タスクを Codex に渡し、Claude Code がレビュー（安全性・整合性・冪等性・保守性・DB 容量）。
- 本番 WordPress / 本番 DB / Stripe Live / Peak Manager 実 API / SALON BOARD 実 API へは、明示の承認があるまで接続しない。

## 最優先原則（設計判断の基準）

1. 安全性 → 2. データ整合性 → 3. 保守性（1 人運用）→ 4. バックアップ/復旧 → 5. 予約・決済の確実性
（以降）操作性 / 管理画面 UX / パフォーマンス / DB 容量効率
（将来）高度な分析 / レポート自動送信 / その他自動化

**高機能化で保守性・安全性が下がる設計は採用しない。**
