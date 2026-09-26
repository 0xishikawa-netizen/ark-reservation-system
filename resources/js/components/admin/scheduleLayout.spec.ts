import { describe, expect, it } from 'vitest';
import { scheduleTrackHeight, TRACK_MIN_HEIGHT } from './scheduleLayout';

describe('ブッキングボードの行高', () => {
    it('1〜3人の日は空きを使って少し広め（上限152px）にする', () => {
        expect(scheduleTrackHeight(3, 0, 28, 560, false)).toBe(152);
        expect(scheduleTrackHeight(1, 0, 28, 560, false)).toBe(152);
        expect(scheduleTrackHeight(3, 0, 28, 330, false)).toBe(110);
    });

    it('4〜6人は標準（上限112px）、7人以上は上限88px', () => {
        expect(scheduleTrackHeight(5, 0, 28, 900, false)).toBe(112);
        expect(scheduleTrackHeight(8, 0, 28, 1200, false)).toBe(88);
    });

    it('多人数で空きが足りない時は従来の最小68px（ページを縦スクロール）', () => {
        expect(scheduleTrackHeight(10, 2, 28, 560, false)).toBe(TRACK_MIN_HEIGHT);
    });

    it('見出し行の高さを差し引いて計算する', () => {
        expect(scheduleTrackHeight(4, 2, 28, 456, false)).toBe(100);
    });

    it('未計測・狭い画面・行なしは最小高さ', () => {
        expect(scheduleTrackHeight(3, 0, 28, 0, false)).toBe(TRACK_MIN_HEIGHT);
        expect(scheduleTrackHeight(3, 0, 28, 560, true)).toBe(TRACK_MIN_HEIGHT);
        expect(scheduleTrackHeight(0, 0, 28, 560, false)).toBe(TRACK_MIN_HEIGHT);
    });
});
