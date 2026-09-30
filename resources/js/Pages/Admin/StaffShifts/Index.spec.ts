import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Index from './Index.vue';

const state = vi.hoisted(() => ({ forms: [] as Record<string, unknown>[], posts: [] as string[], puts: [] as string[] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Head: { template: '<div />' },
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
        usePage: () => ({ props: { auth: { can: {} } } }),
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({ ...initial, errors: {}, processing: false,
                post: (url: string) => { state.posts.push(url); },
                put: (url: string) => { state.puts.push(url); },
                reset: vi.fn(),
            });
            state.forms.push(form);
            return form;
        },
    };
});

describe('Staff shift attendance extension', () => {
    it('keeps actual attendance separate and submits create/update through the existing page', async () => {
        state.forms = []; state.posts = []; state.puts = [];
        const page = mount(Index, { props: {
            staff: [{ user_id: 1, display_name: '担当A', is_bookable: true }], selected_staff_id: 1,
            templates: [], exceptions: [], shifts: [],
            attendances: [{ id: 5, business_date: '2026-09-10', clock_in_at: '2026-09-10T09:00',
                clock_out_at: '2026-09-10T18:00', status: 'confirmed', note: '既存',
                breaks: [{ start_at: '2026-09-10T12:00', end_at: '2026-09-10T13:00', type: 'break', note: null }] }],
            booking: { horizon_mode: 'none', horizon_days: 30, release_day_of_month: 20,
                min_lead_minutes: 0, closed_dates: [], enforced: false, last_bookable_date: null },
            filters: { staff_id: 1, from: '2026-09-01', to: '2026-09-30' },
        } });
        await page.findAll('.v-tab').find((tab) => tab.text().includes('出退勤の記録'))!.trigger('click');
        await flushPromises();
        expect(page.text()).toContain('出退勤の記録（実績）');
        const attendanceForm = state.forms[0] as Record<string, unknown>;
        attendanceForm.business_date = '2026-09-11';
        await page.findAll('button').find((button) => button.text().includes('記録を保存'))!.trigger('click');
        expect(state.posts).toContain('/admin/staff-shifts/attendances');
        await page.findAll('button').find((button) => button.text().includes('修正'))!.trigger('click');
        expect(attendanceForm.business_date).toBe('2026-09-10');
        await page.findAll('button').find((button) => button.text().includes('記録を保存'))!.trigger('click');
        // 日付＋時刻の入力から日時と休憩を組み立てて送る。
        expect(attendanceForm.clock_in_at).toBe('2026-09-10T09:00');
        expect(attendanceForm.clock_out_at).toBe('2026-09-10T18:00');
        expect((attendanceForm.breaks as unknown[]).length).toBe(1);
        expect(state.puts).toContain('/admin/staff-shifts/attendances/5');
        page.unmount();
    });
    it('opens on the staff tab by default instead of jumping to the store tab (two tab rows share one value)', async () => {
        state.forms = []; state.posts = []; state.puts = [];
        window.history.replaceState(null, '', '/admin/staff-shifts');
        const page = mount(Index, { props: {
            staff: [{ user_id: 1, display_name: '担当A', is_bookable: true }], selected_staff_id: 1,
            templates: [], exceptions: [], shifts: [], attendances: [],
            booking: { horizon_mode: 'none', horizon_days: 30, release_day_of_month: 20,
                min_lead_minutes: 0, closed_dates: [], enforced: false, last_bookable_date: null },
            filters: { staff_id: 1, from: '2026-09-01', to: '2026-09-30' },
        } });
        await flushPromises();
        const selected = page.findAll('.v-tab--selected').map((tab) => tab.text());
        expect(selected).toEqual(['いつもの勤務（基本シフト）']);
        page.unmount();
    });
});
