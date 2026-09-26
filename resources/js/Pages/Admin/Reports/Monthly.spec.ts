import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MonthField from '@/components/ark/MonthField.vue';
import Monthly from './Monthly.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));

const row = (day: number) => ({
    business_date: `2026-10-${String(day).padStart(2, '0')}`, day, weekday: day === 3 ? '土' : '木', weekday_iso: day === 3 ? 6 : 4,
    is_closed: false, is_future: day > 15, selected_revenue: day === 1 ? 1100 : 0,
    payment_date_revenue: day === 1 ? 1100 : 0, treatment_date_revenue: 0,
    payment_method_totals: day === 1 ? [{ payment_method_id: 1, code: 'cash', name: '現金', amount: 1100 }] : [],
    tax_totals: day === 1 ? [{ tax_category_code: 'standard', tax_category_name: '標準', tax_rate_bps: 1000, net_amount: 1000, tax_amount: 100, gross_amount: 1100, line_count: 1 }] : [],
    payment_category_totals: day === 1 ? [
        { payment_method_id: 1, code: 'cash', name: '現金', amount: 1100, treatment_amount: 780, retail_amount: 320, unallocated_amount: 0 },
    ] : [],
    sales_split: day === 1
        ? { treatment: { net: 710, tax: 70, gross: 780 }, retail: { net: 297, tax: 23, gross: 320 } }
        : { treatment: { net: 0, tax: 0, gross: 0 }, retail: { net: 0, tax: 0, gross: 0 } },
    net_sales: day === 1 ? 1007 : 0, sales_tax: day === 1 ? 93 : 0, gross_sales: day === 1 ? 1100 : 0,
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
    totals: { net_sales: 1007, sales_tax: 93, gross_sales: 1100,
        sales_split: { treatment: { net: 710, tax: 70, gross: 780 }, retail: { net: 297, tax: 23, gross: 320 } },
        payment_category_totals: [{ payment_method_id: 1, code: 'cash', name: '現金', amount: 1100, treatment_amount: 780, retail_amount: 320, unallocated_amount: 0 }],
        selected_revenue: 1100, payment_date_revenue: 1100, treatment_date_revenue: 0, visit_count: 1, long_visit_count: 0, future_reservation_count: 0, first_visit_count: 0, first_visit_reservation_count: 0, unknown_analysis_category_visit_count: 0, analysis_category_visit_counts: { M: 0, T: 0, A: 0, 'M&T': 0, 'A&T': 0 } },
    ratios: { future_reservation_rate: { numerator: 0, denominator: 1, value: 0 }, first_visit_reservation_rate: { numerator: 0, denominator: 0, value: null } },
    target: null,
    progress: { target_amount: null, actual_amount: 1100, difference_amount: null, remaining_required_amount: null, achievement_rate: null, required_daily_average: null },
    business_days: { calendar_days: 31, total: 31, elapsed: 15, input_days: 15, remaining: 16, closed: 0, elapsed_weekdays: 11, elapsed_weekends: 4 },
    averages: { daily_sales: 73.3, daily_visits: 0.1, weekday_sales: 100, weekend_sales: 0, weekday_visits: 0.1, weekend_visits: 0 },
    periods: { first: { selected_revenue: 1100, visit_count: 1 }, second: { selected_revenue: 0, visit_count: 0 } },
});

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); });

const paymentMethodColumns = [
    { payment_method_id: 1, code: 'cash', name: '現金' },
    { payment_method_id: 2, code: 'paypay', name: 'PayPay' },
    { payment_method_id: 7, code: 'id', name: 'iD' },
];

function render() {
    wrapper = mount(Monthly, { props: { report: report(), dataEndpoint: '/admin/reports/monthly/data', paymentMethodColumns } });
    return wrapper;
}

const changeMonth = (page: VueWrapper, value: string) => page.getComponent(MonthField).vm.$emit('update:modelValue', value);
const changeBasis = (page: VueWrapper, value: string) => page.getComponent({ name: 'ReportSelect' }).vm.$emit('update:modelValue', value);

