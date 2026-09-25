import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Index from './Index.vue';

const state = vi.hoisted(() => ({ forms: [] as Record<string, unknown>[], posts: [] as string[], puts: [] as string[] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Head: { template: '<div />' },
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
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
        await page.findAll('.v-tab').find((tab) => tab.text().includes('実勤怠'))!.trigger('click');
        await flushPromises();
        expect(page.text()).toContain('実出退勤・休憩');
        const attendanceForm = state.forms[0] as Record<string, unknown>;
        attendanceForm.business_date = '2026-09-11';
        attendanceForm.clock_in_at = '2026-09-11T09:00';
        attendanceForm.clock_out_at = '2026-09-11T18:00';
        await page.findAll('button').find((button) => button.text().includes('勤怠を保存'))!.trigger('click');
        expect(state.posts).toContain('/admin/staff-shifts/attendances');
        await page.findAll('button').find((button) => button.text().includes('編集'))!.trigger('click');
        expect(attendanceForm.business_date).toBe('2026-09-10');
        expect((attendanceForm.breaks as unknown[]).length).toBe(1);
        await page.findAll('button').find((button) => button.text().includes('勤怠を保存'))!.trigger('click');
        expect(state.puts).toContain('/admin/staff-shifts/attendances/5');
        page.unmount();
    });
});
