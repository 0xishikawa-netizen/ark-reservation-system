import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import NewReservationPanel from './NewReservationPanel.vue';
import ScheduleBlockCreatePanel from './ScheduleBlockCreatePanel.vue';
import { createEmptyBlockDraft, createEmptyReservationDraft } from '@/composables/reservationDraft';

/**
 * 全面検証（2026-09-27）で見つけた不具合の再発防止。
 * 作成に成功するとサーバーが台帳へリダイレクトし、パネルは onSuccess より先にアンマウントされる。
 * 完了処理を emit で親へ伝えていたため Vue に捨てられ、下書き（前回の顧客・メニュー・ブース）が
 * 次の予約へ引き継がれていた。アンマウント後に onSuccess が来ても完了処理が呼ばれることを確認する。
 */
type PostOptions = { onSuccess?: () => void };
const posted = vi.hoisted(() => ({ calls: [] as Array<{ url: string; options: PostOptions }> }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: <T extends Record<string, unknown>>(data: T) => {
            const form = reactive({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                post: (url: string, options: PostOptions) => posted.calls.push({ url, options }),
                clearErrors: () => undefined,
                data: () => Object.fromEntries(Object.keys(data).map((key) => [key, (form as Record<string, unknown>)[key]])),
            });

            return form;
        },
        router: { get: vi.fn(), visit: vi.fn(), post: vi.fn() },
    };
});

// 作成日を過去日と判定されないよう、現在日時を固定する（Date のみ）。
beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-02T09:00:00+09:00'));
});

afterEach(() => {
    vi.useRealTimers();
    posted.calls.length = 0;
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('作成パネルの完了処理', () => {
    it('予約作成：パネルがアンマウントされた後の onSuccess でも afterCreate が呼ばれる', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => [{ starts_at: '2026-10-02 14:00:00', ends_at: '2026-10-02 15:00:00', available_staff_ids: [7] }],
        }));
        const draft = {
            ...createEmptyReservationDraft(),
            customer_id: 1, customer_name: '顧客', service_id: 11, staff_id: 7, date: '2026-10-02', starts_at: '2026-10-02 14:00:00',
        };
        const afterCreate = vi.fn();
        const wrapper = mount(NewReservationPanel, {
            attachTo: document.body,
            props: {
                services: [{ id: 11, name: 'パーソナル60', duration_min: 60, requires_staff: true, staff_ids: [7], booth_ids: [], price: 8800, category: null, color: '#1A2653' }],
                staff: [{ user_id: 7, display_name: '担当A', color: '#1A2653' }],
                booths: [],
                prefill: { customer_id: null, service_id: null, staff_id: null, booth_id: null, date: null, time: null },
                draft,
                afterCreate,
            },
        });
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 400));
        await flushPromises();

        const button = wrapper.findAll('button').find((b) => b.text().includes('予約を作成'));
        expect(button?.attributes('disabled')).toBeUndefined();
        await button!.trigger('click');
        expect(posted.calls[0]?.url).toContain('/admin/reservations');

        wrapper.unmount();
        posted.calls[0].options.onSuccess?.();

        expect(afterCreate).toHaveBeenCalledWith({ date: '2026-10-02' });
    });

    it('予定追加：パネルがアンマウントされた後の onSuccess でも afterCreate が呼ばれる', async () => {
        const draft = { ...createEmptyBlockDraft(), staff_id: 7, work_date: '2026-10-02', start_at: '12:00', end_at: '13:00' };
        const afterCreate = vi.fn();
        const wrapper = mount(ScheduleBlockCreatePanel, {
            attachTo: document.body,
            props: {
                staff: [{ user_id: 7, display_name: '担当A', color: '#1A2653' }],
                businessHours: { open: '10:00', close: '21:00', slot_minutes: 15 },
                prefill: { staff_id: null, booth_id: null, date: null, time: null },
                draft,
                afterCreate,
            },
        });
        await flushPromises();

        const button = wrapper.findAll('button').find((b) => b.text().includes('追加する'));
        await button!.trigger('click');
        expect(posted.calls[0]?.url).toContain('/admin/schedule/blocks');

        wrapper.unmount();
        posted.calls[0].options.onSuccess?.();

        expect(afterCreate).toHaveBeenCalledTimes(1);
    });
});
