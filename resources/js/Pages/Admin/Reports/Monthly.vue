<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EmptyValue, MonthField, PageHeader, SectionCard } from '@/components/ark';
import { ReportFilterBar, ReportFilterField, ReportKpi, ReportSelect, ReportTable, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type SalesBasis = 'payment_date' | 'treatment_date';
interface Ratio { numerator: number; denominator: number; value: number | null }
interface PaymentTotal { payment_method_id: number; code: string; name: string; amount: number }
interface PaymentColumn { payment_method_id: number; code: string; name: string }
interface PaymentCategoryTotal extends PaymentTotal { treatment_amount: number; retail_amount: number; unallocated_amount: number }
interface SalesAmounts { net: number; tax: number; gross: number }
interface SalesSplit { treatment: SalesAmounts; retail: SalesAmounts }
interface TaxTotal { tax_category_code: string | null; tax_category_name: string | null; tax_rate_bps: number | null; net_amount: number; tax_amount: number; gross_amount: number; line_count: number }
interface DailyRow {
    business_date: string; day: number; weekday: string; weekday_iso: number; is_closed: boolean; is_future: boolean;
    selected_revenue: number; payment_date_revenue: number; treatment_date_revenue: number;
    payment_method_totals: PaymentTotal[]; tax_totals: TaxTotal[];
    payment_category_totals: PaymentCategoryTotal[]; sales_split: SalesSplit; net_sales: number; sales_tax: number; gross_sales: number;
    visit_count: number; long_visit_count: number; future_reservation_count: number; future_reservation_unknown_count: number;
    future_reservation_rate: Ratio; first_visit_count: number; first_visit_reservation_count: number;
    first_visit_reservation_unknown_count: number; first_visit_reservation_rate: Ratio;
    analysis_category_visit_counts: Record<string, number>; unknown_analysis_category_visit_count: number;
}
interface MonthlyReport {
    year: number; month: number; month_key: string; as_of_date: string; sales_basis: SalesBasis;
    daily_rows: DailyRow[]; payment_methods: PaymentTotal[]; tax_buckets: TaxTotal[];
    totals: Record<string, number | PaymentTotal[] | TaxTotal[] | PaymentCategoryTotal[] | SalesSplit | Record<string, number>>;
    ratios: { future_reservation_rate: Ratio; first_visit_reservation_rate: Ratio };
    target: number | null;
    progress: { target_amount: number | null; actual_amount: number; difference_amount: number | null; remaining_required_amount: number | null; achievement_rate: number | null; required_daily_average: number | null };
    business_days: { calendar_days: number; total: number; elapsed: number; input_days: number; remaining: number; closed: number; elapsed_weekdays: number; elapsed_weekends: number };
    averages: { daily_sales: number | null; daily_visits: number | null; weekday_sales: number | null; weekend_sales: number | null; weekday_visits: number | null; weekend_visits: number | null };
    periods: { first: { selected_revenue: number; visit_count: number }; second: { selected_revenue: number; visit_count: number } };
}

const props = withDefaults(defineProps<{
    report: MonthlyReport;
    dataEndpoint: string;
    exportEndpoint?: string | null;
    /** 原本「月計表」と同じ並びの決済列（マスタ表示順）。 */
    paymentMethodColumns?: PaymentColumn[];
}>(), {
    exportEndpoint: null,
    paymentMethodColumns: () => [],
});
const labels = MESSAGES.reporting;
const report = ref(props.report);
const selectedMonth = ref(props.report.month_key);
const selectedBasis = ref<SalesBasis>(props.report.sales_basis);
const loading = ref(false);
const error = ref<string | null>(null);

const basisItems: { title: string; value: SalesBasis }[] = [
    { title: labels.annualPaymentBasis, value: 'payment_date' },
    { title: labels.annualTreatmentBasis, value: 'treatment_date' },
];
const basisLabel = computed(() => (report.value.sales_basis === 'payment_date' ? labels.annualPaymentBasis : labels.annualTreatmentBasis));
const methodHeading = (method: { code: string | null; name: string }): string => (method.code ? labels.paymentMethodHeadings[method.code] : undefined) ?? method.name;
const categories = ['M', 'T', 'A', 'M&T', 'A&T'] as const;

// 決済列はマスタの表示順（原本と同じ 現金→PayPay→…→ID）で固定し、マスタ外（無効化済み等）で実績がある手段は後ろに足す。
const paymentColumns = computed<PaymentColumn[]>(() => {
    const columns = [...props.paymentMethodColumns];
    for (const method of report.value.payment_methods) {
        if (!columns.some((column) => column.payment_method_id === method.payment_method_id)) {
            columns.push({ payment_method_id: method.payment_method_id, code: method.code, name: method.name });
        }
    }
    return columns;
});

/** 旧月計表K〜Nの物販決済列（現金・PayPay・エアペイ・ID）に、実績のあるその他の手段を後ろに足す。 */
const RETAIL_CODES = ['cash', 'paypay', 'airpay', 'id'];
const monthPaymentCategories = computed(() => (report.value.totals.payment_category_totals as PaymentCategoryTotal[] | undefined) ?? []);
const retailColumns = computed<PaymentColumn[]>(() => {
    const columns = paymentColumns.value.filter((column) => RETAIL_CODES.includes(column.code));
    for (const method of monthPaymentCategories.value) {
        if (method.retail_amount > 0 && !columns.some((column) => column.payment_method_id === method.payment_method_id)) {
            columns.push({ payment_method_id: method.payment_method_id, code: method.code, name: method.name });
        }
    }
    return columns;
});
const hasUnallocated = computed(() => monthPaymentCategories.value.some((method) => method.unallocated_amount > 0));
const categoryAmount = (row: DailyRow, id: number, key: 'treatment_amount' | 'retail_amount'): number =>
    (row.payment_category_totals ?? []).find((item) => item.payment_method_id === id)?.[key] ?? 0;
const unallocated = (row: DailyRow): number => (row.payment_category_totals ?? []).reduce((sum, item) => sum + item.unallocated_amount, 0);
const categoryTotal = (id: number, key: 'treatment_amount' | 'retail_amount'): number =>
    monthPaymentCategories.value.find((item) => item.payment_method_id === id)?.[key] ?? 0;
const monthSplit = computed(() => report.value.totals.sales_split as SalesSplit | undefined);

const hasFacts = (row: DailyRow): boolean => row.visit_count > 0 || row.payment_date_revenue > 0 || row.treatment_date_revenue > 0;
/** 未来日で実績がない日は、0 ではなく「未実績」として「-」にする。 */
const isPending = (row: DailyRow): boolean => row.is_future && !hasFacts(row);
// 原本の月計表に税区分名の欄はないため、画面にも税区分名（マスタの内部名）は出さず税率ごとにまとめる。
interface TaxRateTotal { rate_bps: number | null; net_amount: number; tax_amount: number }
const taxRates = computed<TaxRateTotal[]>(() => {
    const byRate = new Map<number | null, TaxRateTotal>();
    for (const tax of props.report.tax_buckets) {
        const current = byRate.get(tax.tax_rate_bps) ?? { rate_bps: tax.tax_rate_bps, net_amount: 0, tax_amount: 0 };
        current.net_amount += tax.net_amount;
        current.tax_amount += tax.tax_amount;
        byRate.set(tax.tax_rate_bps, current);
    }
    return [...byRate.values()].sort((a, b) => (b.rate_bps ?? -1) - (a.rate_bps ?? -1));
});
const taxRateLabel = (tax: TaxRateTotal): string => tax.rate_bps === null
    ? labels.monthlyTaxRateUnknown
    : labels.monthlyTaxRateTarget.replace('{rate}', String(tax.rate_bps / 100));
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
        error.value = labels.monthlyLoadFailed;
    } finally {
        loading.value = false;
    }
}

