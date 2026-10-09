import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

/** 全角数字を半角にし、数字以外（カンマ・記号・マイナス・小数点）を取り除く。金額入力用。 */
export function toDigits(raw: string): string {
    return raw.replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0)).replace(/[^0-9]/g, '');
}

/** 金額を 3 桁カンマ区切りで表示する（未入力は空文字）。 */
export function formatMoney(value: number | null | undefined): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '';
    }

    return new Intl.NumberFormat('ja-JP').format(value);
}

/**
 * 以下は画面ごとに違う金額表示を、表示結果ごとに名前を分けて共通化したもの（Task 11-34）。
 * 出力が違うので互いに置き換えないこと。
 */

/** 円の小数点以下の桁数（円は小数なし）。 */
const YEN_FRACTION_DIGITS = 0;

/** Intl の通貨形式（全角の円記号）。例：12345 → '￥12,345' */
export function formatYenCurrency(value: number): string {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: YEN_FRACTION_DIGITS,
    }).format(value);
}

/** 半角の円記号を前に付ける。例：12345 → '¥12,345' */
export function formatYenSign(value: number): string {
    return `¥${value.toLocaleString('ja-JP')}`;
}

/** 3 桁カンマ区切りの数値だけ。例：12345 → '12,345' */
export function formatNumber(value: number): string {
    return new Intl.NumberFormat('ja-JP').format(value);
}

/** 「円」を後ろに付ける（文言は MESSAGES.customerUi.format.yen）。例：12345 → '12,345円' */
export function formatYenSuffix(value: number): string {
    return fillMessage(MESSAGES.customerUi.format.yen, { amount: formatNumber(value) });
}
