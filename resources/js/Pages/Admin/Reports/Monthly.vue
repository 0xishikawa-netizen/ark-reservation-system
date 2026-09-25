<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Ratio { numerator: number; denominator: number; value: number | null }
interface PaymentTotal { payment_method_id: number; code: string; name: string; amount: number }
interface TaxTotal { tax_category_code: string | null; tax_category_name: string | null; tax_rate_bps: number | null; net_amount: number; tax_amount: number; gross_amount: number; line_count: number }
interface DailyRow {
    business_date: string; day: number; weekday: string; weekday_iso: number; is_closed: boolean; is_future: boolean;
    selected_revenue: number; payment_date_revenue: number; treatment_date_revenue: number;
    payment_method_totals: PaymentTotal[]; tax_totals: TaxTotal[];
    visit_count: number; long_visit_count: number; future_reservation_count: number; future_reservation_unknown_count: number;
    future_reservation_rate: Ratio; first_visit_count: number; first_visit_reservation_count: number;
    first_visit_reservation_unknown_count: number; first_visit_reservation_rate: Ratio;
    analysis_category_visit_counts: Record<string, number>; unknown_analysis_category_visit_count: number;
}
interface MonthlyReport {
    year: number; month: number; month_key: string; as_of_date: string; sales_basis: 'payment_date' | 'treatment_date';
    daily_rows: DailyRow[]; payment_methods: PaymentTotal[]; tax_buckets: TaxTotal[];
    totals: Record<string, number | PaymentTotal[] | TaxTotal[] | Record<string, number>>;
    ratios: { future_reservation_rate: Ratio; first_visit_reservation_rate: Ratio };
    target: number | null;
    progress: { target_amount: number | null; actual_amount: number; difference_amount: number | null; remaining_required_amount: number | null; achievement_rate: number | null; required_daily_average: number | null };
    business_days: { calendar_days: number; total: number; elapsed: number; input_days: number; remaining: number; closed: number; elapsed_weekdays: number; elapsed_weekends: number };
    averages: { daily_sales: number | null; daily_visits: number | null; weekday_sales: number | null; weekend_sales: number | null; weekday_visits: number | null; weekend_visits: number | null };
    periods: { first: { selected_revenue: number; visit_count: number }; second: { selected_revenue: number; visit_count: number } };
}

const props = defineProps<{ report: MonthlyReport; dataEndpoint: string; exportEndpoint?: string | null }>();
const report = ref(props.report);
const selectedMonth = ref(props.report.month_key);
const selectedBasis = ref<'payment_date' | 'treatment_date'>(props.report.sales_basis);
const loading = ref(false);
const error = ref<string | null>(null);

const categories = ['M', 'T', 'A', 'M&T', 'A&T'] as const;
const money = (value: number | null): string => value === null ? '—' : `${new Intl.NumberFormat('ja-JP').format(Math.round(value))}円`;
const number = (value: number | null): string => value === null ? '—' : new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 1 }).format(value);
const percent = (value: number | null): string => value === null ? '—' : `${(value * 100).toFixed(1)}%`;
const hasFacts = (row: DailyRow): boolean => row.visit_count > 0 || row.payment_date_revenue > 0 || row.treatment_date_revenue > 0;
const valueOrFuture = (row: DailyRow, value: number, currency = false): string =>
    row.is_future && !hasFacts(row) ? MESSAGES.reporting.futureDay : currency ? money(value) : number(value);
const paymentAmount = (row: DailyRow, id: number): number => row.payment_method_totals.find((item) => item.payment_method_id === id)?.amount ?? 0;
const taxKey = (tax: TaxTotal): string => `${tax.tax_category_code ?? 'unknown'}|${tax.tax_category_name ?? 'unknown'}|${tax.tax_rate_bps ?? 'unknown'}`;
const taxAmount = (row: DailyRow, bucket: TaxTotal): number => row.tax_totals.find((item) => taxKey(item) === taxKey(bucket))?.tax_amount ?? 0;
const taxLabel = (tax: TaxTotal): string => tax.tax_rate_bps === null
    ? `${tax.tax_category_name ?? '不明'}（税率不明）`
    : `${tax.tax_category_name ?? tax.tax_category_code ?? '税区分不明'} ${tax.tax_rate_bps / 100}%`;
const totalNumber = (key: string): number => report.value.totals[key] as number;
const categoryTotals = computed(() => report.value.totals.analysis_category_visit_counts as Record<string, number>);
const exportUrl = computed(() => {
    if (!props.exportEndpoint || selectedBasis.value !== 'payment_date') return null;
    const query = new URLSearchParams({ year: String(report.value.year), month: String(report.value.month),
        basis: selectedBasis.value, as_of_date: report.value.as_of_date });
    return `${props.exportEndpoint}?${query}`;
});

