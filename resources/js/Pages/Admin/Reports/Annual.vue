<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { DateField, PageHeader, SectionCard, YearField } from '@/components/ark';
import { ReportFilterBar, ReportFilterField, ReportSelect, ReportTable, ReportValue, type ReportValueFormat } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { changeAndReload } from '@/composables/reportNavigation';

defineOptions({ layout: AdminLayout });

/** 事業年度の開始月（4月〜翌3月） */
const FISCAL_YEAR_START_MONTH = 4;

type SalesBasis = 'payment_date' | 'treatment_date';
type Period = 'fiscal' | 'calendar';
type NumericRow = Record<string, unknown>;
interface MonthRow extends NumericRow { year?: number; month: number; month_key: string; is_future: boolean }
interface AnnualReport {
    year: number; as_of_date: string; sales_basis: SalesBasis; period?: Period; period_start?: string; period_end?: string;
    months: MonthRow[]; totals: NumericRow;
}
interface Column { key: string; title: string; type: ReportValueFormat }
interface ColumnGroup { title: string; columns: Column[] }

const props = defineProps<{ report: AnnualReport; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const M = MESSAGES.reportsUi.annual;
const report = ref(props.report);
const year = ref(props.report.year);
const basis = ref<SalesBasis>(props.report.sales_basis);
const period = ref<Period>(props.report.period ?? 'calendar');
const periodItems: { title: string; value: Period }[] = [
    { title: labels.annualFiscal, value: 'fiscal' },
    { title: labels.annualCalendar, value: 'calendar' },
];
/** 事業年度は4月〜翌3月（2026年度＝2026年4月〜2027年3月）。 */
const periodTitle = computed(() => fillMessage(report.value.period === 'fiscal' ? M.fiscalYear : M.calendarYear, { year: String(report.value.year) }));
const asOfDate = ref(props.report.as_of_date);
const loading = ref(false);
const error = ref<string | null>(null);

const basisItems: { title: string; value: SalesBasis }[] = [
    { title: labels.annualPaymentBasis, value: 'payment_date' },
    { title: labels.annualTreatmentBasis, value: 'treatment_date' },
];
// 列の並び・算出は従来どおり。見やすさのため業務上のまとまりで見出しを付ける。
const groups: ColumnGroup[] = [
    { title: labels.annualGroupSales, columns: [
        { key: 'payment_date_revenue', title: labels.annualPaymentRevenue, type: 'money' },
        { key: 'treatment_date_revenue', title: labels.annualTreatmentRevenue, type: 'money' },
        { key: 'selected_revenue', title: labels.annualSelectedRevenue, type: 'money' },
        { key: 'target_amount', title: labels.annualTarget, type: 'money' },
        { key: 'achievement_rate', title: labels.annualAchievement, type: 'percent' },
    ] },
    { title: labels.annualGroupVisits, columns: [
        { key: 'visit_count', title: labels.annualVisits, type: 'count' },
        { key: 'long_visit_count', title: labels.annualLong, type: 'count' },
        { key: 'future_reservation_count', title: labels.annualReservationCount, type: 'count' },
        { key: 'reservation_rate', title: labels.annualReservationRate, type: 'percent' },
    ] },
    { title: labels.annualGroupFirst, columns: [
        { key: 'first_visit_count', title: labels.annualFirst, type: 'count' },
        { key: 'first_visit_reservation_count', title: labels.annualFirstReservation, type: 'count' },
        { key: 'first_visit_reservation_rate', title: labels.annualFirstReservationRate, type: 'percent' },
    ] },
    { title: labels.annualGroupCustomers, columns: [
        { key: 'new_customers', title: labels.annualNew, type: 'count' },
        { key: 'returning_customers', title: labels.annualReturning, type: 'count' },
        { key: 'churn_customers', title: labels.annualChurn, type: 'count' },
    ] },
    { title: labels.annualGroupReach, columns: [
        { key: 'reached_2', title: labels.annualReach2, type: 'count' },
        { key: 'reach_2_rate', title: labels.annualReach2Rate, type: 'percent' },
        { key: 'reached_6', title: labels.annualReach6, type: 'count' },
        { key: 'reach_6_rate', title: labels.annualReach6Rate, type: 'percent' },
        { key: 'reached_10', title: labels.annualReach10, type: 'count' },
        { key: 'reach_10_rate', title: labels.annualReach10Rate, type: 'percent' },
    ] },
    { title: labels.annualGroupUtilization, columns: [
        { key: 'legacy_utilization_rate', title: labels.annualLegacyRate, type: 'percent' },
        { key: 'bookable_utilization_rate', title: labels.annualBookableRate, type: 'percent' },
    ] },
];
const columns = computed(() => groups.flatMap((group) => group.columns.map((column, index) => ({ ...column, groupStart: index === 0 }))));

// 基準日はサーバーの検証範囲（対象年内・未来年は前年末日も可）に合わせて選べる日を絞る。
const asOfMin = computed(() => (period.value === 'fiscal' ? `${year.value}-03-31` : `${year.value - 1}-12-31`));
const asOfMax = computed(() => (period.value === 'fiscal' ? `${year.value + 1}-03-31` : `${year.value}-12-31`));
const numericValue = (row: NumericRow, key: string): number | null => (typeof row[key] === 'number' ? row[key] as number : null);
/** 値がない理由。未来月は「未実績」（目標だけは未来月でも表示する）、目標は「未設定」、それ以外は「算出不可」。 */
const emptyLabel = (key: string, future: boolean): string => {
    if (future && key !== 'target_amount') return labels.annualFuture;
    return key === 'target_amount' ? MESSAGES.common.notSet : MESSAGES.common.notCalculated;
};

async function loadReport(resetAsOf = false): Promise<void> {
    if (resetAsOf) asOfDate.value = '';
    loading.value = true;
    error.value = null;
    try {
        const query = new URLSearchParams({ year: String(year.value), basis: basis.value, period: period.value });
        if (asOfDate.value) query.set('as_of_date', asOfDate.value);
        const response = await fetch(`${props.dataEndpoint}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json() as { data: AnnualReport };
        report.value = payload.data;
        asOfDate.value = payload.data.as_of_date;
    } catch {
        error.value = labels.annualLoadFailed;
    } finally {
        loading.value = false;
    }
}

function changeYear(value: number): void {
    if (value === year.value) return;
    year.value = value;
    void loadReport(true);
}

function changePeriod(value: Period): void {
    if (value === period.value) return;
    period.value = value;
    void loadReport(true);
}

function changeBasis(value: SalesBasis): void {
    changeAndReload(basis, value, loadReport);
}

function changeAsOf(value: string): void {
    if (value === asOfDate.value) return;
    asOfDate.value = value;
    void loadReport();
}
</script>

<template>
    <Head :title="labels.annualTitle" />
    <PageHeader :title="labels.annualTitle" :subtitle="labels.annualSubtitle" />

    <ReportFilterBar :loading="loading" :loading-text="labels.annualLoading" :error="error">
        <ReportFilterField size="md">
            <ReportSelect :model-value="period" :items="periodItems" :label="labels.annualPeriod" data-testid="period-select" @update:model-value="changePeriod" />
        </ReportFilterField>
        <ReportFilterField size="sm">
            <YearField :model-value="year" :label="period === 'fiscal' ? labels.annualFiscalYear : labels.annualYear" data-testid="year-input" @update:model-value="changeYear" />
        </ReportFilterField>
        <ReportFilterField size="md">
            <ReportSelect :model-value="basis" :items="basisItems" :label="labels.annualBasis" data-testid="basis-select" @update:model-value="changeBasis" />
        </ReportFilterField>
        <ReportFilterField size="lg">
            <DateField :model-value="asOfDate" :label="labels.annualAsOf" :min="asOfMin" :max="asOfMax" data-testid="as-of-input" @update:model-value="changeAsOf" />
        </ReportFilterField>
        <template #meta>{{ labels.annualAsOfMeta }}</template>
    </ReportFilterBar>

    <SectionCard :title="`${periodTitle} ${labels.annualTitle}`" :subtitle="basis === 'payment_date' ? labels.annualPaymentBasis : labels.annualTreatmentBasis">
        <ReportTable :loading="loading" min-width="2100px" sticky-width="64px" max-height="none" data-testid="annual-table">
            <thead>
                <tr class="group-row">
                    <th rowspan="2" class="is-sticky">{{ labels.annualMonth }}</th>
                    <th v-for="group in groups" :key="group.title" :colspan="group.columns.length" class="group-start">{{ group.title }}</th>
                </tr>
                <tr>
                    <th v-for="column in columns" :key="column.key" class="num" :class="{ 'group-start': column.groupStart }">{{ column.title }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.months" :key="row.month_key" :class="{ 'row-muted': row.is_future }">
                    <th class="is-sticky"><template v-if="report.period === 'fiscal' && (row.month === 1 || row.month === FISCAL_YEAR_START_MONTH)">{{ row.year }}/</template>{{ fillMessage(M.month, { month: String(row.month) }) }}</th>
                    <td v-for="column in columns" :key="column.key" class="num" :class="{ 'group-start': column.groupStart }">
                        <ReportValue
                            :value="numericValue(row, column.key)"
                            :format="column.type"
                            :hidden="row.is_future && column.key !== 'target_amount'"
                            :empty-label="emptyLabel(column.key, row.is_future)"
                        />
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th class="is-sticky">{{ labels.annualTotal }}</th>
                    <td v-for="column in columns" :key="column.key" class="num" :class="{ 'group-start': column.groupStart }">
                        <ReportValue :value="numericValue(report.totals, column.key)" :format="column.type" :empty-label="emptyLabel(column.key, false)" />
                    </td>
                </tr>
            </tfoot>
        </ReportTable>
    </SectionCard>
</template>
