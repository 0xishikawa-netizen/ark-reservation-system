import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Monthly from './Monthly.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));

const row = (day: number) => ({
    business_date: `2026-10-${String(day).padStart(2, '0')}`, day, weekday: day === 3 ? '土' : '木', weekday_iso: day === 3 ? 6 : 4,
    is_closed: false, is_future: day > 15, selected_revenue: day === 1 ? 1100 : 0,
    payment_date_revenue: day === 1 ? 1100 : 0, treatment_date_revenue: 0,
    payment_method_totals: day === 1 ? [{ payment_method_id: 1, code: 'cash', name: '現金', amount: 1100 }] : [],
    tax_totals: day === 1 ? [{ tax_category_code: 'standard', tax_category_name: '標準', tax_rate_bps: 1000, net_amount: 1000, tax_amount: 100, gross_amount: 1100, line_count: 1 }] : [],
    visit_count: day === 1 ? 1 : 0, long_visit_count: 0, future_reservation_count: 0, future_reservation_unknown_count: 0,
    future_reservation_rate: { numerator: 0, denominator: day === 1 ? 1 : 0, value: day === 1 ? 0 : null },
    first_visit_count: 0, first_visit_reservation_count: 0, first_visit_reservation_unknown_count: 0,
    first_visit_reservation_rate: { numerator: 0, denominator: 0, value: null },
    analysis_category_visit_counts: { M: 0, T: 0, A: 0, 'M&T': 0, 'A&T': 0 }, unknown_analysis_category_visit_count: 0,
});

const report = () => ({
    year: 2026, month: 10, month_key: '2026-10', as_of_date: '2026-10-15', sales_basis: 'payment_date' as const,
    daily_rows: Array.from({ length: 31 }, (_, index) => row(index + 1)),
    payment_methods: [{ payment_method_id: 1, code: 'cash', name: '現金', amount: 1100 }],
    tax_buckets: [{ tax_category_code: 'standard', tax_category_name: '標準', tax_rate_bps: 1000, net_amount: 1000, tax_amount: 100, gross_amount: 1100, line_count: 1 }],
    totals: { selected_revenue: 1100, payment_date_revenue: 1100, treatment_date_revenue: 0, visit_count: 1, long_visit_count: 0, future_reservation_count: 0, first_visit_count: 0, first_visit_reservation_count: 0, unknown_analysis_category_visit_count: 0, analysis_category_visit_counts: { M: 0, T: 0, A: 0, 'M&T': 0, 'A&T': 0 } },
    ratios: { future_reservation_rate: { numerator: 0, denominator: 1, value: 0 }, first_visit_reservation_rate: { numerator: 0, denominator: 0, value: null } },
    target: null,
    progress: { target_amount: null, actual_amount: 1100, difference_amount: null, remaining_required_amount: null, achievement_rate: null, required_daily_average: null },
    business_days: { calendar_days: 31, total: 31, elapsed: 15, input_days: 15, remaining: 16, closed: 0, elapsed_weekdays: 11, elapsed_weekends: 4 },
    averages: { daily_sales: 73.3, daily_visits: 0.1, weekday_sales: 100, weekend_sales: 0, weekday_visits: 0.1, weekend_visits: 0 },
    periods: { first: { selected_revenue: 1100, visit_count: 1 }, second: { selected_revenue: 0, visit_count: 0 } },
});

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); });

function render() {
    wrapper = mount(Monthly, { props: { report: report(), dataEndpoint: '/admin/reports/monthly/data' } });
    return wrapper;
}

describe('Monthly report page', () => {
    it('renders data, dynamic columns, zero, nullable values and future state distinctly', () => {
        const page = render();
        expect(page.text()).toContain('現金');
        expect(page.text()).toContain('標準 10% 税額');
        expect(page.text()).toContain('31日');
        expect(page.text()).toContain('0.0%');
        expect(page.text()).toContain('—');
        expect(page.text()).toContain('未実績');
    });

    it('reloads for month and sales basis changes and displays loading', async () => {
        let resolveFetch!: (value: Response) => void;
        const fetchMock = vi.spyOn(window, 'fetch').mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve; }));
        const page = render();

        await page.get('[data-testid="month-input"]').setValue('2026-11');
        expect(page.text()).toContain('月計を読み込んでいます');
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=11&basis=payment_date');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();

        await page.get('[data-testid="basis-select"]').setValue('treatment_date');
        expect(fetchMock.mock.calls[1][0]).toContain('basis=treatment_date');
        resolveFetch({ ok: true, json: async () => ({ data: { ...report(), sales_basis: 'treatment_date' } }) } as Response);
        await flushPromises();
        expect(page.text()).toContain('施術日基準');
    });

    it('shows the shared API error message', async () => {
        vi.spyOn(window, 'fetch').mockRejectedValue(new Error('network'));
        const page = render();
        await page.get('[data-testid="basis-select"]').setValue('treatment_date');
        await flushPromises();

        expect(page.get('[role="alert"]').text()).toBe('月計を読み込めませんでした。時間をおいて再度お試しください。');
    });

    it('shows a permission-gated export link only for the compatible payment-date basis', async () => {
        const allowed = mount(Monthly, { props: { report: report(), dataEndpoint: '/admin/reports/monthly/data',
            exportEndpoint: '/admin/reports/excel' } });
        expect(allowed.get('[data-testid="excel-export"]').attributes('href'))
            .toContain('year=2026&month=10&basis=payment_date&as_of_date=2026-10-15');
        vi.spyOn(window, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ data: { ...report(), sales_basis: 'treatment_date' } }) } as Response);
        await allowed.get('[data-testid="basis-select"]').setValue('treatment_date');
        await flushPromises();
        expect(allowed.find('[data-testid="excel-export"]').exists()).toBe(false);
        expect(allowed.text()).toContain('原本形式のExcel出力は決済日基準のみ対応しています。');
        allowed.unmount();
        const denied = render();
        expect(denied.find('[data-testid="excel-export"]').exists()).toBe(false);
    });
});
