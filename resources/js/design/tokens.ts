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

/**
 * 予約ステータス（App\Enums\Reservation\ReservationStatus）→ 日本語表示。
 * バックエンドの ReservationPanelQuery::statusLabel() と同じ文言に揃える。
 * ここが未知のステータスを返すと生の英語値がそのまま画面に出てしまうため、
 * enum の全ケースを必ず網羅すること。
 */
const RESERVATION_STATUS_LABEL: Record<string, string> = {
    pending_payment: '支払い待ち',
    pending_external_sync: '外部連携待ち',
    confirmed: '予約確定',
    completed: '来店完了',
    no_show: '無断キャンセル',
    canceled: 'キャンセル',
    expired: '期限切れ',
};

export function reservationStatusLabel(status: string | null | undefined): string {
    if (!status) {
        return '';
    }

    return RESERVATION_STATUS_LABEL[status] ?? status;
}

/**
 * 予約「経路」（App\Enums\Reservation\ReservationSource）→ 日本語/表示名。
 * ステータスではなくデータ区分なので statusColor とは別系統の色を Index/Edit 側で割り当てる。
 */
const RESERVATION_SOURCE_LABEL: Record<string, string> = {
    ARK_WEB: 'ARK Web',
    ADMIN: '管理',
    HOTPEPPER: 'Hot Pepper',
    EPARK: 'EPARK',
    PEAK_MANAGER: 'Peak Manager',
};

export function reservationSourceLabel(source: string | null | undefined): string {
    if (!source) {
        return '';
    }

    return RESERVATION_SOURCE_LABEL[source] ?? source;
}

const RESERVATION_SOURCE_COLOR: Record<string, string> = {
    ARK_WEB: 'primary',
    ADMIN: 'secondary',
    HOTPEPPER: 'pink-darken-1',
    EPARK: 'cyan-darken-2',
    PEAK_MANAGER: 'indigo',
};

export function reservationSourceColor(source: string | null | undefined): string {
    if (!source) {
        return 'grey';
    }

    return RESERVATION_SOURCE_COLOR[source] ?? 'grey';
}
