/**
 * ARK Design System — 共有トークン。
 *
 * 色は Vuetify テーマ（plugins/vuetify.ts）が正本。ここでは Vuetify カラー名へ
 * 業務ステータスをマッピングするヘルパーと、CSS に依存しない spacing / radius の
 * 参照値だけを持つ。新しい配色をここで作らない。
 */

/** spacing scale（px）。CSS 側は --ark-space-* を使う。TS からの数値参照用。 */
export const space = {
    1: 4,
    2: 8,
    3: 12,
    4: 16,
    5: 24,
    6: 32,
    7: 48,
} as const;

export const radius = {
    sm: 4,
    md: 8,
    lg: 12,
} as const;

/**
 * 業務ステータス → Vuetify カラー名。
 * 予約 / 決済 / 会員 / 回数券で色の意味を統一する（緑=正常継続, 琥珀=要注意/猶予,
 * 赤=失敗/停止, 石=終了/中立, primary=進行中）。
 */
const STATUS_COLOR: Record<string, string> = {
    // 進行中・受付
    pending: 'primary',
    pending_payment: 'primary',
    pending_external_sync: 'primary',
    processing: 'primary',
    authorized: 'primary',
    // 正常・継続
    active: 'success',
    confirmed: 'success',
    completed: 'success',
    paid: 'success',
    succeeded: 'success',
    // 要注意・猶予・予定終了
    grace: 'warning',
    canceling: 'warning',
    partially_refunded: 'warning',
    refunded: 'warning',
    // 失敗・停止
    failed: 'error',
    paused: 'error',
    no_show: 'error',
    voided: 'error',
    // 終了・中立
    canceled: 'grey',
    expired: 'grey',
    exhausted: 'grey',
};

/** 未知のステータスは中立色にフォールバックする。 */
export function statusColor(status: string | null | undefined): string {
    if (!status) {
        return 'grey';
    }

    return STATUS_COLOR[status] ?? 'grey';
}
