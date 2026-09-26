/**
 * 会計入力画面のプレビュー計算。確定値はサーバー（TaxAmountCalculator）が正本で、同じ式を使う。
 */

/** 税込額から税額を切り捨てで求める（明細単位・内税）。税率未設定は null。 */
export function splitInclusive(gross: number, rateBps: number | null): { net: number; tax: number } | null {
    if (rateBps === null || !Number.isFinite(gross) || gross < 0) return null;
    const tax = Math.floor((gross * rateBps) / (10000 + rateBps));
    return { net: gross - tax, tax };
}

/**
 * 担当時間などの重みで金額を按分する（Phase 11 §4.4 の担当時間按分）。
 * 基礎額は floor(total × weight ÷ 総weight)、余りは端数の大きい順（同順位は入力順）に1円ずつ配る最大剰余法。
 */
export function allocateByWeight(total: number, weights: number[]): number[] {
    const sum = weights.reduce((acc, w) => acc + Math.max(w, 0), 0);
    if (sum <= 0 || weights.length === 0) return weights.map(() => 0);
    const base = weights.map((w) => Math.floor((total * Math.max(w, 0)) / sum));
    let remainder = total - base.reduce((acc, v) => acc + v, 0);
    const order = weights
        .map((w, index) => ({ index, fraction: ((total * Math.max(w, 0)) % sum) / sum }))
        .sort((a, b) => b.fraction - a.fraction || a.index - b.index);
    for (const entry of order) {
        if (remainder <= 0) break;
        base[entry.index] += 1;
        remainder -= 1;
    }
    return base;
}
