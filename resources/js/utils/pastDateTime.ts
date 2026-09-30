/**
 * 'YYYY-MM-DD HH:MM[:SS]'（店舗の壁時計）が現在より前か。過去日時への予約・予定の入力確認に使う。
 * ブラウザの現在時刻（店舗と同じタイムゾーンの端末を想定）と比べる。
 */
export function isPastDateTime(value: string | null | undefined, now: Date = new Date()): boolean {
    if (!value) {
        return false;
    }
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value);
    if (!match) {
        return false;
    }
    const [, y, m, d, hh, mm] = match.map(Number);

    return new Date(y, m - 1, d, hh, mm).getTime() < now.getTime();
}
