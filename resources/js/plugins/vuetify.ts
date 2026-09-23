import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import { ja } from 'vuetify/locale';

/**
 * ARK Design System — Vuetify テーマ（配色の実装上の正本）。
 *
 * 根拠は docs/design/ARK_DESIGN_SYSTEM.md。
 * 色は ARK Conditioning Laboratory 公式サイト（https://ark-conditioning.com/）の
 * 実 CSS とロゴから抽出した **navy `#1A2653`** を brandPrimary とする。
 *
 * - brand（navy 系）と semantic（success/warning/error/info）は別系統。
 *   ステータス表現を navy で塗り潰さない（design/tokens.ts の statusColor）。
 * - Web フォント非依存・淡い影・枠線と地色差で階層を作る、という公式サイトの方針を踏襲。
 */
const ark = {
    dark: false,
    colors: {
        // ── ブランド（CONFIRMED: 公式 common.css / ロゴ）
        primary: '#1A2653', // ARK navy（公式最頻・一次 CTA・リンク）
        'primary-darken-1': '#12193C', // 深い navy（AppBar / hover / pressed / ナビ選択）— INFERRED（ロゴ基部）
        secondary: '#5B6470', // 補助アクション用の冷grey — INFERRED
        accent: '#0087C5', // ロゴグラデーション上端 azure（アクセント）— CONFIRMED

        // ── 面
        background: '#F5F6F8', // ごく淡い cool オフホワイト（カードを白で分離）— INFERRED
        surface: '#FFFFFF',
        'surface-bright': '#FFFFFF',
        'surface-light': '#F1F3F7', // 入れ子パネル
        'surface-variant': '#5B6470',
        'on-surface-variant': '#F1F3F7',
        'brand-soft': '#EBEFF6', // navy の淡いウォッシュ（選択行・ソフト塗り）— INFERRED

        // ── テキスト
        'on-background': '#1F2430',
        'on-surface': '#1F2430',

        // ── セマンティック（INFERRED: white 上 4.5:1 以上 / navy と色相分離）
        success: '#1F7A4D',
        warning: '#A85F00', // white 上で AA（4.5:1）を満たすよう調整
        error: '#C0261C',
        info: '#0A6FA6',
    },
    variables: {
        'border-color': '#D9DEE5',
        'border-opacity': 1,
        'high-emphasis-opacity': 0.92,
        'medium-emphasis-opacity': 0.66,
        'theme-surface-light': '#F1F3F7',
    },
} as const;

export default createVuetify({
    // Vuetify 標準 UI 文言（「Items per page:」等）を日本語化する。
    locale: {
        locale: 'ja',
        fallback: 'en',
        messages: { ja },
    },
    theme: {
        defaultTheme: 'ark',
        themes: { ark },
    },
    defaults: {
        // 「素の Vuetify 感」を抑える共通既定。個別画面の variant 指定は尊重される。
        global: {
            // ドロップシャドウを弱め、面と枠線で階層を出す。
            elevation: 0,
        },
        VCard: {
            rounded: 'lg',
            border: true,
            flat: true,
        },
        VBtn: {
            rounded: 'md', // 8px（公式一次ボタンと一致）
            // 全大文字をやめて視認性を上げる（日本語 UI）。
            class: 'text-none',
        },
        VChip: {
            rounded: 'sm',
        },
        VTextField: {
            color: 'primary',
        },
        VSelect: {
            color: 'primary',
        },
        VTextarea: {
            color: 'primary',
        },
        VAlert: {
            rounded: 'md',
            border: 'start',
        },
        VAppBar: {
            flat: true,
        },
    },
});
