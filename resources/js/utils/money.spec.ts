import { describe, expect, it } from 'vitest';
import { formatMoney, formatNumber, formatYenCurrency, formatYenSign, formatYenSuffix, toDigits } from './money';

describe('既存の金額入力用関数', () => {
    it('toDigits は全角数字を半角にして数字以外を除く', () => {
        expect(toDigits('１２,３45円')).toBe('12345');
    });

    it('formatMoney は 3 桁区切り、未入力は空文字', () => {
        expect(formatMoney(12345)).toBe('12,345');
        expect(formatMoney(null)).toBe('');
        expect(formatMoney(Number.NaN)).toBe('');
    });
});

describe('金額表示（表示結果ごとに別関数）', () => {
    it('formatYenCurrency は Intl の通貨形式（全角の円記号・小数なし）', () => {
        expect(formatYenCurrency(12345)).toBe('￥12,345');
        expect(formatYenCurrency(0)).toBe('￥0');
        expect(formatYenCurrency(-500)).toBe('-￥500');
    });

    it('formatYenSign は半角の円記号を前に付ける', () => {
        expect(formatYenSign(12345)).toBe('¥12,345');
        expect(formatYenSign(0)).toBe('¥0');
    });

    it('formatNumber は 3 桁区切りの数値だけ', () => {
        expect(formatNumber(1234567)).toBe('1,234,567');
    });

    it('formatYenSuffix は「円」を後ろに付ける', () => {
        expect(formatYenSuffix(12345)).toBe('12,345円');
        expect(formatYenSuffix(0)).toBe('0円');
    });
});