function changeMonth(value: string): void {
    if (value === selectedMonth.value) return;
    selectedMonth.value = value;
    void loadReport();
}

function changeBasis(value: SalesBasis): void {
    if (value === selectedBasis.value) return;
    selectedBasis.value = value;
    void loadReport();
}
</script>

<template>
    <Head title="月計" />
    <PageHeader title="月計" subtitle="日次と同じ事実データから、月の日別実績・目標進捗を集計します。">
        <template #actions>
            <v-btn href="/admin/reports/customers" variant="outlined" color="primary" prepend-icon="mdi-account-group-outline" data-testid="customers-link">
                {{ labels.customerOpen }}
            </v-btn>
            <template v-if="exportEndpoint">
                <v-btn
                    :href="exportUrl ?? undefined"
                    :disabled="exportUrl === null"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-microsoft-excel"
                    :title="labels.excelExportHint"
                    data-testid="excel-export"
                >
                    {{ labels.excelExport }}
                </v-btn>
            </template>
        </template>
    </PageHeader>
    <p v-if="exportEndpoint && selectedBasis === 'treatment_date'" class="report-hint" role="note">
        <v-icon icon="mdi-information-outline" size="16" />{{ labels.excelPaymentDateOnly }}
    </p>

    <ReportFilterBar :loading="loading" :loading-text="labels.monthlyLoading" :error="error">
        <ReportFilterField size="md">
            <MonthField :model-value="selectedMonth" :label="MESSAGES.calendar.targetMonth" density="compact" data-testid="month-input" @update:model-value="changeMonth" />
        </ReportFilterField>
        <ReportFilterField size="md">
            <ReportSelect :model-value="selectedBasis" :items="basisItems" :label="labels.annualBasis" data-testid="basis-select" @update:model-value="changeBasis" />
        </ReportFilterField>
        <template #meta>{{ labels.annualAsOf }} {{ report.as_of_date }}</template>
    </ReportFilterBar>

    <div class="report-kpi-grid monthly-kpis" :aria-busy="loading">
        <ReportKpi label="月間目標">
            <ReportValue :value="report.progress.target_amount" format="money" :empty-label="MESSAGES.common.notSet" />
        </ReportKpi>
        <ReportKpi label="現在実績" emphasis>
            <ReportValue :value="report.progress.actual_amount" format="money" />
            <template #caption>{{ basisLabel }}</template>
        </ReportKpi>
        <ReportKpi :label="labels.monthlyNetSales">
            <ReportValue :value="totalNumber('net_sales') ?? null" format="money" />
            <template #caption>{{ labels.monthlyGrossSales }} <ReportValue :value="totalNumber('gross_sales') ?? null" format="money" /> / {{ labels.monthlySalesTax }} <ReportValue :value="totalNumber('sales_tax') ?? null" format="money" /></template>
        </ReportKpi>
        <ReportKpi label="達成率">
            <ReportValue :value="report.progress.achievement_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" />
        </ReportKpi>
        <ReportKpi label="残必要売上">
            <ReportValue :value="report.progress.remaining_required_amount" format="money" :empty-label="MESSAGES.common.notCalculated" />
            <template #caption>差額 <ReportValue :value="report.progress.difference_amount" format="money" :empty-label="MESSAGES.common.notCalculated" /></template>
        </ReportKpi>
        <ReportKpi label="経過 / 残営業日">
            {{ report.business_days.elapsed }} / {{ report.business_days.remaining }}<small>日</small>
            <template #caption>臨時休業 {{ report.business_days.closed }}日</template>
        </ReportKpi>
        <ReportKpi label="残営業日平均">
            <ReportValue :value="report.progress.required_daily_average" format="money" :empty-label="MESSAGES.common.notCalculated" />
        </ReportKpi>
        <ReportKpi label="平日平均">
            <ReportValue :value="report.averages.weekday_visits" format="decimal" :empty-label="MESSAGES.common.notCalculated" /><small v-if="report.averages.weekday_visits !== null">来店</small>
            <template #caption><ReportValue :value="report.averages.weekday_sales" format="money" :empty-label="MESSAGES.common.notCalculated" /></template>
        </ReportKpi>
        <ReportKpi label="土日平均">
            <ReportValue :value="report.averages.weekend_visits" format="decimal" :empty-label="MESSAGES.common.notCalculated" /><small v-if="report.averages.weekend_visits !== null">来店</small>
            <template #caption><ReportValue :value="report.averages.weekend_sales" format="money" :empty-label="MESSAGES.common.notCalculated" /></template>
        </ReportKpi>
    </div>

    <SectionCard title="日別実績" :subtitle="labels.monthlyDailySubtitle">
        <ReportTable :loading="loading" max-height="none" min-width="1800px" sticky-width="56px" data-testid="monthly-daily-table">
            <thead>
                <tr class="group-row">
                    <th rowspan="2" class="is-sticky">日</th>
                    <th rowspan="2">曜</th>
                    <th :colspan="paymentColumns.length + 1" class="group-start">{{ labels.monthlyTreatmentPayments }}</th>
                    <th :colspan="retailColumns.length + 1" class="group-start">{{ labels.monthlyRetailPayments }}</th>
                    <th v-if="hasUnallocated" rowspan="2" class="num group-start">{{ labels.monthlyUnallocated }}</th>
                    <th colspan="4" class="group-start">{{ labels.monthlySales }}</th>
                    <th colspan="4" class="group-start">来店</th>
                    <th colspan="3" class="group-start">初診</th>
                    <th :colspan="categories.length + 1" class="group-start">施術分類</th>
                </tr>
                <tr>
                    <th v-for="(method, index) in paymentColumns" :key="method.payment_method_id" class="num" :class="{ 'group-start': index === 0 }">{{ methodHeading(method) }}</th>
                    <th class="num">{{ labels.monthlyNetSubtotal }}</th>
                    <th v-for="(method, index) in retailColumns" :key="`r-${method.payment_method_id}`" class="num" :class="{ 'group-start': index === 0 }">{{ methodHeading(method) }}</th>
                    <th class="num" :class="{ 'group-start': retailColumns.length === 0 }">{{ labels.monthlyNetSubtotal }}</th>
                    <th class="num group-start">{{ labels.monthlyNetSales }}</th><th class="num">{{ labels.monthlySalesTax }}</th><th class="num">{{ labels.monthlyGrossSales }}</th>
                    <th class="num">{{ labels.monthlySelectedRevenue }}<br><small>{{ basisLabel }}</small></th>
                    <th class="num group-start">来店数</th><th class="num">ロング</th><th class="num">予約</th><th class="num">予約率</th>
                    <th class="num group-start">初診数</th><th class="num">初診予約</th><th class="num">初診予約率</th>
                    <th v-for="(category, index) in categories" :key="category" class="num" :class="{ 'group-start': index === 0 }">{{ category }}</th>
                    <th class="num">分類不明</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.daily_rows" :key="row.business_date" :class="{ 'row-muted': row.is_future, 'row-alert': row.is_closed }">
                    <th class="is-sticky">{{ row.day }}日</th>
                    <td>{{ row.weekday }}<span v-if="row.is_closed" class="cell-tag">休</span></td>
                    <td v-for="(method, index) in paymentColumns" :key="method.payment_method_id" class="num" :class="{ 'group-start': index === 0 }">
                        <ReportValue :value="categoryAmount(row, method.payment_method_id, 'treatment_amount')" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" />
                    </td>
                    <td class="num"><ReportValue :value="row.sales_split?.treatment.net ?? null" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td v-for="(method, index) in retailColumns" :key="`r-${method.payment_method_id}`" class="num" :class="{ 'group-start': index === 0 }">
                        <ReportValue :value="categoryAmount(row, method.payment_method_id, 'retail_amount')" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" />
                    </td>
                    <td class="num" :class="{ 'group-start': retailColumns.length === 0 }"><ReportValue :value="row.sales_split?.retail.net ?? null" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td v-if="hasUnallocated" class="num group-start"><ReportValue :value="unallocated(row)" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num group-start"><ReportValue :value="row.net_sales ?? null" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.sales_tax ?? null" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.gross_sales ?? null" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.selected_revenue" format="money" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num group-start"><ReportValue :value="row.visit_count" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.long_visit_count" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.future_reservation_count" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.future_reservation_rate.value" format="percent" :hidden="isPending(row)" :empty-label="isPending(row) ? labels.futureDay : MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.first_visit_count" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.first_visit_reservation_count" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                    <td class="num"><ReportValue :value="row.first_visit_reservation_rate.value" format="percent" :hidden="isPending(row)" :empty-label="isPending(row) ? labels.futureDay : MESSAGES.common.notCalculated" /></td>
                    <td v-for="(category, index) in categories" :key="category" class="num" :class="{ 'group-start': index === 0 }">
                        <ReportValue :value="row.analysis_category_visit_counts[category] ?? 0" :hidden="isPending(row)" :empty-label="labels.futureDay" />
                    </td>
                    <td class="num"><ReportValue :value="row.unknown_analysis_category_visit_count" :hidden="isPending(row)" :empty-label="labels.futureDay" /></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th class="is-sticky">合計</th>
                    <td><EmptyValue /></td>
                    <td v-for="(method, index) in paymentColumns" :key="method.payment_method_id" class="num" :class="{ 'group-start': index === 0 }">
                        <ReportValue :value="categoryTotal(method.payment_method_id, 'treatment_amount')" format="money" />
                    </td>
                    <td class="num"><ReportValue :value="monthSplit?.treatment.net ?? null" format="money" /></td>
                    <td v-for="(method, index) in retailColumns" :key="`r-${method.payment_method_id}`" class="num" :class="{ 'group-start': index === 0 }">
                        <ReportValue :value="categoryTotal(method.payment_method_id, 'retail_amount')" format="money" />
                    </td>
                    <td class="num" :class="{ 'group-start': retailColumns.length === 0 }"><ReportValue :value="monthSplit?.retail.net ?? null" format="money" /></td>
                    <td v-if="hasUnallocated" class="num group-start"><ReportValue :value="monthPaymentCategories.reduce((sum, item) => sum + item.unallocated_amount, 0)" format="money" /></td>
                    <td class="num group-start"><ReportValue :value="totalNumber('net_sales') ?? null" format="money" /></td>
                    <td class="num"><ReportValue :value="totalNumber('sales_tax') ?? null" format="money" /></td>
                    <td class="num"><ReportValue :value="totalNumber('gross_sales') ?? null" format="money" /></td>
                    <td class="num"><ReportValue :value="totalNumber('selected_revenue')" format="money" /></td>
                    <td class="num group-start"><ReportValue :value="totalNumber('visit_count')" /></td>
                    <td class="num"><ReportValue :value="totalNumber('long_visit_count')" /></td>
                    <td class="num"><ReportValue :value="totalNumber('future_reservation_count')" /></td>
                    <td class="num"><ReportValue :value="report.ratios.future_reservation_rate.value" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="totalNumber('first_visit_count')" /></td>
                    <td class="num"><ReportValue :value="totalNumber('first_visit_reservation_count')" /></td>
                    <td class="num"><ReportValue :value="report.ratios.first_visit_reservation_rate.value" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td v-for="(category, index) in categories" :key="category" class="num" :class="{ 'group-start': index === 0 }">
                        <ReportValue :value="categoryTotals[category] ?? 0" />
                    </td>
                    <td class="num"><ReportValue :value="totalNumber('unknown_analysis_category_visit_count')" /></td>
                </tr>
            </tfoot>
        </ReportTable>
        <div class="period-summary">
            <span class="period-summary__item" data-testid="period-first">{{ labels.monthlyFirstHalf }} <strong><ReportValue :value="report.periods.first.selected_revenue" format="money" /></strong> / {{ report.periods.first.visit_count }}{{ labels.monthlyVisitUnit }}</span>
            <span class="period-summary__item" data-testid="period-second">{{ labels.monthlySecondHalf }} <strong><ReportValue :value="report.periods.second.selected_revenue" format="money" /></strong> / {{ report.periods.second.visit_count }}{{ labels.monthlyVisitUnit }}</span>
            <span v-for="tax in taxRates" :key="String(tax.rate_bps)" class="period-summary__item" data-testid="tax-rate-summary">{{ taxRateLabel(tax) }} {{ labels.monthlyNetSales }} <strong><ReportValue :value="tax.net_amount" format="money" /></strong> / {{ labels.monthlySalesTax }} <ReportValue :value="tax.tax_amount" format="money" /></span>
        </div>
    </SectionCard>
</template>

<style scoped>
/* KPI 8枚は折り返しで端数が出ないよう 8列 → 4列 → 2列 で並べる。 */
.monthly-kpis { grid-template-columns: repeat(9, minmax(0, 1fr)); }
@media (max-width: 1599px) { .monthly-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
@media (max-width: 1199px) { .monthly-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 599px) { .monthly-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

.report-hint {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: calc(-1 * var(--ark-space-3)) 0 var(--ark-space-3);
    color: rgba(var(--v-theme-on-surface), 0.65);
    font-size: 0.8125rem;
    justify-content: flex-end;
}

.period-summary {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ark-space-2);
    margin-top: var(--ark-space-3);
}

.period-summary__item {
    padding: 4px 12px;
    border-radius: 999px;
    background: #f3f5f9;
    color: rgba(var(--v-theme-on-surface), 0.75);
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
}

.period-summary__item strong {
    margin-inline: 4px;
    color: rgb(var(--v-theme-on-surface));
}
</style>
