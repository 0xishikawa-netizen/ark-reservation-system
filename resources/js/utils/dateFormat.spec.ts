import { describe, expect, it, vi } from 'vitest';
import {
    formatDateObject,
    formatDateOnly,
    formatDateTime,
    formatMonthDayWeekday,
    parseDateOnly,
    parseDateTime,
    timeLabel,
    todayIso,
    toIsoDate,
} from './dateFormat';

// 文字列はどれもローカル時刻として解釈するため、テスト実行環境のタイムゾーンに依らず同じ結果になる。
describe('dateFormat プリセット', () => {
    const value = '2026-10-09 14:05:30';

    it.each([
        ['long', '2026年10月9日(金) 14:05'],
        ['monthDayWeekday', '10/9(金) 14:05'],
        ['monthLongDayWeekday', '10月9日(金) 14:05'],
        ['medium', '2026/10/09 14:05'],
        ['short', '2026/10/09 14:05'],
        ['withSeconds', '2026/10/09 14:05:30'],
        ['numeric', '2026/10/9 14:05'],
        ['dateMedium', '2026/10/09'],
        ['dateLong', '2026年10月9日'],
    ] as const)('%s → %s', (preset, expected) => {
        expect(formatDateTime(value, preset)).toBe(expected);
    });

    it('時は 2 桁で 0 埋めする', () => {
        expect(formatDateTime('2026-10-09 09:05:00', 'long')).toBe('2026年10月9日(金) 09:05');
    });

    it('日付だけの値はローカルの 0 時として整形する', () => {
        expect(formatDateOnly('2026-10-09', 'dateLong')).toBe('2026年10月9日');
        expect(formatDateOnly('2026-10-09', 'dateMedium')).toBe('2026/10/09');
    });

    it('Date をそのまま整形できる', () => {
        expect(formatDateObject(new Date(2026, 9, 9, 14, 5), 'long')).toBe('2026年10月9日(金) 14:05');
    });
});

describe('parseDateTime / parseDateOnly', () => {
    it('空白区切りの日時をローカル時刻として解釈する', () => {
        expect(parseDateTime('2026-10-09 14:05:30').getTime()).toBe(new Date(2026, 9, 9, 14, 5, 30).getTime());
    });

    it('日付をローカルの 0 時として解釈する', () => {
        expect(parseDateOnly('2026-10-09').getTime()).toBe(new Date(2026, 9, 9).getTime());
    });
});

describe('formatMonthDayWeekday', () => {
    it("'YYYY-MM-DD' を「月/日（曜）」にする", () => {
        expect(formatMonthDayWeekday('2026-10-09')).toBe('10/9（金）');
        expect(formatMonthDayWeekday('2026-01-05')).toBe('1/5（月）');
    });

    it('形式が違う値は空文字', () => {
        expect(formatMonthDayWeekday('')).toBe('');
        expect(formatMonthDayWeekday('2026/10/09')).toBe('');
    });
});

describe('timeLabel', () => {
    it("日時の 'HH:MM' を取り出す", () => {
        expect(timeLabel('2026-10-09 14:05:30')).toBe('14:05');
        expect(timeLabel('2026-10-09T09:00:00')).toBe('09:00');
    });
});

describe('toIsoDate / todayIso', () => {
    it('ローカル日付を 0 埋めの YYYY-MM-DD にする', () => {
        expect(toIsoDate(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05');
    });

    it('今日の日付を返す', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 9, 8, 0));
        try {
            expect(todayIso()).toBe('2026-10-09');
        } finally {
            vi.useRealTimers();
        }
    });
});
