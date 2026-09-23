import { describe, expect, it } from 'vitest';
import {
    applyBlockPrefill,
    applyReservationPrefill,
    blockEndTimeFrom,
    createEmptyBlockDraft,
    createEmptyReservationDraft,
    resetBlockDraft,
    resetReservationDraft,
} from './reservationDraft';

describe('reservationDraft', () => {
    it('creates an empty draft with all fields null/blank', () => {
        const draft = createEmptyReservationDraft();

        expect(draft.customer_id).toBeNull();
        expect(draft.service_id).toBeNull();
        expect(draft.staff_id).toBeNull();
        expect(draft.booth_id).toBeNull();
        expect(draft.is_staff_requested).toBe(false);
        expect(draft.date).toBe('');
        expect(draft.starts_at).toBeNull();
        expect(draft.notes).toBe('');
    });

    it('keeps existing values when prefill has no new information (パネル切替・戻るで入力を失わない)', () => {
        const draft = createEmptyReservationDraft();
        draft.customer_id = 42;
        draft.service_id = 7;
        draft.notes = '腰部注意';

        applyReservationPrefill(draft, {
            customer_id: null,
            service_id: null,
            staff_id: null,
            booth_id: null,
            date: null,
            time: null,
        });

        expect(draft.customer_id).toBe(42);
        expect(draft.service_id).toBe(7);
        expect(draft.notes).toBe('腰部注意');
    });

    it('overwrites only the fields that the new prefill actually provides', () => {
        const draft = createEmptyReservationDraft();
        draft.customer_id = 42;
        draft.notes = '腰部注意';

        applyReservationPrefill(draft, {
            customer_id: null,
            service_id: null,
            staff_id: 5,
            booth_id: 2,
            date: '2026-09-20',
            time: '10:00',
        });

        // 新しい枠の情報だけ反映される。
        expect(draft.staff_id).toBe(5);
        expect(draft.booth_id).toBe(2);
        expect(draft.booth_manually_set).toBe(true);
        expect(draft.date).toBe('2026-09-20');
        expect(draft.starts_at).toBe('2026-09-20 10:00:00');

        // 新しい情報がない項目（顧客・メモ）はそのまま残る。
        expect(draft.customer_id).toBe(42);
        expect(draft.notes).toBe('腰部注意');
    });

    it('resets a reservation draft back to empty', () => {
        const draft = createEmptyReservationDraft();
        draft.customer_id = 1;
        draft.notes = 'something';

        resetReservationDraft(draft);

        expect(draft.customer_id).toBeNull();
        expect(draft.notes).toBe('');
    });

    it('creates an empty block draft defaulting to BREAK / staff target', () => {
        const draft = createEmptyBlockDraft();

        expect(draft.target_kind).toBe('staff');
        expect(draft.type).toBe('BREAK');
        expect(draft.staff_id).toBeNull();
        expect(draft.booth_id).toBeNull();
    });

    it('applies block prefill for staff target and computes a +60min end time', () => {
        const draft = createEmptyBlockDraft();

        applyBlockPrefill(draft, {
            staff_id: 9,
            booth_id: null,
            date: '2026-09-20',
            time: '13:30',
        });

        expect(draft.target_kind).toBe('staff');
        expect(draft.staff_id).toBe(9);
        expect(draft.work_date).toBe('2026-09-20');
        expect(draft.start_at).toBe('13:30');
        expect(draft.end_at).toBe('14:30');
    });

    it('applies block prefill for booth target when staff_id is absent', () => {
        const draft = createEmptyBlockDraft();

        applyBlockPrefill(draft, {
            staff_id: null,
            booth_id: 3,
            date: '2026-09-20',
            time: '23:40',
        });

        expect(draft.target_kind).toBe('booth');
        expect(draft.booth_id).toBe(3);
        // 日をまたぐ時刻も正しく繰り上がる。
        expect(draft.end_at).toBe('00:40');
    });

    it('keeps existing block draft fields when prefill provides nothing new', () => {
        const draft = createEmptyBlockDraft();
        draft.type = 'MEETING';
        draft.note = 'メモ済み';

        applyBlockPrefill(draft, { staff_id: null, booth_id: null, date: null, time: null });

        expect(draft.type).toBe('MEETING');
        expect(draft.note).toBe('メモ済み');
    });

    it('resets a block draft back to empty', () => {
        const draft = createEmptyBlockDraft();
        draft.type = 'OTHER';
        draft.title = 'x';

        resetBlockDraft(draft);

        expect(draft.type).toBe('BREAK');
        expect(draft.title).toBe('');
    });

    it('blockEndTimeFrom returns blank for null input', () => {
        expect(blockEndTimeFrom(null)).toBe('');
    });
});
