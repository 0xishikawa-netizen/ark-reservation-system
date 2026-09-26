<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { ReportFilterBar, ReportFilterField, ReportKpi, ReportSelect, ReportTable, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type SalesBasis = 'payment_date' | 'treatment_date';
type CourseType = 'ticket' | 'membership' | 'service';
interface Row {
    course_type: CourseType; course_id: number; name: string; is_active: boolean;
    sales_amount: number; sales_quantity: number; usage_count: number; user_count: number;
    target_amount: number | null; target_count: number | null; average_unit_amount: number | null;
    difference_amount: number | null; achievement_rate: number | null;
}
interface Report { month_key: string; sales_basis: SalesBasis; rows: Row[]; totals: { sales_amount: number; sales_quantity: number; usage_count: number; target_amount: number | null } }

const props = defineProps<{ report: Report; dataEndpoint: string; targetEndpoint: string; canEditTargets: boolean }>();
const labels = MESSAGES.reporting;
const report = ref<Report>(props.report);
const month = ref(props.report.month_key);
const basis = ref<SalesBasis>(props.report.sales_basis);
const loading = ref(false);
const error = ref<string | null>(null);
const editing = ref<string | null>(null);
const draftAmount = ref<number | null>(null);
const basisItems: { title: string; value: SalesBasis }[] = [
    { title: labels.annualPaymentBasis, value: 'payment_date' },
    { title: labels.annualTreatmentBasis, value: 'treatment_date' },
];
const typeLabel: Record<CourseType, string> = { ticket: labels.courseTypeTicket, membership: labels.courseTypeMembership, service: labels.courseTypeService };
const rowKey = (row: Row): string => `${row.course_type}:${row.course_id}`;

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
        error.value = labels.courseLoadFailed;
    } finally {
        loading.value = false;
    }
}

function changeMonth(value: string): void { if (value !== month.value) { month.value = value; void load(); } }
function changeBasis(value: SalesBasis): void { if (value !== basis.value) { basis.value = value; void load(); } }
function startEdit(row: Row): void { editing.value = rowKey(row); draftAmount.value = row.target_amount; }
function saveTarget(row: Row, clear = false): void {
    router.put(props.targetEndpoint, {
        month: month.value, course_type: row.course_type, course_id: row.course_id,
        target_amount: clear ? null : draftAmount.value, target_count: null,
    }, { preserveScroll: true, preserveState: true, onSuccess: () => { editing.value = null; void load(); } });
}
</script>

<template>
    <Head :title="labels.courseSalesTitle" />
    <PageHeader :title="labels.courseSalesTitle" :subtitle="labels.courseSalesSubtitle" />
    <ReportFilterBar :loading="loading" :loading-text="MESSAGES.common.loading" :error="error">
        <ReportFilterField size="md"><MonthField :model-value="month" :label="MESSAGES.calendar.targetMonth" density="compact" data-testid="month-input" @update:model-value="changeMonth" /></ReportFilterField>
        <ReportFilterField size="md"><ReportSelect :model-value="basis" :items="basisItems" :label="labels.annualBasis" data-testid="basis-select" @update:model-value="changeBasis" /></ReportFilterField>
    </ReportFilterBar>

    <div class="report-kpi-grid" :aria-busy="loading">
        <ReportKpi :label="labels.courseSales" emphasis><ReportValue :value="report.totals.sales_amount" format="money" /></ReportKpi>
        <ReportKpi :label="labels.courseQuantity"><ReportValue :value="report.totals.sales_quantity" /></ReportKpi>
        <ReportKpi :label="labels.courseUsage"><ReportValue :value="report.totals.usage_count" /></ReportKpi>
        <ReportKpi :label="labels.courseTarget"><ReportValue :value="report.totals.target_amount" format="money" :empty-label="MESSAGES.common.notSet" /></ReportKpi>
    </div>
    <p class="note">{{ labels.courseNote }}</p>

    <SectionCard :title="labels.courseSalesTitle">
        <ReportTable :loading="loading" min-width="1160px" sticky-width="220px" max-height="none" data-testid="course-sales-table">
            <thead>
                <tr class="group-row">
                    <th rowspan="2" class="is-sticky">{{ labels.courseName }}</th><th rowspan="2">{{ labels.courseType }}</th>
                    <th colspan="3" class="group-start">{{ labels.courseSales }}</th><th colspan="2" class="group-start">{{ labels.courseUsage }}</th>
                    <th colspan="3" class="group-start">{{ labels.courseTarget }}</th><th v-if="canEditTargets" rowspan="2" />
                </tr>
                <tr>
                    <th class="num group-start">{{ labels.courseSales }}</th><th class="num">{{ labels.courseQuantity }}</th><th class="num">{{ labels.courseAverage }}</th>
                    <th class="num group-start">{{ labels.courseUsage }}</th><th class="num">{{ labels.courseUsers }}</th>
                    <th class="num group-start">{{ labels.courseTarget }}</th><th class="num">{{ labels.courseDifference }}</th><th class="num">{{ labels.courseAchievement }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.rows" :key="rowKey(row)" :class="{ 'row-muted': !row.is_active }">
                    <th class="is-sticky">{{ row.name }}<small v-if="!row.is_active" class="muted">（{{ labels.courseInactive }}）</small></th>
                    <td>{{ typeLabel[row.course_type] }}</td>
                    <td class="num group-start"><ReportValue :value="row.sales_amount" format="money" /></td>
                    <td class="num"><ReportValue :value="row.sales_quantity" /></td>
                    <td class="num"><ReportValue :value="row.average_unit_amount" format="money" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.usage_count" /></td>
                    <td class="num"><ReportValue :value="row.user_count" /></td>
                    <td class="num group-start">
                        <v-text-field v-if="editing === rowKey(row)" v-model.number="draftAmount" type="number" min="0" density="compact" variant="outlined" hide-details
                            :aria-label="labels.courseTarget" style="min-width: 130px" />
                        <ReportValue v-else :value="row.target_amount" format="money" :empty-label="MESSAGES.common.notSet" />
                    </td>
                    <td class="num"><ReportValue :value="row.difference_amount" format="money" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.achievement_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td v-if="canEditTargets">
                        <template v-if="editing === rowKey(row)">
                            <v-btn size="small" color="primary" variant="flat" @click="saveTarget(row)">{{ labels.courseTargetSave }}</v-btn>
                            <v-btn size="small" variant="text" @click="saveTarget(row, true)">{{ labels.courseTargetClear }}</v-btn>
                        </template>
                        <v-btn v-else size="small" variant="text" :data-testid="`edit-target-${rowKey(row)}`" @click="startEdit(row)">{{ labels.courseTargetEdit }}</v-btn>
                    </td>
                </tr>
                <tr v-if="report.rows.length === 0"><td :colspan="canEditTargets ? 11 : 10" class="empty-cell">{{ labels.courseNoData }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>
</template>

<style scoped>
.note { color: #6b7785; font-size: 0.85rem; margin: 4px 0 16px; }
.muted { color: #6b7785; margin-left: 4px; }
</style>
