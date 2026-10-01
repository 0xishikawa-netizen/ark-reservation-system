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
                reset: vi.fn(), clearErrors: vi.fn(),
            });
            state.forms.push(form);
            return form;
        },
    };
});

describe('Staff shift attendance extension', () => {
    it('lists days from the board shifts and records/edits attendance through the existing endpoints', async () => {
        state.forms = []; state.posts = []; state.puts = [];
        window.history.replaceState(null, '', '/admin/staff-shifts');
        const page = mount(Index, { props: {
            staff: [{ user_id: 1, display_name: '担当A', is_bookable: true }], selected_staff_id: 1,
            templates: [], exceptions: [], shifts: [],
            timesheet: [
                { date: '2026-09-10', planned: { start: '10:00', end: '19:00', work_min: 540, break_min: 60, breaks: [{ start: '12:00', end: '13:00' }] },
                    attendance: { id: 5, status: 'confirmed', note: '既存', clock_in: '10:15', clock_out: '19:30', work_min: 495, break_min: 60, breaks: [{ start: '12:00', end: '13:00', type: 'break' }] },
                    flags: [{ type: 'late_start', minutes: 15 }, { type: 'overtime', minutes: 30 }], overtime_min: 15, booked_count: 4, booked_min: 300, available_min: 495, utilization: 61 },
                { date: '2026-09-11', planned: { start: '10:00', end: '18:00', work_min: 480, break_min: 0, breaks: [] },
                    attendance: null, flags: [], overtime_min: 0, booked_count: 2, booked_min: 120, available_min: 480, utilization: 25 },
            ],
            booking: { horizon_mode: 'none', horizon_days: 30, release_day_of_month: 20,
                min_lead_minutes: 0, closed_dates: [], enforced: false, last_bookable_date: null },
            filters: { staff_id: 1, from: '2026-09-01', to: '2026-09-30' },
        } });
        await page.findAll('.v-tab').find((tab) => tab.text().includes('出退勤の記録'))!.trigger('click');
        await flushPromises();
        const first = page.get('[data-testid="ts-row-2026-09-10"]').text();
        expect(first).toContain('遅出 15分');
        expect(first).toContain('残業 30分');
        expect(first).toContain('61%');
        expect(page.get('[data-testid="ts-row-2026-09-11"]').text()).toContain('予定どおりで記録');
        // 修正（実績あり）→ 日付と時刻から組み立てて PUT。
        await page.get('[data-testid="ts-row-2026-09-10"]').findAll('button').find((b) => b.text().includes('修正'))!.trigger('click');
        await flushPromises();
        const attendanceForm = state.forms[0] as Record<string, unknown>;
        expect(attendanceForm.business_date).toBe('2026-09-10');
        const save = [...document.querySelectorAll('button')].find((b) => b.textContent?.includes('記録を保存'));
        save?.click();
        await flushPromises();
        expect(state.puts).toContain('/admin/staff-shifts/attendances/5');
        expect(attendanceForm.clock_in_at).toBe('2026-09-10T10:15');
        expect(attendanceForm.clock_out_at).toBe('2026-09-10T19:30');
        expect((attendanceForm.breaks as unknown[]).length).toBe(1);
        page.unmount();
    });

    it('opens on the staff tab by default instead of jumping to the store tab (two tab rows share one value)', async () => {
        state.forms = []; state.posts = []; state.puts = [];
        window.history.replaceState(null, '', '/admin/staff-shifts');
        const page = mount(Index, { props: {
            staff: [{ user_id: 1, display_name: '担当A', is_bookable: true }], selected_staff_id: 1,
            templates: [], exceptions: [], shifts: [], timesheet: [],
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
