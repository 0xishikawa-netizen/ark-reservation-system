// 予約台帳（Schedule）の表示用の純粋な整形関数（画面の状態に依存しない）。

import type { ScheduleLane } from "./types";
import { MESSAGES } from "@/constants/messages";

/** 1時間あたりの分数。 */
const MINUTES_PER_HOUR = 60;
/** 2桁の時刻表示幅。 */
const TIME_PART_WIDTH = 2;
/** 短縮HEXカラーの文字数。 */
const SHORT_HEX_LENGTH = 3;
/** HEXカラーを数値化する基数。 */
const HEX_RADIX = 16;
/** RGBの赤成分を取り出すビット位置。 */
const RED_CHANNEL_SHIFT_BITS = 16;
/** RGBの緑成分を取り出すビット位置。 */
const GREEN_CHANNEL_SHIFT_BITS = 8;
/** RGB各成分の最大値。 */
const MAX_COLOR_CHANNEL = 255;
/** カード背景に混ぜる白の割合。 */
const BACKGROUND_MIX_RATIO = 0.88;
/** カード背景に混ぜるメニュー色の割合。 */
const MENU_TINT_MIX_RATIO = 0.12;

export function timeToMinute(value: string): number {
    const [hour = 0, minute = 0] = value.slice(0, 5).split(":").map(Number);

    return hour * MINUTES_PER_HOUR + minute;
}

export function minuteToLabel(value: number): string {
    return `${String(Math.floor(value / MINUTES_PER_HOUR)).padStart(TIME_PART_WIDTH, "0")}:${String(value % MINUTES_PER_HOUR).padStart(TIME_PART_WIDTH, "0")}`;
}

// 性別表示は簡素に「男 / 女」のみ。未登録・その他は表示しない（推測しない）。
export function genderLabel(gender: string | null): string | null {
    if (gender === "male") return MESSAGES.boardUi.reservationCard.maleShort;
    if (gender === "female") return MESSAGES.boardUi.reservationCard.femaleShort;

    return null;
}

export function genderClass(gender: string | null): string {
    return gender === "male" || gender === "female"
        ? `reservation-gender reservation-gender--${gender}`
        : "reservation-gender";
}

// カード右上のステータスは色だけに頼らず、アイコン＋tooltip/aria-label で伝える（§26）。
export function statusIcon(status: string): string {
    const icons: Record<string, string> = {
        confirmed: "mdi-calendar-check-outline",
        completed: "mdi-check-circle-outline",
        no_show: "mdi-account-off-outline",
        pending_payment: "mdi-timer-sand",
        pending_external_sync: "mdi-sync",
        canceled: "mdi-close-circle-outline",
        expired: "mdi-clock-alert-outline",
    };

    return icons[status] ?? "mdi-information-outline";
}

/** メニュー色（HEX）から、カードの淡い背景色を作る。原色ベタ塗りを避ける（§22）。 */
export function menuTintBackground(hex: string): string {
    const clean = hex.replace("#", "");
    const full =
        clean.length === SHORT_HEX_LENGTH
            ? clean
                  .split("")
                  .map((c) => c + c)
                  .join("")
            : clean;
    const value = Number.parseInt(full, HEX_RADIX);

    if (Number.isNaN(value)) {
        return "rgb(var(--v-theme-surface))";
    }

    const r = (value >> RED_CHANNEL_SHIFT_BITS) & MAX_COLOR_CHANNEL;
    const g = (value >> GREEN_CHANNEL_SHIFT_BITS) & MAX_COLOR_CHANNEL;
    const b = value & MAX_COLOR_CHANNEL;

    // 透けて見えないよう、背景と合成済みの不透明色にする（白に薄く色を混ぜる）。
    const mix = (channel: number): number =>
        Math.round(MAX_COLOR_CHANNEL * BACKGROUND_MIX_RATIO + channel * MENU_TINT_MIX_RATIO);

    return `rgb(${mix(r)}, ${mix(g)}, ${mix(b)})`;
}

/** 今日（端末のローカル日付）の 'YYYY-MM-DD'（共通実装を再エクスポート）。 */
export { todayIso } from "@/utils/dateFormat";

export function shiftDateBy(iso: string, days: number): string {
    const [y, m, d] = iso.split("-").map(Number);
    const next = new Date(y, m - 1, d);
    next.setDate(next.getDate() + days);

    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
}

export function dayLabel(value: string): string {
    return new Intl.DateTimeFormat("ja-JP", {
        month: "numeric",
        day: "numeric",
        weekday: "short",
    }).format(new Date(`${value}T12:00:00`));
}

export function laneKey(lane: ScheduleLane): string {
    return `${lane.kind}-${lane.id ?? "unassigned"}`;
}

/** 予定ブロック種別ごとのアイコン・色。 */
const BLOCK_ICON: Record<string, string> = {
    BREAK: "mdi-coffee-outline",
    MEETING: "mdi-account-group-outline",
    ADMIN: "mdi-file-document-outline",
    CLEANING: "mdi-broom",
    WORK: "mdi-briefcase-outline",
    TRAINING: "mdi-school-outline",
    OUT: "mdi-walk",
    OTHER: "mdi-dots-horizontal",
};

const BLOCK_COLOR: Record<string, string> = {
    BREAK: "warning",
    MEETING: "info",
    ADMIN: "secondary",
    CLEANING: "success",
    WORK: "primary",
    TRAINING: "accent",
    OUT: "secondary",
    OTHER: "secondary",
};

export function blockIcon(type: string): string {
    return BLOCK_ICON[type] ?? "mdi-calendar-blank-outline";
}

export function blockColor(type: string): string {
    return BLOCK_COLOR[type] ?? "secondary";
}
