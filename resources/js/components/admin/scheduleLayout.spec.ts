import { describe, expect, it } from 'vitest';
import { scheduleTrackHeight, TRACK_MIN_HEIGHT, TRACK_STANDARD_HEIGHT } from './scheduleLayout';

describe('ブッキングボードの行高（Task 11-30）', () => {
    it('スタッフのみ・ブースのみ・両方のどれでも、人数に関係なく同じ標準の高さ', () => {
        // 1人だけ（スタッフのみ表示）でも、画面の空きを埋めるために伸ばさない。
        expect(scheduleTrackHeight(1, 0, 28, 900, false)).toBe(TRACK_STANDARD_HEIGHT);
        expect(scheduleTrackHeight(3, 0, 28, 560, false)).toBe(TRACK_STANDARD_HEIGHT);
        // 両方（見出し行あり）でも同じ。
        expect(scheduleTrackHeight(5, 2, 28, 900, false)).toBe(TRACK_STANDARD_HEIGHT);
        expect(scheduleTrackHeight(12, 2, 28, 400, false)).toBe(TRACK_STANDARD_HEIGHT);
    });

    it('狭い画面・行なしは最小高さ', () => {
        expect(scheduleTrackHeight(3, 0, 28, 560, true)).toBe(TRACK_MIN_HEIGHT);
        expect(scheduleTrackHeight(0, 0, 28, 560, false)).toBe(TRACK_MIN_HEIGHT);
    });
});
