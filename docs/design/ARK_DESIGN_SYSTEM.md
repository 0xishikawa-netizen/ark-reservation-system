# ARK Design System

Phase 9.6 で確定した ARK 予約・決済システムのデザイントークン正本。
色は `resources/js/plugins/vuetify.ts`（Vuetify テーマ）が実装上の正本、
本書はその **根拠（なぜこの色か）** を記録する。

---

## 1. Source（調査結果）

- **正本サイト**: <https://ark-conditioning.com/>（ARK Conditioning Laboratory 公式）
- **調査日**: 2026-09-10
- **調査方法**: 実サイトを読み込み、以下を機械抽出した。
  - 適用中の stylesheet 一覧
  - 子テーマ CSS（`twentytwenty-child/style/css/common.css` 他）の全 hex / rgb トークンを出現回数集計
  - 主要要素（`body` / `a` / `h1`–`h3` / `header` / `nav` / `.btn` / CTA / footer）の computed style
  - ロゴ画像 `wp-content/uploads/2023/04/ark_conditioning_logo.png` を canvas に描画してピクセルサンプリング
- **プラットフォーム**: WordPress（親テーマ `twentytwenty` + 子テーマ `twentytwenty-child`）
  - `--wp--preset--color--*`（例 `background:#f5efe0`, `accent:#cd2653`）は **twentytwenty の既定値**であり
    ARK の選定色ではない。子テーマ `common.css` が実際の ARK 配色で上書きしている。**preset 変数は採用しない。**

### CONFIRMED / INFERRED の区別

| 区分 | 意味 |
| --- | --- |
| **CONFIRMED** | 公式サイトの CSS もしくはロゴ画像から直接抽出した値 |
| **INFERRED** | CONFIRMED 値から業務アプリ向けに導出した値（対比・可読性・面の分離のため）。公式サイトには対応する値が存在しない |

---

## 2. Brand colors

公式 `common.css` の hex 出現集計（上位）:

| hex | 出現 | 用途（公式サイト） |
| --- | --- | --- |
| `#1a2653` | 24 | リンク色 / 一次ボタン `background:#1a2653; color:#fff; border-radius:8px` / 見出し下線 / 枠線 |
| `#ffffff` (`#fff`) | 23 | 面・ボタン文字 |
| `#333333` (`#333`) | 5 | 見出し・強調テキスト |
| `#1c2b56` | 3 | 一部の枠線 / 塗り（`#1a2653` と同族の微差） |
| `#444444` (`#444`) | 3 | ナビリンク等の二次テキスト |
| `#c30d23` | 1 | キャンペーン等の強調（赤） |
| `#cccccc` / `#eeeeee` / `#f0f0f0` / `#aaaaaa` | 各 1 | 枠線 / 微背景 / ソフトシャドウ |

ロゴ「A」マークのグラデーション（canvas サンプリング, CONFIRMED）:
上端 `#0087C5`（rgb 0,135,197 azure）→ 中間 `#1B519D` → 下端 `#0D2261` / 最暗部 `#030E4B`（深い navy）。

### 確定 brand token

| token | 値 | 区分 | 根拠 |
| --- | --- | --- | --- |
| `brandPrimary` | `#1A2653` | **CONFIRMED** | 公式 `common.css` で圧倒的最頻。リンク・一次 CTA・見出し下線の色そのもの |
| `brandPrimaryDark` | `#12193C` | INFERRED | ロゴ基部 navy `#0D2261` / `#030E4B` 相当。AppBar・hover・pressed・ナビ選択に使用 |
| `brandPrimarySoft` | `#EBEFF6` | INFERRED | `#1A2653` の淡いウォッシュ。選択行・ソフト塗り。white 面と 2% 差以内に収めない（視認できる最小差） |
| `brandAccent` | `#0087C5` | **CONFIRMED** | ロゴグラデーション上端の azure。navy 一色化を避けるためのアクセント（リンクオンダーク／情報系の母色） |
| `brandInk` | `#333333` | **CONFIRMED** | 見出し（`h1`–`h3`, weight 700–800） |
| `brandMuted` | `#5B6470` | INFERRED | 公式は二次テキストに `#444` を使うが、業務アプリの大量表示では冷たいグレーの方が階層が付く。`#444` と近い明度で彩度のみ下げた |