async function loadReport(): Promise<void> {
    const [year, month] = selectedMonth.value.split('-').map(Number);
    if (!year || !month) return;
    loading.value = true;
    error.value = null;
    try {
        const query = new URLSearchParams({ year: String(year), month: String(month), basis: selectedBasis.value });
        const response = await fetch(`${props.dataEndpoint}?${query.toString()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json() as { data: MonthlyReport };
        report.value = payload.data;
    } catch {
        error.value = MESSAGES.reporting.monthlyLoadFailed;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head title="月計" />
    <PageHeader title="月計" subtitle="日次と同じ事実データから、月の日別実績・目標進捗を集計します。" />
    <p class="report-link"><a href="/admin/reports/customers">{{ MESSAGES.reporting.customerOpen }}</a></p>
    <p v-if="exportUrl" class="report-link"><a :href="exportUrl" data-testid="excel-export">{{ MESSAGES.reporting.excelExport }}</a></p>
    <p v-else-if="exportEndpoint && selectedBasis === 'treatment_date'" class="report-link">{{ MESSAGES.reporting.excelPaymentDateOnly }}</p>

    <SectionCard title="表示条件" class="mb-5">
        <div class="report-controls">
            <label>対象月<input v-model="selectedMonth" data-testid="month-input" type="month" @change="loadReport"></label>
            <label>売上基準<select v-model="selectedBasis" data-testid="basis-select" @change="loadReport">
                <option value="payment_date">決済日基準</option>
                <option value="treatment_date">施術日基準</option>
            </select></label>
            <span class="as-of">基準日 {{ report.as_of_date }}</span>
        </div>
        <p v-if="loading" class="report-state" role="status">{{ MESSAGES.reporting.monthlyLoading }}</p>
        <p v-if="error" class="report-state report-state--error" role="alert">{{ error }}</p>
    </SectionCard>

    <div class="summary-grid mb-5">
        <SectionCard title="月間目標"><strong>{{ money(report.progress.target_amount) }}</strong></SectionCard>
        <SectionCard title="現在実績"><strong>{{ money(report.progress.actual_amount) }}</strong><small>{{ report.sales_basis === 'payment_date' ? '決済日基準' : '施術日基準' }}</small></SectionCard>
        <SectionCard title="達成率"><strong>{{ percent(report.progress.achievement_rate) }}</strong></SectionCard>
        <SectionCard title="残必要売上"><strong>{{ money(report.progress.remaining_required_amount) }}</strong><small>差額 {{ money(report.progress.difference_amount) }}</small></SectionCard>
        <SectionCard title="経過 / 残営業日"><strong>{{ report.business_days.elapsed }} / {{ report.business_days.remaining }}日</strong><small>臨時休業 {{ report.business_days.closed }}日</small></SectionCard>
        <SectionCard title="残営業日平均"><strong>{{ money(report.progress.required_daily_average) }}</strong></SectionCard>
        <SectionCard title="平日平均"><strong>{{ number(report.averages.weekday_visits) }}来店</strong><small>{{ money(report.averages.weekday_sales) }}</small></SectionCard>
        <SectionCard title="土日平均"><strong>{{ number(report.averages.weekend_visits) }}来店</strong><small>{{ money(report.averages.weekend_sales) }}</small></SectionCard>
    </div>

    <SectionCard title="日別実績">
        <div class="monthly-table-wrap" :aria-busy="loading">
            <table class="monthly-table">
                <thead><tr>
                    <th class="sticky-date">日</th><th>曜日</th><th>選択売上</th><th>決済日売上</th><th>施術日売上</th>
                    <th v-for="method in report.payment_methods" :key="method.payment_method_id">{{ method.name }}</th>
                    <th v-for="tax in report.tax_buckets" :key="taxKey(tax)">{{ taxLabel(tax) }} 税額</th>
                    <th>来店</th><th>ロング</th><th>予約人数</th><th>予約率</th><th>初診</th><th>初診予約</th><th>初診予約率</th>
                    <th v-for="category in categories" :key="category">{{ category }}</th><th>分類不明</th>
                </tr></thead>
                <tbody>
                    <tr v-for="row in report.daily_rows" :key="row.business_date" :class="{ 'future-row': row.is_future, 'closed-row': row.is_closed }">
                        <th class="sticky-date">{{ row.day }}日</th><td>{{ row.weekday }}<span v-if="row.is_closed" class="closed-label">休</span></td>
                        <td>{{ valueOrFuture(row, row.selected_revenue, true) }}</td><td>{{ valueOrFuture(row, row.payment_date_revenue, true) }}</td><td>{{ valueOrFuture(row, row.treatment_date_revenue, true) }}</td>
                        <td v-for="method in report.payment_methods" :key="method.payment_method_id">{{ valueOrFuture(row, paymentAmount(row, method.payment_method_id), true) }}</td>
                        <td v-for="tax in report.tax_buckets" :key="taxKey(tax)">{{ valueOrFuture(row, taxAmount(row, tax), true) }}</td>
                        <td>{{ valueOrFuture(row, row.visit_count) }}</td><td>{{ valueOrFuture(row, row.long_visit_count) }}</td><td>{{ valueOrFuture(row, row.future_reservation_count) }}</td>
                        <td>{{ row.is_future && !hasFacts(row) ? MESSAGES.reporting.futureDay : percent(row.future_reservation_rate.value) }}</td>
                        <td>{{ valueOrFuture(row, row.first_visit_count) }}</td><td>{{ valueOrFuture(row, row.first_visit_reservation_count) }}</td>
                        <td>{{ row.is_future && !hasFacts(row) ? MESSAGES.reporting.futureDay : percent(row.first_visit_reservation_rate.value) }}</td>
                        <td v-for="category in categories" :key="category">{{ valueOrFuture(row, row.analysis_category_visit_counts[category] ?? 0) }}</td>
                        <td>{{ valueOrFuture(row, row.unknown_analysis_category_visit_count) }}</td>
                    </tr>
                </tbody>
                <tfoot><tr>
                    <th class="sticky-date">合計</th><td>—</td><td>{{ money(totalNumber('selected_revenue')) }}</td><td>{{ money(totalNumber('payment_date_revenue')) }}</td><td>{{ money(totalNumber('treatment_date_revenue')) }}</td>
                    <td v-for="method in report.payment_methods" :key="method.payment_method_id">{{ money(method.amount) }}</td>
                    <td v-for="tax in report.tax_buckets" :key="taxKey(tax)">{{ money(tax.tax_amount) }}</td>
                    <td>{{ totalNumber('visit_count') }}</td><td>{{ totalNumber('long_visit_count') }}</td><td>{{ totalNumber('future_reservation_count') }}</td><td>{{ percent(report.ratios.future_reservation_rate.value) }}</td>
                    <td>{{ totalNumber('first_visit_count') }}</td><td>{{ totalNumber('first_visit_reservation_count') }}</td><td>{{ percent(report.ratios.first_visit_reservation_rate.value) }}</td>
                    <td v-for="category in categories" :key="category">{{ categoryTotals[category] ?? 0 }}</td><td>{{ totalNumber('unknown_analysis_category_visit_count') }}</td>
                </tr></tfoot>
            </table>
        </div>
        <div class="period-summary">
            <span>1〜15日: {{ money(report.periods.first.selected_revenue) }} / {{ report.periods.first.visit_count }}来店</span>
            <span>16日〜月末: {{ money(report.periods.second.selected_revenue) }} / {{ report.periods.second.visit_count }}来店</span>
        </div>
    </SectionCard>
</template>

<style scoped>
.report-link { margin: 0 0 16px; }
.report-controls { display: flex; align-items: end; flex-wrap: wrap; gap: 16px; }
.report-controls label { display: grid; gap: 6px; color: #495365; font-size: 13px; font-weight: 700; }
.report-controls input, .report-controls select { min-width: 180px; border: 1px solid #cbd2dc; border-radius: 8px; background: white; padding: 9px 12px; font: inherit; }
.as-of { margin-left: auto; color: #667085; }
.report-state { margin: 12px 0 0; color: #526275; }.report-state--error { color: #b42318; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.summary-grid strong { display: block; color: #15233d; font-size: 23px; }.summary-grid small { display: block; margin-top: 4px; color: #667085; }
.monthly-table-wrap { overflow: auto; max-height: 68vh; border: 1px solid #dfe3e8; border-radius: 10px; }
.monthly-table { border-collapse: separate; border-spacing: 0; min-width: 2200px; width: 100%; font-size: 12px; white-space: nowrap; }
.monthly-table th, .monthly-table td { border-right: 1px solid #e6e9ee; border-bottom: 1px solid #e6e9ee; padding: 8px 10px; text-align: right; background: white; }
.monthly-table thead th { position: sticky; top: 0; z-index: 3; background: #edf4f8; color: #253247; }
.monthly-table .sticky-date { position: sticky; left: 0; z-index: 2; min-width: 58px; text-align: left; background: #f8fafc; }
.monthly-table thead .sticky-date { z-index: 4; background: #e5eef4; }.monthly-table tfoot .sticky-date { z-index: 2; }
.monthly-table tfoot th, .monthly-table tfoot td { background: #eaf3f7; font-weight: 800; }
.future-row td, .future-row .sticky-date { color: #8a94a6; background: #fafbfc; }.closed-row td, .closed-row .sticky-date { background: #fff6f2; }
.closed-label { display: inline-block; margin-left: 4px; border-radius: 4px; background: #d92d20; color: white; padding: 1px 4px; font-size: 10px; }
.period-summary { display: flex; gap: 24px; margin-top: 14px; color: #465469; font-weight: 700; }
@media (max-width: 1100px) { .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
