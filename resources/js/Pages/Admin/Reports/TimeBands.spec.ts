import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
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
        expect(wrapper.get('[data-testid="daily-table"]').text()).toContain('未取得');
        expect(wrapper.text()).toContain('対象時間帯外の実績分: 0');
    });

    it('reloads by month and staff and reports API errors', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ data: report() }) } as Response);
        wrapper = mount(TimeBands, { props: { report: report(), dataEndpoint: '/admin/reports/time-bands/data' } });
        await wrapper.get('[data-testid="month-input"]').setValue('2026-10');
        await flushPromises();
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=10');
        await wrapper.get('[data-testid="staff-filter"]').setValue('1');
        await flushPromises();
        expect(fetchMock.mock.calls[1][0]).toContain('staff_id=1');
        fetchMock.mockRejectedValueOnce(new Error('network'));
        await wrapper.get('[data-testid="month-input"]').setValue('2026-11');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('読み込めませんでした');
    });
});
