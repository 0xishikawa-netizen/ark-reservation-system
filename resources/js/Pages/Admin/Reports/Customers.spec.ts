import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Customers from './Customers.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));

const dimensions = [
    'course', 'visit_purpose', 'gender', 'age_at_first_visit', 'age_decade',
    'motivation', 'referrer', 'prefecture', 'municipality', 'first_staff',
    'future_reservation', 'reached_2', 'reached_6', 'reached_10',
] as const;

function report() {
    const breakdowns = Object.fromEntries(dimensions.map((key) => [key, {
        status: (['visit_purpose', 'motivation', 'referrer', 'prefecture', 'municipality'].includes(key) ? 'not_captured' : 'available') as 'not_captured' | 'available',
        basis: null, buckets: key === 'gender' ? [{ value: 'male', label: 'male', count: 1 }, { value: null, label: null, count: 1 }] : [],
    }])) as Record<typeof dimensions[number], { status: 'not_captured' | 'available'; basis: null; buckets: { value: string | null; label: string | null; count: number }[] }>;
    return {
        year: 2026, month: 10, cohort_month: '2026-10', as_of_date: '2026-10-15',
        new_customers: 2, returning_customers: 0, churn_customers: null,
        reach: { '2': { numerator: 1, denominator: 2, rate: 0.5 }, '6': { numerator: 0, denominator: 2, rate: 0 }, '10': { numerator: 0, denominator: 2, rate: null } },
        breakdowns,
    };
}

let wrapper: VueWrapper | null = null;
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.restoreAllMocks(); });

function render() {
    wrapper = mount(Customers, { props: { report: report(), dataEndpoint: '/admin/reports/customers/data', monthlyReportUrl: '/admin/reports/monthly' } });
    return wrapper;
}

describe('Customer report page', () => {
    it('shows KPIs, zero, NULL and unknown separately', () => {
        const page = render();
        expect(page.get('[data-testid="new-count"]').text()).toBe('2');
        expect(page.get('[data-testid="returning-count"]').text()).toBe('0');
        expect(page.get('[data-testid="churn-count"]').text()).toBe('—');
        expect(page.get('[data-testid="reach-2"]').text()).toBe('50.0%');
        expect(page.get('[data-testid="reach-6"]').text()).toBe('0.0%');
        expect(page.get('[data-testid="reach-10"]').text()).toBe('—');
        expect(page.get('[data-testid="breakdown-gender"]').text()).toContain('未入力・不明');
        expect(page.get('[data-testid="breakdown-prefecture"]').text()).toContain('現行DBで未取得');
    });

    it('reloads on month and as-of changes, displays loading and updated values', async () => {
        let resolveFetch!: (response: Response) => void;
        const fetchMock = vi.spyOn(window, 'fetch').mockImplementation(() => new Promise((resolve) => { resolveFetch = resolve; }));
        const page = render();
        await page.get('[data-testid="month-input"]').setValue('2026-11');
        expect(page.get('[role="status"]').text()).toContain('読み込んでいます');
        expect(fetchMock.mock.calls[0][0]).toContain('year=2026&month=11&as_of_date=2026-10-15');
        resolveFetch({ ok: true, json: async () => ({ data: { ...report(), new_customers: 0 } }) } as Response);
        await flushPromises();
        expect(page.get('[data-testid="new-count"]').text()).toBe('0');

        await page.get('[data-testid="as-of-input"]').setValue('2026-12-01');
        expect(fetchMock.mock.calls[1][0]).toContain('as_of_date=2026-12-01');
        resolveFetch({ ok: true, json: async () => ({ data: report() }) } as Response);
        await flushPromises();
    });

    it('shows a shared API error message', async () => {
        vi.spyOn(window, 'fetch').mockRejectedValue(new Error('network'));
        const page = render();
        await page.get('[data-testid="as-of-input"]').setValue('2026-10-20');
        await flushPromises();
        expect(page.get('[role="alert"]').text()).toContain('顧客統計を読み込めませんでした');
    });
});