describe('Monthly report page', () => {
    it('renders zero as 0 and nullable / future values as a muted hyphen keeping their meaning', () => {
        const page = render();
        const table = page.get('[data-testid="monthly-daily-table"]');
        expect(page.text()).toContain('標準 10% 税抜売上');
        expect(page.text()).toContain('31日');
        expect(table.text()).toContain('0.0%');
        expect(table.text()).toContain('0円');
        expect(page.text()).not.toContain('算出不可');
        expect(page.text()).not.toContain('未実績');
        expect(page.text()).not.toContain('未設定');
        expect(table.findAll('.ark-empty-value[aria-label="算出不可"]').length).toBeGreaterThan(0);
        expect(table.findAll('.ark-empty-value[aria-label="未実績"]').length).toBeGreaterThan(0);
        expect(table.get('tfoot .ark-empty-value[aria-label="該当なし"]').text()).toBe('-');
        expect(page.get('.ark-empty-value[aria-label="未設定"]').text()).toBe('-');
    });

    it('orders daily columns like the canonical 月計表 (treatment payments → retail payments → sales → visits → first visit → categories)', () => {
        const page = render();
        const headers = page.findAll('[data-testid="monthly-daily-table"] thead tr:nth-child(2) th').map((cell) => cell.text());
        expect(headers).toEqual(['現金', 'PayPay', 'iD', '計（税抜）', '現金', 'PayPay', 'iD', '計（税抜）', '税抜売上', '税額', '税込売上', '売上金決済日基準',
            '来店数', 'ロング', '予約', '予約率', '初診数', '初診予約', '初診予約率', 'M', 'T', 'A', 'M&T', 'A&T', '分類不明']);
        const groups = page.findAll('[data-testid="monthly-daily-table"] thead tr.group-row th').map((cell) => cell.text());
        expect(groups).toEqual(['日', '曜', '施術等 決済別（税込）', '物販 決済別（税込）', '売上', '来店', '初診', '施術分類']);
        const firstRow = page.findAll('[data-testid="monthly-daily-table"] tbody tr')[0].findAll('td').map((cell) => cell.text());
        expect(firstRow.slice(1, 13)).toEqual(['780円', '0円', '0円', '710円', '320円', '0円', '0円', '297円', '1,007円', '93円', '1,100円', '1,100円']);
    });

    it('reloads for month and sales basis changes and displays loading', async () => {
        let resolveFetch!: (value: Response) => void;
        const fetchMock = vi.spyOn(window, 'fetch').mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve; }));
        const page = render();

        changeMonth(page, '2026-11');
        await flushPromises();
        expect(page.text()).toContain('月計を読み込んでいます');
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=11&basis=payment_date');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();

        changeBasis(page, 'treatment_date');
        expect(fetchMock.mock.calls[1][0]).toContain('basis=treatment_date');
        resolveFetch({ ok: true, json: async () => ({ data: { ...report(), sales_basis: 'treatment_date' } }) } as Response);
        await flushPromises();
        expect(page.text()).toContain('施術日基準');
    });

    it('shows the shared API error message', async () => {
        vi.spyOn(window, 'fetch').mockRejectedValue(new Error('network'));
        const page = render();
        changeBasis(page, 'treatment_date');
        await flushPromises();

        expect(page.get('[role="alert"]').text()).toBe('月計を読み込めませんでした。時間をおいて再度お試しください。');
    });

    it('shows report navigation as buttons', () => {
        const page = render();
        const customers = page.get('[data-testid="customers-link"]');
        expect(customers.element.tagName).toBe('A');
        expect(customers.classes()).toContain('v-btn');
        expect(customers.attributes('href')).toBe('/admin/reports/customers');
        expect(customers.text()).toBe('顧客統計を見る');
    });

    it('shows a permission-gated download button only for the compatible payment-date basis', async () => {
        const allowed = mount(Monthly, { props: { report: report(), dataEndpoint: '/admin/reports/monthly/data',
            exportEndpoint: '/admin/reports/excel' } });
        const button = allowed.get('[data-testid="excel-export"]');
        expect(button.text()).toBe('月計表をダウンロード');
        expect(button.classes()).toContain('v-btn');
        expect(button.attributes('href')).toContain('year=2026&month=10&basis=payment_date&as_of_date=2026-10-15');
        vi.spyOn(window, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ data: { ...report(), sales_basis: 'treatment_date' } }) } as Response);
        changeBasis(allowed, 'treatment_date');
        await flushPromises();
        expect(allowed.get('[data-testid="excel-export"]').attributes('href')).toBeUndefined();
        expect(allowed.get('[data-testid="excel-export"]').classes()).toContain('v-btn--disabled');
        expect(allowed.text()).toContain('原本形式のExcel出力は決済日基準のみ対応しています。');
        allowed.unmount();
        const denied = render();
        expect(denied.find('[data-testid="excel-export"]').exists()).toBe(false);
    });
});