> **重要**: `brandPrimary` と semantic（`success` / `warning` / `error`）は別系統。
> ステータス表現を navy で塗り潰さない（§6 参照）。

---

## 3. Semantic colors（システム用・INFERRED）

公式サイトは marketing サイトのため success/warning/info の色を持たない（赤 `#c30d23` のみ）。
以下は **業務アプリ用に導出**した値。全て white 上でテキストコントラスト比 ≥ 4.5:1、
かつ brand navy と色相で明確に分離することを条件にした。

| token | 値 | 区分 | 根拠 / 使いどころ |
| --- | --- | --- | --- |
| `success` | `#1F7A4D` | INFERRED | 緑。予約確定 / 支払い成功 / 会員 active / 継続 |
| `warning` | `#A85F00` | INFERRED | 琥珀。猶予期間 / 要注意 / 一部返金 / キャンセル進行中（white 上で AA 4.5:1） |
| `error` | `#C0261C` | INFERRED（`#c30d23` を母色に微調整） | 赤。失敗 / 停止 / no-show / 取消不能な破壊操作 |
| `info` | `#0A6FA6` | INFERRED | azure。`brandAccent #0087C5` の暗いきょうだい（テキスト可読性確保）。中立の案内 / 3DS 待ち |

`#C30D23`（公式の生の赤）は「brand red の参照値」として保持。塗り面には彩度を少し落とした `error` を使う。

---

## 4. Typography

- **CONFIRMED**: 公式は `メイリオ, "ＭＳ Ｐゴシック", Meiryo, "MS PGothic", sans-serif`（日本語システムフォント、Web フォント非依存）。
- **本システム**: 既存の Hiragino 優先スタックを維持しつつ Meiryo を明示的に含める。
  意図（システム日本語フォント・オフライン可・高速）は公式と一致。
  ```
  "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Noto Sans JP",
  "BIZ UDPGothic", Meiryo, "MS PGothic", system-ui, -apple-system,
  BlinkMacSystemFont, "Segoe UI", sans-serif
  ```
- 見出し: weight **700**、`letter-spacing: 0.01em`、`line-height: 1.4`
- 本文: `line-height: 1.7`、`letter-spacing: 0.01em`（日本語可読性）
- 本文サイズ基準 16px（公式は 18px だが業務アプリの情報密度に合わせ 16px 基準）

---

## 5. Spacing / Radius / Shadow

| 種別 | 値 | 区分 | 根拠 |
| --- | --- | --- | --- |
| Spacing scale | 4 / 8 / 12 / 16 / 24 / 32 / 48 px | 既存維持 | 一般的な 4px グリッド |
| Radius | `sm 4` / `md 8` / `lg 12` | **CONFIRMED** | 公式 `common.css` に 4px/8px/12px が実在。一次ボタンは 8px |
| Shadow | `0 1px 2px` / `0 2px 10px`（ごく淡い） | CONFIRMED（方向性） | 公式は `box-shadow: 0 0 4px #aaa` のみ。強いドロップシャドウを使わない設計 |

面の階層は **枠線 + わずかな地色差**で表現し、影に依存しない。

---

## 6. Status representation（ステータス色の分離）

`resources/js/design/tokens.ts` の `statusColor()` が業務状態 → Vuetify カラー名を一元マッピングする。
**brand navy（primary）は「進行中／受付」だけに使い、正常・警告・失敗は semantic 色を使う。**

