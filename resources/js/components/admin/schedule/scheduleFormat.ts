// 予約台帳（Schedule）の表示用の純粋な整形関数（画面の状態に依存しない）。

import type { ScheduleLane } from "./types";

export function timeToMinute(value: string): number {
    const [hour = 0, minute = 0] = value.slice(0, 5).split(":").map(Number);

    return hour * 60 + minute;
}

export function minuteToLabel(value: number): string {
    return `${String(Math.floor(value / 60)).padStart(2, "0")}:${String(value % 60).padStart(2, "0")}`;
}

// 性別表示は簡素に「男 / 女」のみ。未登録・その他は表示しない（推測しない）。
export function genderLabel(gender: string | null): string | null {
    if (gender === "male") return "男";
    if (gender === "female") return "女";

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
        clean.length === 3
            ? clean
                  .split("")
                  .map((c) => c + c)
                  .join("")
            : clean;
    const value = Number.parseInt(full, 16);

    if (Number.isNaN(value)) {
        return "rgb(var(--v-theme-surface))";
    }

    const r = (value >> 16) & 255;
    const g = (value >> 8) & 255;
    const b = value & 255;

    // 透けて見えないよう、背景と合成済みの不透明色にする（白に薄く色を混ぜる）。
    const mix = (channel: number): number =>
        Math.round(255 * 0.88 + channel * 0.12);

    return `rgb(${mix(r)}, ${mix(g)}, ${mix(b)})`;
}

export function todayIso(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
}

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
