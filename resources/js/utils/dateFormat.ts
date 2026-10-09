/**
 * 日時・日付の表示整形（Task 11-34 共通化）。
 *
 * 画面ごとに表示形式が違うため、Intl のオプションは名前付きプリセットとして並べ、
 * 呼び出し側で形式を明示して選ぶ。文字列の解釈方法も画面ごとに違うので、解釈と整形は分けておく。
 */

/** 画面で使っている Intl.DateTimeFormat のオプション（表示結果が一字でも違うものは別プリセット）。 */
export const DATE_TIME_FORMATS = {
    /** 例：2026年10月9日(金) 14:05 */
    long: { year: 'numeric', month: 'long', day: 'numeric', weekday: 'short', hour: '2-digit', minute: '2-digit' },
    /** 例：10/9(金) 14:05 */
    monthDayWeekday: { month: 'numeric', day: 'numeric', weekday: 'short', hour: '2-digit', minute: '2-digit' },
    /** 例：10月9日(金) 14:05 */
    monthLongDayWeekday: { month: 'long', day: 'numeric', weekday: 'short', hour: '2-digit', minute: '2-digit' },
    /** 例：2026/10/09 14:05 */
    medium: { dateStyle: 'medium', timeStyle: 'short' },
    /** 例：2026/10/09 14:05（dateStyle: short） */
    short: { dateStyle: 'short', timeStyle: 'short' },
    /** 例：2026/10/09 14:05:30 */
    withSeconds: { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' },
    /** 例：2026/10/9 14:05 */
    numeric: { year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' },
    /** 例：2026/10/09（日付のみ） */
    dateMedium: { dateStyle: 'medium' },
    /** 例：2026年10月9日（日付のみ） */
    dateLong: { year: 'numeric', month: 'long', day: 'numeric' },
} satisfies Record<string, Intl.DateTimeFormatOptions>;

export type DateTimeFormatPreset = keyof typeof DATE_TIME_FORMATS;

/** 'YYYY-MM-DD' 形式の判定。 */
const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
/** 'YYYY-MM-DD HH:MM:SS' から 'HH:MM' を取り出す位置。 */
const TIME_LABEL_START = 11;
const TIME_LABEL_END = 16;
/** 月・日の 0 埋め桁数。 */
const DATE_PART_WIDTH = 2;

/** サーバーの 'YYYY-MM-DD HH:MM:SS' を端末のローカル時刻として解釈する。 */
export function parseDateTime(value: string): Date {
    return new Date(value.replace(' ', 'T'));
}

/** 'YYYY-MM-DD' を端末のローカル日付の 0 時として解釈する。 */
export function parseDateOnly(value: string): Date {
    return new Date(`${value}T00:00:00`);
}

/** Date をプリセットの形式で整形する。 */
export function formatDateObject(date: Date, preset: DateTimeFormatPreset): string {
    return new Intl.DateTimeFormat('ja-JP', DATE_TIME_FORMATS[preset]).format(date);
}

/** 'YYYY-MM-DD HH:MM:SS' をプリセットの形式で整形する。 */
export function formatDateTime(value: string, preset: DateTimeFormatPreset): string {
    return formatDateObject(parseDateTime(value), preset);
}

/** 'YYYY-MM-DD' をプリセットの形式で整形する。 */
export function formatDateOnly(value: string, preset: DateTimeFormatPreset): string {
    return formatDateObject(parseDateOnly(value), preset);
}

/** 'YYYY-MM-DD' を「10/9（金）」にする。形式が違う値は空文字。 */
export function formatMonthDayWeekday(iso: string): string {
    if (!ISO_DATE_PATTERN.test(iso)) {
        return '';
    }

    const [y, m, d] = iso.split('-').map(Number);
    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(new Date(y, m - 1, d));

    return `${m}/${d}（${weekday}）`;
}

/** 'YYYY-MM-DD HH:MM:SS' の時刻部分 'HH:MM'。 */
export function timeLabel(value: string): string {
    return value.slice(TIME_LABEL_START, TIME_LABEL_END);
}

/** Date を端末のローカル日付の 'YYYY-MM-DD' にする。 */
export function toIsoDate(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(DATE_PART_WIDTH, '0');
    const d = String(date.getDate()).padStart(DATE_PART_WIDTH, '0');

    return `${y}-${m}-${d}`;
}

/** 今日（端末のローカル日付）の 'YYYY-MM-DD'。 */
export function todayIso(): string {
    return toIsoDate(new Date());
}
