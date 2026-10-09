/**
 * Reports 画面の数値表示の共通フォーマッタ。
 * 値がない（null / undefined）ときは null を返し、呼び出し側で EmptyValue（薄いグレーの「-」）にする。
 * 0 は実績値なので必ず「0」「0円」「0.0%」として返す。
 */
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

/** 割合（0〜1）を百分率にする倍率。 */
const PERCENT_SCALE = 100;
/** 百分率の小数点以下の桁数。 */
const PERCENT_FRACTION_DIGITS = 1;

export type ReportValueFormat = 'count' | 'money' | 'percent' | 'decimal' | 'minutes';

const integerFormat = new Intl.NumberFormat('ja-JP');
const decimalFormat = new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 1 });

export function formatReportValue(value: number | null | undefined, format: ReportValueFormat = 'count'): string | null {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return null;
    }

    switch (format) {
        case 'money':
            return fillMessage(MESSAGES.customerUi.reportFormat.money, { amount: integerFormat.format(Math.round(value)) });
        case 'percent':
            return `${(value * PERCENT_SCALE).toFixed(PERCENT_FRACTION_DIGITS)}%`;
        case 'decimal':
            return decimalFormat.format(value);
        default:
            return integerFormat.format(value);
    }
}

/** 'YYYY-MM-DD' を表の日付列向けの「9/1（火）」形式にする。 */
export function formatReportDate(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return value;
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(date);

    return fillMessage(MESSAGES.customerUi.reportFormat.date, { month: String(Number(match[2])), day: String(Number(match[3])), weekday: weekday });
}
