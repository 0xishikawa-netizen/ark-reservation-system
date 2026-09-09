import 'vuetify/styles';
import { createVuetify } from 'vuetify';

/**
 * ARK Design System — Vuetify テーマ。
 *
 * ARK Conditioning 公式サイトの雰囲気（温かみのあるオフホワイト背景・落ち着いた
 * ティール・ヘルスケア/クリニック寄りの信頼感）に寄せた 1 つの light テーマ。
 * 各画面で個別に配色しないための単一の正本。
 */
const ark = {
    dark: false,
    colors: {
        // ブランド
        primary: '#1F8A80', // 落ち着いたティール（ネオンにしない）
        secondary: '#334155', // 補助アクション用の墨色
        accent: '#2BB0A3',

        // 面
        background: '#F7F6F2', // 温かみのあるオフホワイト
        surface: '#FFFFFF',
        'surface-bright': '#FFFFFF',
        'surface-light': '#EFEEE8', // 入れ子パネル用のソフト面
        'surface-variant': '#5B6468',
        'on-surface-variant': '#EFEEE8',

        // テキスト
        'on-background': '#22282B',
        'on-surface': '#22282B',

        // セマンティック（彩度は抑えめ）
        success: '#2E7D5B',
        warning: '#B26B00',
        error: '#B3261E',
        info: '#2B6C8F',
    },
    variables: {
        'border-color': '#E3E1DA',
        'border-opacity': 1,
        'high-emphasis-opacity': 0.92,
        'medium-emphasis-opacity': 0.66,
        'theme-surface-light': '#EFEEE8',
    },
} as const;

export default createVuetify({
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
            rounded: 'md',
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
