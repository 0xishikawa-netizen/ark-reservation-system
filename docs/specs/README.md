# 設計書（基本設計・詳細設計）— 目次

2026-10-07 時点の実装（`main` HEAD `73c0532`）を、基本設計・詳細設計の体系に整理した設計書一式。

## 位置づけ

- **承認済み設計の正本は `docs/PLAN.md` と Task 文書（`docs/tasks/`）**。本書は、それと散在していた個別仕様（`ARCHITECTURE.md` / `DB_SCHEMA.md` / `CHECKOUT_ENTRY.md` / `REPORTS_UI.md` ほか）、および実装コードを突き合わせて一冊の体系にまとめたもの。
- 文書と実装が食い違う箇所は **実装に合わせて** 記載し、差分を `docs/review/2026-10-07-full-code-review.md`「ドキュメントと実装の食い違い」に記録した。
- 細かな指標定義・画面ルールなど、既存文書のほうが詳しいものは、本書では要約して参照先を示す（二重管理を避けるため）。

## 構成

| 文書 | 内容 |
|---|---|
| [01_basic_design.md](01_basic_design.md) | **基本設計**：目的・範囲、システム構成、利用者と権限、業務フロー、機能一覧（機能 ID）、画面一覧（概要）、外部インターフェース、データ概要、非機能要件、前提・未決事項、用語 |
| [detailed/01_screens_routes.md](detailed/01_screens_routes.md) | 画面の共通仕様、画面遷移、画面一覧（Vue・ルート・権限）、ルート設計の規則 |
| [detailed/02_reservation.md](detailed/02_reservation.md) | 予約の状態遷移、作成・変更・延長・キャンセル・無断・完了の処理、検証ルール、スロット、空き枠算出、勤務枠と予約受付、ゲスト予約、予約台帳、予約 API |
| [detailed/03_payment.md](detailed/03_payment.md) | Stripe 決済の状態と流れ、冪等キー、返金、料金調整、Webhook、復旧手段、エラー時の表示 |
| [detailed/04_ticket_membership.md](detailed/04_ticket_membership.md) | 追記型台帳、回数券（取引種別・FEFO・ポリシー）、月額プラン（状態・申込・Webhook・操作・定期処理） |
| [detailed/05_visit_checkout.md](detailed/05_visit_checkout.md) | 来店・施術・会計・支払配分・スタッフ配分の責務、来店完了のトランザクション、会計の確定・取消、施術日基準の売上 |
| [detailed/06_reporting.md](detailed/06_reporting.md) | 帳票の方針、サービスと正本、主な指標の定義、画面・API と権限 |
| [detailed/07_auth_security.md](detailed/07_auth_security.md) | 認証・MFA・セッション、管理画面の入口、権限とロール（初期値）、回数制限、HTTP セキュリティ、個人情報、監査ログ |
| [detailed/08_batch_operations.md](detailed/08_batch_operations.md) | スケジュール一覧、手動コマンド、キュージョブ、保持期間、監視画面、バックアップ（未実装）、デプロイ |
| [detailed/09_external_integration.md](detailed/09_external_integration.md) | 外部予約連携の切替軸、Outbox、取込、データ、実 API 確定後の作業 |
| [detailed/10_data_model.md](detailed/10_data_model.md) | DB の共通規約、テーブル目録（89 テーブル）、主要テーブルの現行列、状態遷移一覧、設定キー、一意制約 |

関連：コードレビュー報告 [../review/2026-10-07-full-code-review.md](../review/2026-10-07-full-code-review.md)

## 更新のルール

- 仕様を変える Task では、該当する詳細設計の節も同じ変更で更新する。
- 機能 ID（`F-XXX-nn`）は基本設計 §5 で採番し、詳細設計・テスト・Task から参照する。
- 図は Mermaid で書く（GitHub / VS Code でそのまま表示できる）。
