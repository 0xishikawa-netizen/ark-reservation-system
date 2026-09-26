import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DateField from '@/components/ark/DateField.vue';
import YearField from '@/components/ark/YearField.vue';
import Annual from './Annual.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));

const report = () => ({
    year: 2026, as_of_date: '2026-10-15', sales_basis: 'payment_date' as const,
    months: [
        { month: 1, month_key: '2026-01', is_future: false, payment_date_revenue: 1000,
            treatment_date_revenue: 900, selected_revenue: 1000, target_amount: 2000,
            achievement_rate: 0.5, visit_count: 2, long_visit_count: 1,
            future_reservation_count: 1, reservation_rate: 0.5, first_visit_count: 1,
            first_visit_reservation_count: 1, new_customers: 1, returning_customers: 0,
            churn_customers: 0, reached_2: 1, reached_6: 0, reached_10: 0,
            legacy_utilization_rate: 0.25, bookable_utilization_rate: 0.5 },
        { month: 11, month_key: '2026-11', is_future: true, payment_date_revenue: 0,
            treatment_date_revenue: 0, selected_revenue: 0, target_amount: null,
            achievement_rate: null, visit_count: 0, long_visit_count: 0,
            future_reservation_count: 0, reservation_rate: null, first_visit_count: 0,
            first_visit_reservation_count: 0, new_customers: 0, returning_customers: 0,
            churn_customers: null, reached_2: 0, reached_6: 0, reached_10: 0,
            legacy_utilization_rate: null, bookable_utilization_rate: null },
    ],
    totals: { payment_date_revenue: 1000, treatment_date_revenue: 900, selected_revenue: 1000,
        target_amount: null, achievement_rate: null, visit_count: 2, long_visit_count: 1,
        future_reservation_count: 1, reservation_rate: 0.5, first_visit_count: 1,
        first_visit_reservation_count: 1, new_customers: 1, returning_customers: 0,
        churn_customers: null, reached_2: 1, reached_6: 0, reached_10: 0,
        legacy_utilization_rate: 0.25, bookable_utilization_rate: 0.5 },
});

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); });

describe('annual report', () => {
    it('shows twelve-month style values, future blank, and nullable annual target', () => {
        wrapper = mount(Annual, { props: { report: report(), dataEndpoint: '/admin/reports/annual/data' } });
        const table = wrapper.get('[data-testid="annual-table"]').text();
        expect(table).toContain('1,000円');
        expect(table).toContain('50.0%');
        expect(table).toContain('年間合計');
        expect(table).not.toContain('未実績');
        expect(table).not.toContain('未設定');
        const tableEl = wrapper.get('[data-testid="annual-table"]');
        // 未来月は「-」（未実績）。0 実績は 0 のまま。
        expect(tableEl.findAll('.ark-empty-value[aria-label="未実績"]').length).toBeGreaterThan(0);
        expect(tableEl.findAll('.ark-empty-value[aria-label="未設定"]').length).toBeGreaterThan(0);
        expect(tableEl.findAll('tbody tr')[0].text()).toContain('0');
        expect(tableEl.findAll('thead tr.group-row th').map((cell) => cell.text())).toEqual(['月', '売上', '来店・予約', '初診', '顧客', '到達', '稼働率']);
        expect(wrapper.find('input[type="number"]').exists()).toBe(false);
        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
    });

    it('reloads year, basis, and as-of and preserves result on failure', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ data: report() }) } as Response);
        wrapper = mount(Annual, { props: { report: report(), dataEndpoint: '/admin/reports/annual/data' } });
        wrapper.getComponent(YearField).vm.$emit('update:modelValue', 2027);
        await flushPromises();
        expect(fetchMock.mock.calls[0][0]).toContain('year=2027');
        expect(fetchMock.mock.calls[0][0]).not.toContain('as_of_date');
        wrapper.findAllComponents({ name: 'ReportSelect' })[1].vm.$emit('update:modelValue', 'treatment_date');
        await flushPromises();
        expect(fetchMock.mock.calls[1][0]).toContain('basis=treatment_date');
        fetchMock.mockRejectedValueOnce(new Error('network'));
        wrapper.getComponent(DateField).vm.$emit('update:modelValue', '2027-02-28');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('読み込めませんでした');
        expect(wrapper.get('[data-testid="annual-table"]').text()).toContain('1,000円');
    });

    it('switches between fiscal year and calendar year', async () => {
        const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: { ...report(), period: 'fiscal' } }), { status: 200 }));
        wrapper = mount(Annual, { props: { report: report(), dataEndpoint: '/admin/reports/annual/data' } });
        wrapper.findAllComponents({ name: 'ReportSelect' })[0].vm.$emit('update:modelValue', 'fiscal');
        await flushPromises();
        expect(fetchMock.mock.calls[0][0]).toContain('period=fiscal');
        expect(wrapper.text()).toContain('年度');
    });
});
