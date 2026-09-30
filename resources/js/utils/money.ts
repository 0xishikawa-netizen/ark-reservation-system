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
