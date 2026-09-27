<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { formatReportDate, MonthlyReportTabs, ReportFilterBar, ReportFilterField, ReportKpi, ReportSelect, ReportTable, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type SalesBasis = 'payment_date' | 'treatment_date';
interface Amounts { total_amount: number; nominated_amount: number; non_nominated_amount: number; nomination_unknown_amount: number }
interface StaffRow extends Amounts { staff_id: number | null; staff_name: string | null; nominated_share: number | null;
    occupied_minutes?: number | null; working_minutes?: number | null; patient_count?: number | null;
    sales_per_occupied_hour?: number | null; sales_per_working_hour?: number | null }
interface DailyRow extends Amounts { business_date: string; staff_id: number | null; staff_name: string | null }
interface Report { month_key: string; sales_basis: SalesBasis; staff_rows: StaffRow[]; daily_rows: DailyRow[]; totals: Amounts }

const props = defineProps<{ report: Report; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const report = ref<Report>(props.report);
const month = ref(props.report.month_key);
const basis = ref<SalesBasis>(props.report.sales_basis);
const loading = ref(false);
const error = ref<string | null>(null);
const basisItems: { title: string; value: SalesBasis }[] = [
    { title: labels.annualPaymentBasis, value: 'payment_date' },
    { title: labels.annualTreatmentBasis, value: 'treatment_date' },
];

async function load(): Promise<void> {
    const [year, selectedMonth] = month.value.split('-').map(Number);
    if (!year || !selectedMonth) return;
    loading.value = true;
    error.value = null;
    try {
        const query = new URLSearchParams({ year: String(year), month: String(selectedMonth), basis: basis.value });
        const response = await fetch(`${props.dataEndpoint}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        report.value = ((await response.json()) as { data: Report }).data;
    } catch {
        error.value = labels.staffSalesLoadFailed;
    } finally {
        loading.value = false;
    }
}

function changeMonth(value: string): void { if (value !== month.value) { month.value = value; void load(); } }
function changeBasis(value: SalesBasis): void { if (value !== basis.value) { basis.value = value; void load(); } }
</script>

<template>
    <Head :title="labels.staffSalesTitle" />
    <PageHeader :title="labels.staffSalesTitle" :subtitle="labels.staffSalesSubtitle" />
    <MonthlyReportTabs active="staffSales" :month="month" :basis="basis" />
    <ReportFilterBar :loading="loading" :loading-text="MESSAGES.common.loading" :error="error">
        <ReportFilterField size="md"><MonthField :model-value="month" :label="MESSAGES.calendar.targetMonth" density="compact" data-testid="month-input" @update:model-value="changeMonth" /></ReportFilterField>
        <ReportFilterField size="md"><ReportSelect :model-value="basis" :items="basisItems" :label="labels.annualBasis" data-testid="basis-select" @update:model-value="changeBasis" /></ReportFilterField>
    </ReportFilterBar>

    <div class="report-kpi-grid" :aria-busy="loading">
        <ReportKpi :label="labels.staffSalesTotal" emphasis><ReportValue :value="report.totals.total_amount" format="money" /></ReportKpi>
        <ReportKpi :label="labels.staffSalesNominated"><ReportValue :value="report.totals.nominated_amount" format="money" /></ReportKpi>
        <ReportKpi :label="labels.staffSalesNonNominated"><ReportValue :value="report.totals.non_nominated_amount" format="money" /></ReportKpi>
        <ReportKpi :label="labels.staffSalesUnknown"><ReportValue :value="report.totals.nomination_unknown_amount" format="money" /><template #caption>{{ labels.staffSalesUnknownHint }}</template></ReportKpi>
    </div>
    <p class="note">{{ labels.staffSalesNote }} {{ MESSAGES.monthlyHub.perHourHint }}</p>

    <SectionCard :title="labels.staffSalesMonthly" class="mb-4">
        <ReportTable :loading="loading" min-width="760px" max-height="none" data-testid="staff-sales-monthly">
            <thead><tr>
                <th class="is-sticky">{{ labels.staffName }}</th>
                <th class="num">{{ labels.staffSalesTotal }}</th><th class="num">{{ labels.staffSalesNominated }}</th>
                <th class="num">{{ labels.staffSalesNonNominated }}</th><th class="num">{{ labels.staffSalesUnknown }}</th><th class="num">{{ labels.staffSalesShare }}</th>
                <th class="num">{{ labels.staffPatients }}</th><th class="num">{{ labels.staffOccupied }}</th>
                <th class="num">{{ MESSAGES.monthlyHub.perOccupiedHour }}</th><th class="num">{{ MESSAGES.monthlyHub.perWorkingHour }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in report.staff_rows" :key="`${row.staff_id}-${row.staff_name}`">
                    <th class="is-sticky">{{ row.staff_name }}</th>
                    <td class="num"><ReportValue :value="row.total_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.nominated_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.non_nominated_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.nomination_unknown_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.nominated_share" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.patient_count ?? null" /></td>
                    <td class="num"><ReportValue :value="row.occupied_minutes ?? null" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.sales_per_occupied_hour ?? null" format="money" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.sales_per_working_hour ?? null" format="money" :empty-label="MESSAGES.common.notCalculated" /></td>
                </tr>
                <tr v-if="report.staff_rows.length === 0"><td colspan="10" class="empty-cell">{{ labels.staffSalesNoData }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>

    <SectionCard :title="labels.staffSalesDaily">
        <ReportTable :loading="loading" min-width="760px" data-testid="staff-sales-daily">
            <thead><tr>
                <th class="is-sticky">{{ labels.staffDate }}</th><th>{{ labels.staffName }}</th>
                <th class="num">{{ labels.staffSalesTotal }}</th><th class="num">{{ labels.staffSalesNominated }}</th>
                <th class="num">{{ labels.staffSalesNonNominated }}</th><th class="num">{{ labels.staffSalesUnknown }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in report.daily_rows" :key="`${row.business_date}-${row.staff_id}-${row.staff_name}`">
                    <th class="is-sticky">{{ formatReportDate(row.business_date) }}</th><td>{{ row.staff_name }}</td>
                    <td class="num"><ReportValue :value="row.total_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.nominated_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.non_nominated_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.nomination_unknown_amount" format="money" /></td>
                </tr>
                <tr v-if="report.daily_rows.length === 0"><td colspan="6" class="empty-cell">{{ labels.staffSalesNoData }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>
</template>

<style scoped>
.note { color: #6b7785; font-size: 0.85rem; margin: 4px 0 16px; }
</style>
