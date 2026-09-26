import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MonthField from '@/components/ark/MonthField.vue';
import TimeBands from './TimeBands.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));

interface BandRow {
    business_date: string; staff_id: number; staff_name: string;
    band_code: string; band_label: string; occupied_minutes: number | null;
    known_occupied_minutes: number; occupied_unknown_count: number; working_minutes: number;
    bookable_minutes: number; legacy_utilization_rate: number;
    bookable_utilization_rate: number | null;
}
const row = (): BandRow => ({
    business_date: '2026-09-10', staff_id: 1, staff_name: '担当A',
    band_code: '10_12', band_label: '10:00–12:00', occupied_minutes: 30,
    known_occupied_minutes: 30, occupied_unknown_count: 0, working_minutes: 120,
    bookable_minutes: 105, legacy_utilization_rate: 0.25,
    bookable_utilization_rate: 30 / 105,
});
const report = () => ({ month_key: '2026-09', as_of_date: '2026-09-15',
    staff: [{ id: 1, name: '担当A' }], outside_band_minutes: 0,
    daily_rows: [row()], monthly_rows: [row()], overall_rows: [row()],
});

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); });

describe('time band utilization', () => {
    it('shows split minutes, rate, and unknown without converting null to zero', () => {
        const data = report();
        data.daily_rows.push({ ...row(), business_date: '2026-09-11', occupied_minutes: null,
            occupied_unknown_count: 1, bookable_utilization_rate: null });
        wrapper = mount(TimeBands, { props: { report: data, dataEndpoint: '/admin/reports/time-bands/data' } });
        expect(wrapper.get('[data-testid="overall-table"]').text()).toContain('10:00–12:00');
        expect(wrapper.get('[data-testid="monthly-table"]').text()).toContain('28.6%');
        expect(wrapper.get('[data-testid="daily-table"]').text()).toContain('担当A');
        // null は 0 にせず「-」（意味は aria-label に残す）
        expect(wrapper.get('[data-testid="daily-table"]').text()).not.toContain('未取得');
        expect(wrapper.get('[data-testid="daily-table"] .ark-empty-value[aria-label="未取得"]').text()).toBe('-');
        expect(wrapper.get('[data-testid="daily-table"] .ark-empty-value[aria-label="算出不可"]').text()).toBe('-');
        expect(wrapper.text()).toContain('対象時間帯外の実績分: 0分');
    });

    it('filters the daily list by staff chip and by date', async () => {
        const data = report();
        data.staff.push({ id: 2, name: '担当B' });
        data.daily_rows.push({ ...row(), staff_id: 2, staff_name: '担当B', business_date: '2026-09-11' });
        wrapper = mount(TimeBands, { props: { report: data, dataEndpoint: '/admin/reports/time-bands/data' } });
        expect(wrapper.findAll('[data-testid="daily-table"] tbody tr')).toHaveLength(2);
        await wrapper.get('[data-testid="staff-chip-2"]').trigger('click');
        expect(wrapper.findAll('[data-testid="daily-table"] tbody tr')).toHaveLength(1);
        await wrapper.get('[data-testid="staff-chip-all"]').trigger('click');
        wrapper.findAllComponents({ name: 'ReportSelect' }).find((select) => select.attributes('data-testid') === 'daily-date-filter')?.vm.$emit('update:modelValue', '2026-09-10');
        await wrapper.vm.$nextTick();
        const rows = wrapper.findAll('[data-testid="daily-table"] tbody tr');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('担当A');
    });

    it('reloads by month and staff and reports API errors', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ data: report() }) } as Response);
        wrapper = mount(TimeBands, { props: { report: report(), dataEndpoint: '/admin/reports/time-bands/data' } });
        wrapper.getComponent(MonthField).vm.$emit('update:modelValue', '2026-10');
        await flushPromises();
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=10');
        wrapper.findAllComponents({ name: 'ReportSelect' }).find((select) => select.attributes('data-testid') === 'staff-filter')?.vm.$emit('update:modelValue', 1);
        await flushPromises();
        expect(fetchMock.mock.calls[1][0]).toContain('staff_id=1');
        fetchMock.mockRejectedValueOnce(new Error('network'));
        wrapper.getComponent(MonthField).vm.$emit('update:modelValue', '2026-11');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('読み込めませんでした');
    });
});
