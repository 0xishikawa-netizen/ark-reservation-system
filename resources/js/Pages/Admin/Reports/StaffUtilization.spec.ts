import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MonthField from '@/components/ark/MonthField.vue';
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
    it('shows staff, daily and monthly values with unknown / unsupported as a muted hyphen', () => {
        const page = render();
        const daily = page.get('[data-testid="daily-table"]');
        expect(daily.text()).toContain('担当B');
        expect(page.get('[data-testid="monthly-table"]').text()).toContain('50.0%');
        expect(daily.text()).not.toContain('未取得');
        expect(daily.text()).not.toContain('算出不可');
        // 出勤mが無い理由（勤務予定なし）と、指名・予約の未取得を区別して残す。
        expect(daily.findAll('.ark-empty-value[aria-label="勤務予定なし"]')).toHaveLength(1);
        expect(daily.findAll('.ark-empty-value[aria-label="未取得"]').length).toBeGreaterThan(0);
        expect(daily.findAll('.ark-empty-value[aria-label="算出不可"]').length).toBeGreaterThan(0);
        expect(daily.text()).toContain('実勤怠');
        // 0 は 0 のまま
        expect(daily.findAll('tbody tr')[1].text()).toContain('0');
        expect(page.text()).toContain('主担当不明の来店: 1件');
    });

    it('switches the daily list between all staff and a single staff and filters active rows', async () => {
        const page = render();
        expect(page.findAll('[data-testid="daily-table"] tbody tr')).toHaveLength(2);
        await page.get('[data-testid="staff-chip-2"]').trigger('click');
        const rows = page.findAll('[data-testid="daily-table"] tbody tr');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('9/11');
        // 1人表示ではスタッフ列を出さない
        expect(page.findAll('[data-testid="daily-table"] thead th').map((cell) => cell.text())).not.toContain('スタッフ');
        await page.get('[data-testid="staff-chip-all"]').trigger('click');
        expect(page.findAll('[data-testid="daily-table"] tbody tr')).toHaveLength(2);
        await page.get('[data-testid="daily-active-only"] input').setValue(true);
        const active = page.findAll('[data-testid="daily-table"] tbody tr');
        expect(active).toHaveLength(1);
        expect(active[0].text()).toContain('担当A');
    });

    it('groups the all-staff daily list by date or by staff', async () => {
        const page = render();
        const leadHeaders = () => page.findAll('[data-testid="daily-table"] thead tr.group-row th').slice(0, 2).map((cell) => cell.text());
        expect(leadHeaders()).toEqual(['日付', 'スタッフ']);
        await page.get('[data-testid="daily-grouping"] button:nth-child(2)').trigger('click');
        expect(leadHeaders()).toEqual(['スタッフ', '日付']);
    });

    it('reloads for month, employment type and staff and shows loading', async () => {
        let resolveFetch!: (value: Response) => void;
        const fetchMock = vi.spyOn(window, 'fetch').mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve; }));
        const page = render();
        page.getComponent(MonthField).vm.$emit('update:modelValue', '2026-10');
        await flushPromises();
        expect(page.get('[role="status"]').text()).toContain('読み込んでいます');
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=10');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();

        page.findAllComponents({ name: 'ReportSelect' }).find((select) => select.attributes('data-testid') === 'type-filter')?.vm.$emit('update:modelValue', 2);
        expect(fetchMock.mock.calls[1][0]).toContain('employment_type_id=2');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();

        page.findAllComponents({ name: 'ReportSelect' }).find((select) => select.attributes('data-testid') === 'staff-filter')?.vm.$emit('update:modelValue', 1);
        expect(fetchMock.mock.calls[2][0]).toContain('staff_id=1');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();
    });

    it('shows API errors without replacing the previous report', async () => {
        vi.spyOn(window, 'fetch').mockRejectedValue(new Error('network'));
        const page = render();
        page.getComponent(MonthField).vm.$emit('update:modelValue', '2026-10');
        await flushPromises();
        await flushPromises();
        expect(page.get('[role="alert"]').text()).toContain('読み込めませんでした');
        expect(page.get('[data-testid="monthly-table"]').text()).toContain('担当A');
    });
});
