/** 増減値に符号を付ける（正は '+'、0 と負はそのまま）。例：3 → '+3'、-2 → '-2'、0 → '0'（Task 11-34 共通化） */
export function signed(delta: number): string {
    return delta > 0 ? `+${delta}` : String(delta);
}