| 意味 | 色 token | 対象ステータス例 |
| --- | --- | --- |
| 進行中・受付 | `primary`（navy） | `pending`, `pending_payment`, `processing`, `authorized` |
| 正常・継続 | `success` | `active`, `confirmed`, `completed`, `paid`, `succeeded` |
| 要注意・猶予 | `warning` | `grace`, `canceling`, `partially_refunded`, `refunded` |
| 失敗・停止 | `error` | `failed`, `paused`, `no_show`, `voided` |
| 終了・中立 | `grey` | `canceled`, `expired`, `exhausted` |

色だけに頼らずラベル（テキスト）を必ず併記する（§Accessibility）。

---

## 7. Buttons

| 種別 | スタイル | 根拠 |
| --- | --- | --- |
| Primary CTA | `color=primary` `variant=flat`、塗り navy `#1A2653` / 文字 white / radius 8px / 全角大文字化しない（`text-none`） | 公式一次ボタンと一致 |
| Secondary | `variant=tonal` または `variant=outlined`（navy 枠 + navy 文字 + white 面） | 公式のアウトラインボタン `.btn` と一致 |
| Text | `variant=text` | 補助導線 |
| Destructive | `color=error`（塗りは重要操作のみ、通常は text+error） | semantic error |
| Google Sign-In | 専用（§ Login UI）。ARK Primary CTA と視覚的に区別する | Google ブランドガイドライン準拠 |

---

## 8. Forms

- `VTextField` / `VSelect` / `VTextarea` の `color=primary`（フォーカスライン navy）
- ラベルは常時表示（floating しない密度でも label 必須）
- エラーは `error-messages` で入力直下、`aria` 関連付けは Vuetify 既定に従う
- フォーカス: `:focus-visible { outline: 2px solid brandPrimary; outline-offset: 2px }`（全要素）

---

## 9. Cards

- `VCard`: `rounded=lg` / `border=true` / `flat=true` / 影は `0 1px 2px`（ごく淡い）
- 階層は入れ子面 `surface-light`（`#F1F3F7`）と枠線で表現

---

## 10. Navigation

| 面 | スタイル |
| --- | --- |
| Customer AppBar | `color=surface` / 下ボーダー / ブランド名 navy 太字 + ロゴマーク |
| Customer BottomNav | `color=primary`（選択タブ navy）/ アクティブインジケータ navy 3px |
| Admin NavigationDrawer | `color=surface` / 選択項目 `active` は navy 文字 + `brandPrimarySoft` 背景 |
| Admin AppBar | `color=surface`（濃色バーにしない。長時間運用の眩しさ回避） |

`aria-current="page"` を選択中項目に付与。

---

## 11. Customer UI

目標: **「ARK 公式サイトからそのまま予約・マイページに入った」体験**。

- 背景 white 基調（`background` は `#F5F6F8`、カードは `#FFFFFF`）
- 一次 CTA は navy 塗り、余白広め、角丸 8px
- セクション見出しは `#333` 太字、必要に応じ navy の下線アクセント
- モバイルファースト（375 / 430 幅で最適化、BottomNav 前提）
- 情報量は絞る。1 画面 1 主要アクション

---

## 12. Admin UI

目標: **ARK ブランドを保ちつつ、毎日長時間使える業務システムの可読性・操作速度を優先**。

- Customer と同じ navy を使うが、**塗り面を増やさない**（白背景 + 枠線 + 淡い地色差）
- 濃い navy バーやヒーローは使わない（眩しさ・視線移動コスト）
- ステータス・警告は semantic 色（navy で潰さない）
- 表・フィルタ・一覧の情報密度を優先（`density=compact` を許容）
- Customer 画面と「同じ製品」に見えるだけのブランド一貫性は保つ（ロゴ・色・タイポ）が、
  レイアウトの密度は別物でよい

---

## 13. 変更履歴

- **2026-09-10 (Phase 9.6)**: 初版。
  旧テーマの teal 系（`#1F8A80` 他）は公式ブランドと不一致のため撤去し、
  公式サイト実 CSS から抽出した navy `#1A2653` を brandPrimary に再設定。
  semantic 色（success/warning/error/info）は navy と分離した独立系統として定義。
