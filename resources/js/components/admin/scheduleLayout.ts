/**
 * ブッキングボード（日表示）の行の高さ。
 * 人数が少ない日は画面の空きを使って行を高くし、多い日は最小高さ＋ページの縦スクロールにする。
 * 予約カードが間延びしないよう、上限は行数に応じて段階的に下げる。
 *   最小 68px（従来の固定値）
 *   上限 1〜3行: 152px（少し広め） / 4〜6行: 112px（標準） / 7行以上: 88px
 */
export const TRACK_MIN_HEIGHT = 68;

export function trackMaxHeight(laneRows: number): number {
    if (laneRows <= 3) return 152;
    if (laneRows <= 6) return 112;

    return 88;
}

/**
 * @param laneRows     スタッフ／ブース行の数
 * @param headerRows   「両方」表示の見出し行の数（固定高さ）
 * @param headerHeight 見出し行1つの高さ
 * @param available    台帳の行に使える縦の空き（画面下端まで。0以下なら未計測）
 * @param narrow       スマホ幅など固定高さにする画面か
 */
export function scheduleTrackHeight(
    laneRows: number,
    headerRows: number,
    headerHeight: number,
    available: number,
    narrow: boolean,
): number {
    if (narrow || laneRows === 0 || available <= 0) {
        return TRACK_MIN_HEIGHT;
    }

    const perLane = Math.floor((available - headerRows * headerHeight) / laneRows);

    return Math.min(trackMaxHeight(laneRows), Math.max(TRACK_MIN_HEIGHT, perLane));
}
