<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type SalesBasis = 'payment_date' | 'treatment_date';
type ColumnType = 'money' | 'count' | 'rate';
type NumericRow = Record<string, unknown>;
interface MonthRow extends NumericRow { month: number; month_key: string; is_future: boolean }
interface AnnualReport {
    year: number; as_of_date: string; sales_basis: SalesBasis;
    months: MonthRow[]; totals: NumericRow;
}

const props = defineProps<{ report: AnnualReport; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const report = ref(props.report);
const year = ref(props.report.year);
const basis = ref<SalesBasis>(props.report.sales_basis);
const asOfDate = ref(props.report.as_of_date);
const loading = ref(false);
const error = ref<string | null>(null);
const columns: { key: string; title: string; type: ColumnType }[] = [
    { key: 'payment_date_revenue', title: labels.annualPaymentRevenue, type: 'money' },
    { key: 'treatment_date_revenue', title: labels.annualTreatmentRevenue, type: 'money' },
    { key: 'selected_revenue', title: labels.annualSelectedRevenue, type: 'money' },
    { key: 'target_amount', title: labels.annualTarget, type: 'money' },
    { key: 'achievement_rate', title: labels.annualAchievement, type: 'rate' },
    { key: 'visit_count', title: labels.annualVisits, type: 'count' },
    { key: 'long_visit_count', title: labels.annualLong, type: 'count' },
    { key: 'future_reservation_count', title: labels.annualReservationCount, type: 'count' },
    { key: 'reservation_rate', title: labels.annualReservationRate, type: 'rate' },
    { key: 'first_visit_count', title: labels.annualFirst, type: 'count' },
    { key: 'first_visit_reservation_count', title: labels.annualFirstReservation, type: 'count' },
    { key: 'first_visit_reservation_rate', title: labels.annualFirstReservationRate, type: 'rate' },
    { key: 'new_customers', title: labels.annualNew, type: 'count' },
    { key: 'returning_customers', title: labels.annualReturning, type: 'count' },
    { key: 'churn_customers', title: labels.annualChurn, type: 'count' },
    { key: 'reached_2', title: labels.annualReach2, type: 'count' },
    { key: 'reach_2_rate', title: labels.annualReach2Rate, type: 'rate' },
    { key: 'reached_6', title: labels.annualReach6, type: 'count' },
    { key: 'reach_6_rate', title: labels.annualReach6Rate, type: 'rate' },
    { key: 'reached_10', title: labels.annualReach10, type: 'count' },
    { key: 'reach_10_rate', title: labels.annualReach10Rate, type: 'rate' },
    { key: 'legacy_utilization_rate', title: labels.annualLegacyRate, type: 'rate' },
    { key: 'bookable_utilization_rate', title: labels.annualBookableRate, type: 'rate' },
];

function formatted(row: NumericRow, key: string, type: ColumnType, future = false): string {
    if (future && key !== 'target_amount') return labels.annualFuture;
    const value = row[key];
    if (typeof value !== 'number') return '—';
    if (type === 'rate') return `${(value * 100).toFixed(1)}%`;
    const number = new Intl.NumberFormat('ja-JP').format(value);
    return type === 'money' ? `${number}円` : number;
}

async function loadReport(resetAsOf = false): Promise<void> {
    if (resetAsOf) asOfDate.value = '';
    loading.value = true;
    error.value = null;
    try {
        const query = new URLSearchParams({ year: String(year.value), basis: basis.value });
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
</script>

<template>
    <Head :title="labels.annualTitle" />
    <PageHeader :title="labels.annualTitle" :subtitle="labels.annualSubtitle" />
    <SectionCard :title="labels.customerConditions" class="mb-5">
        <div class="d-flex flex-wrap ga-3 align-center">
            <label>{{ labels.annualYear }} <input v-model.number="year" data-testid="year-input" type="number" min="2000" max="2100" @change="loadReport(true)"></label>
            <label>{{ labels.annualBasis }} <select v-model="basis" data-testid="basis-select" @change="loadReport()">
                <option value="payment_date">{{ labels.annualPaymentBasis }}</option>
                <option value="treatment_date">{{ labels.annualTreatmentBasis }}</option>
            </select></label>
            <label>{{ labels.annualAsOf }} <input v-model="asOfDate" data-testid="as-of-input" type="date" @change="loadReport()"></label>
        </div>
        <p v-if="loading" role="status">{{ labels.annualLoading }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
    </SectionCard>
    <SectionCard :title="labels.annualTitle">
        <div class="table-scroll" :aria-busy="loading"><table data-testid="annual-table">
            <thead><tr><th>{{ labels.annualMonth }}</th><th v-for="column in columns" :key="column.key">{{ column.title }}</th></tr></thead>
            <tbody><tr v-for="row in report.months" :key="row.month_key" :class="{ future: row.is_future }">
                <th>{{ row.month }}</th><td v-for="column in columns" :key="column.key">{{ formatted(row, column.key, column.type, row.is_future) }}</td>
            </tr></tbody>
            <tfoot><tr><th>{{ labels.annualTotal }}</th><td v-for="column in columns" :key="column.key">{{ formatted(report.totals, column.key, column.type) }}</td></tr></tfoot>
        </table></div>
    </SectionCard>
</template>

<style scoped>
.table-scroll { overflow-x: auto; }
table { border-collapse: collapse; min-width: 1900px; width: 100%; }
th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: right; white-space: nowrap; }
th:first-child, td:first-child { text-align: left; position: sticky; left: 0; background: white; }
tfoot { font-weight: bold; }
tr.future { color: #666; }
select, input { margin-left: 8px; padding: 6px; border: 1px solid #aaa; border-radius: 4px; }
</style>
