/**
 * ブッキングボード（日表示）の行の高さ（Task 11-30）。
 * スタッフ／ブース／両方のどの表示でも同じ標準の高さにする。人数が少ない日に画面の空きを埋めるため
 * 行を伸ばすことはしない（1人の行が150px以上になり予約カードが不自然に大きくなっていたため）。
 * 行が少ない日は台帳自体が内容の高さに縮み、「本日の集計」がすぐ下に来る。
 *   標準 80px（名前・時間・メニュー・指名の4行が収まる高さ）
 *   スマホ幅など狭い画面は従来の最小 68px
 */
export const TRACK_MIN_HEIGHT = 68;
export const TRACK_STANDARD_HEIGHT = 80;

/**
 * @param laneRows     スタッフ／ブース行の数
 * @param headerRows   「両方」表示の見出し行の数（互換のため受け取るが高さには使わない）
 * @param headerHeight 見出し行1つの高さ（同上）
 * @param available    台帳の行に使える縦の空き（同上。空きに合わせて伸ばさない）
 * @param narrow       スマホ幅など最小高さにする画面か
 */
export function scheduleTrackHeight(
    laneRows: number,
    headerRows: number,
    headerHeight: number,
    available: number,
    narrow: boolean,
): number {
    void headerRows;
    void headerHeight;
    void available;

    if (narrow || laneRows === 0) {
        return TRACK_MIN_HEIGHT;
    }

    return TRACK_STANDARD_HEIGHT;
}
