import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StaffUtilization from './StaffUtilization.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));

const row = () => ({
    staff_id: 1, staff_name: '担当A', business_date: '2026-09-10', employment_type_name: '社員',
    working_minutes: 480, working_minutes_source: 'actual' as const, scheduled_shift_present: true,
    occupied_minutes: 240, bookable_minutes: 360, patient_count: 10,
    future_reservation_count: 6, nomination_count: 2, nomination_supported: true,
    reservation_rate: 0.6, nomination_rate: 0.2, legacy_utilization_rate: 0.5,
    bookable_utilization_rate: 240 / 360,
});
const report = () => ({ month_key: '2026-09', as_of_date: '2026-09-15',
    staff: [{ id: 1, name: '担当A' }, { id: 2, name: '担当B' }],
    employment_types: [{ id: 1, name: '社員' }, { id: 2, name: 'アルバイト' }],
    unknown_primary_visit_count: 1,
    daily_rows: [row(), { ...row(), staff_id: 2, staff_name: '担当B', employment_type_name: null,
        business_date: '2026-09-11', working_minutes: null, working_minutes_source: 'unknown' as const,
        scheduled_shift_present: false, occupied_minutes: 0, patient_count: 0, future_reservation_count: null,
        nomination_count: null, nomination_supported: false, reservation_rate: null, nomination_rate: null,
        legacy_utilization_rate: null, bookable_utilization_rate: null }],
    monthly_rows: [row()],
});

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); });
function render() {
    wrapper = mount(StaffUtilization, { props: { report: report(), dataEndpoint: '/admin/reports/staff-utilization/data' } });
    return wrapper;
}

describe('Staff utilization report', () => {
    it('shows staff, daily and monthly values, unknown and weighted rates', () => {
        const page = render();
        expect(page.get('[data-testid="daily-table"]').text()).toContain('担当B');
        expect(page.get('[data-testid="monthly-table"]').text()).toContain('50.0%');
        expect(page.get('[data-testid="daily-table"]').text()).toContain('未取得');
        expect(page.get('[data-testid="daily-table"]').text()).toContain('勤務予定なし');
        expect(page.text()).toContain('主担当不明の来店: 1');
    });

    it('reloads for month, employment type and staff and shows loading', async () => {
        let resolveFetch!: (value: Response) => void;
        const fetchMock = vi.spyOn(window, 'fetch').mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve; }));
        const page = render();
        await page.get('[data-testid="month-input"]').setValue('2026-10');
        expect(page.get('[role="status"]').text()).toContain('読み込んでいます');
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=10');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();

        await page.get('[data-testid="type-filter"]').setValue('2');
        expect(fetchMock.mock.calls[1][0]).toContain('employment_type_id=2');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();

        await page.get('[data-testid="staff-filter"]').setValue('1');
        expect(fetchMock.mock.calls[2][0]).toContain('staff_id=1');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();
    });

    it('shows API errors without replacing the previous report', async () => {
        vi.spyOn(window, 'fetch').mockRejectedValue(new Error('network'));
        const page = render();
        await page.get('[data-testid="month-input"]').setValue('2026-10');
        await flushPromises();
        expect(page.get('[role="alert"]').text()).toContain('読み込めませんでした');
        expect(page.get('[data-testid="monthly-table"]').text()).toContain('担当A');
    });
});
