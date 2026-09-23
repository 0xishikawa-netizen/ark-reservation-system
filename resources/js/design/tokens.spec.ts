import { describe, expect, it } from 'vitest';
import { reservationStatusLabel, statusColor } from './tokens';

describe('reservationStatusLabel', () => {
    it('translates every known ReservationStatus value into Japanese', () => {
        expect(reservationStatusLabel('confirmed')).toBe('予約確定');
        expect(reservationStatusLabel('completed')).toBe('来店完了');
        expect(reservationStatusLabel('canceled')).toBe('キャンセル');
        expect(reservationStatusLabel('no_show')).toBe('無断キャンセル');
        expect(reservationStatusLabel('pending_payment')).toBe('支払い待ち');
        expect(reservationStatusLabel('pending_external_sync')).toBe('外部連携待ち');
        expect(reservationStatusLabel('expired')).toBe('期限切れ');
    });

    it('falls back to the raw value for an unknown status instead of throwing', () => {
        expect(reservationStatusLabel('something_new')).toBe('something_new');
    });

    it('returns an empty string for null/undefined', () => {
        expect(reservationStatusLabel(null)).toBe('');
        expect(reservationStatusLabel(undefined)).toBe('');
    });
});

describe('statusColor', () => {
    it('maps canceled/expired to a neutral color', () => {
        expect(statusColor('canceled')).toBe('grey');
        expect(statusColor('expired')).toBe('grey');
    });

    it('maps no_show to error', () => {
        expect(statusColor('no_show')).toBe('error');
    });

    it('falls back to grey for unknown statuses', () => {
        expect(statusColor('mystery')).toBe('grey');
    });
});
