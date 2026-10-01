<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { formatReportDate, MonthlyReportTabs, ReportDailyToolbar, ReportFilterBar, ReportFilterField, ReportSelect, ReportTable, ReportValue, useDailyFilter } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Row {
    business_date?: string; staff_id: number | null; staff_name: string | null;
    band_code: string; band_label: string; occupied_minutes: number | null;
    known_occupied_minutes: number; occupied_unknown_count: number;
    working_minutes: number | null; bookable_minutes: number;
    legacy_utilization_rate: number | null; bookable_utilization_rate: number | null;
    visit_count?: number; day_type?: 'weekday' | 'weekend'; visit_unknown_count?: number;
}
interface Report {
    month_key: string; as_of_date: string; staff: { id: number; name: string }[];
    outside_band_minutes: number; daily_rows: Row[]; monthly_rows: Row[]; overall_rows: Row[];
    store_daily_rows?: Row[]; day_type_rows?: Row[]; visit_unknown_count?: number;
}

const props = defineProps<{ report: Report; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const report = ref(props.report);
const month = ref(props.report.month_key);
const staffId = ref<number | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

const staffItems = computed(() => [
    { title: labels.staffAll, value: null as number | null },
    ...report.value.staff.map((staff) => ({ title: staff.name, value: staff.id as number | null })),
]);

// 時間帯の並び（全体行の順）を正本にして、スタッフ別・日別の行もこの順に並べる。
const bandOrder = computed(() => new Map(report.value.overall_rows.map((row, index) => [row.band_code, index])));
const compareBand = (a: Row, b: Row): number => (bandOrder.value.get(a.band_code) ?? 0) - (bandOrder.value.get(b.band_code) ?? 0);

const monthlyRows = computed(() => {
    const order = new Map(report.value.staff.map((staff, index) => [staff.id, index]));
    const sorted = [...report.value.monthly_rows].sort((a, b) =>
        ((order.get(a.staff_id ?? -1) ?? 0) - (order.get(b.staff_id ?? -1) ?? 0)) || compareBand(a, b));
    return sorted.map((row, index) => ({ row, groupStart: index === 0 || sorted[index - 1].staff_id !== row.staff_id }));
});

/** 稼働分を時間（小数1桁）でも見られるようにする。値なしは null のまま。 */
const hours = (minutes: number | null): number | null => (minutes === null ? null : Math.round((minutes / 60) * 10) / 10);
const dayTypeRows = computed(() => (report.value.day_type_rows ?? []).map((row, index, rows) => ({ row, groupStart: index === 0 || rows[index - 1].day_type !== row.day_type })));
const storeDailyRows = computed(() => {
    const sorted = [...(report.value.store_daily_rows ?? [])].sort((a, b) => (a.business_date ?? '').localeCompare(b.business_date ?? '') || compareBand(a, b));
    return sorted.map((row, index) => ({ row, groupStart: index === 0 || sorted[index - 1].business_date !== row.business_date }));
});

// ── 日別一覧の表示だけの絞り込み ──
const isActive = (row: Row): boolean => (row.working_minutes ?? 0) > 0 || (row.occupied_minutes ?? 0) > 0 || row.occupied_unknown_count > 0;
const {
    staffId: dailyStaffId, date: dailyDate, activeOnly: dailyActiveOnly, availableStaff: dailyStaff, dateItems,
    filtered: filteredDailyRows, compareDate, compareStaff,
} = useDailyFilter(computed(() => report.value.daily_rows), computed(() => report.value.staff), isActive);
const singleStaff = computed(() => dailyStaffId.value !== null);
const dailyRows = computed(() => {
    const sorted = [...filteredDailyRows.value].sort((a, b) => compareDate(a, b) || compareStaff(a, b) || compareBand(a, b));
    // 日付×スタッフのまとまりの先頭行にだけ日付・スタッフを出し、区切り線を引く。
    return sorted.map((row, index) => {
        const previous = sorted[index - 1];
        return {
            row,
            dateStart: index === 0 || previous.business_date !== row.business_date,
            groupStart: index === 0 || previous.business_date !== row.business_date || previous.staff_id !== row.staff_id,
        };
    });
});

async function loadReport(): Promise<void> {
    const [year, selectedMonth] = month.value.split('-').map(Number);
    if (!year || !selectedMonth) return;
    loading.value = true;
    error.value = null;
    try {
        const params = new URLSearchParams({ year: String(year), month: String(selectedMonth) });
        if (staffId.value !== null) params.set('staff_id', String(staffId.value));
        const response = await fetch(`${props.dataEndpoint}?${params}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json() as { data: Report };
        report.value = payload.data;
    } catch {
        error.value = labels.bandLoadFailed;
    } finally {
        loading.value = false;
    }
}

function changeMonth(value: string): void {
    if (value === month.value) return;
    month.value = value;
    void loadReport();
}

function changeStaff(value: number | null): void {
    staffId.value = value;
    void loadReport();
}
</script>

<template>
    <Head :title="labels.bandTitle" />
    <PageHeader :title="labels.bandTitle" :subtitle="labels.bandSubtitle" />
    <MonthlyReportTabs active="bands" :month="month" />

    <ReportFilterBar :loading="loading" :loading-text="labels.bandLoading" :error="error">
        <ReportFilterField size="md">
            <MonthField :model-value="month" :label="labels.staffMonth" data-testid="month-input" @update:model-value="changeMonth" />
        </ReportFilterField>
        <ReportFilterField size="lg">
            <ReportSelect :model-value="staffId" :items="staffItems" :label="labels.staffName" data-testid="staff-filter" @update:model-value="changeStaff" />
        </ReportFilterField>
        <template #meta>
            {{ labels.bandOutside }}: <ReportValue :value="report.outside_band_minutes" />分 / {{ labels.annualAsOf }} {{ report.as_of_date }}
        </template>
    </ReportFilterBar>

    <SectionCard :title="labels.bandOverall" :subtitle="labels.bandOverallHint" class="mb-4">
        <ReportTable :loading="loading" min-width="860px" sticky-width="132px" max-height="none" data-testid="overall-table">
            <thead>
                <tr>
                    <th class="is-sticky">{{ labels.bandTime }}</th>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.bandUnknown }}</th><th class="num">{{ labels.staffWorking }}</th>
                    <th class="num group-start">{{ labels.staffLegacyRate }}</th><th class="num">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th>
                    <th class="num group-start">{{ labels.bandVisits }}</th><th class="num">{{ labels.bandOccupiedHours }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.overall_rows" :key="row.band_code">
                    <th class="is-sticky">{{ row.band_label }}</th>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.occupied_unknown_count" /></td>
                    <td class="num"><ReportValue :value="row.working_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.legacy_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.visit_count ?? null" /></td>
                    <td class="num"><ReportValue :value="hours(row.occupied_minutes)" format="decimal" :empty-label="labels.staffUnknown" /></td>
                </tr>
                <tr v-if="report.overall_rows.length === 0"><td colspan="9" class="empty-cell">{{ labels.staffNoData }}</td></tr>
            </tbody>
        </ReportTable>
        <p class="band-note">{{ labels.bandVisitsHint }}<template v-if="(report.visit_unknown_count ?? 0) > 0">（{{ labels.bandVisitsUnknown }} {{ report.visit_unknown_count }}）</template></p>
    </SectionCard>

    <SectionCard :title="labels.bandDayType" :subtitle="labels.bandDayTypeHint" class="mb-4">
        <ReportTable :loading="loading" min-width="860px" sticky-width="96px" max-height="none" data-testid="day-type-table">
            <thead>
                <tr>
                    <th class="is-sticky">{{ labels.bandDayTypeColumn }}</th><th>{{ labels.bandTime }}</th>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.staffWorking }}</th><th class="num">{{ labels.staffLegacyRate }}</th>
                    <th class="num group-start">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th><th class="num group-start">{{ labels.bandVisits }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="{ row, groupStart } in dayTypeRows" :key="`${row.day_type}-${row.band_code}`" :class="{ 'row-group-start': groupStart }">
                    <th class="is-sticky"><template v-if="groupStart">{{ row.day_type === 'weekday' ? labels.bandWeekday : labels.bandWeekend }}</template></th>
                    <td>{{ row.band_label }}</td>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.working_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.legacy_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.visit_count ?? null" /></td>
                </tr>
                <tr v-if="dayTypeRows.length === 0"><td colspan="8" class="empty-cell">{{ labels.staffNoData }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>

    <SectionCard :title="labels.bandStoreDaily" :subtitle="labels.bandStoreDailyHint" class="mb-4">
        <ReportTable :loading="loading" min-width="860px" sticky-width="104px" max-height="56vh" data-testid="store-daily-table">
            <thead>
                <tr>
                    <th class="is-sticky">{{ labels.staffDate }}</th><th>{{ labels.bandTime }}</th>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.staffWorking }}</th>
                    <th class="num group-start">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th><th class="num group-start">{{ labels.bandVisits }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="{ row, groupStart } in storeDailyRows" :key="`${row.business_date}-${row.band_code}`" :class="{ 'row-group-start': groupStart }">
                    <th class="is-sticky"><template v-if="groupStart">{{ formatReportDate(row.business_date ?? '') }}</template></th>
                    <td>{{ row.band_label }}</td>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.working_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.visit_count ?? null" /></td>
                </tr>
                <tr v-if="storeDailyRows.length === 0"><td colspan="7" class="empty-cell">{{ labels.staffNoData }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>

    <SectionCard :title="labels.bandByStaff" class="mb-4">
        <ReportTable :loading="loading" min-width="980px" sticky-width="148px" max-height="none" data-testid="monthly-table">
            <thead>
                <tr>
                    <th class="is-sticky">{{ labels.staffName }}</th><th class="is-sticky-2">{{ labels.bandTime }}</th>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.bandUnknown }}</th><th class="num">{{ labels.staffWorking }}</th>
                    <th class="num group-start">{{ labels.staffLegacyRate }}</th><th class="num">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th>
                    <th class="num group-start">{{ labels.bandVisits }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="{ row, groupStart } in monthlyRows" :key="`${row.staff_id}-${row.band_code}`" :class="{ 'row-group-start': groupStart }">
                    <th class="is-sticky"><template v-if="groupStart">{{ row.staff_name }}</template></th>
                    <td class="is-sticky-2">{{ row.band_label }}</td>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.occupied_unknown_count" /></td>
                    <td class="num"><ReportValue :value="row.working_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.legacy_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.visit_count ?? null" /></td>
                </tr>
                <tr v-if="report.monthly_rows.length === 0"><td colspan="9" class="empty-cell">{{ labels.staffNoData }}</td></tr>
            </tbody>
        </ReportTable>
    </SectionCard>

    <SectionCard :title="labels.staffDaily" :subtitle="labels.staffDailyHint">
        <ReportDailyToolbar
            v-model:staff-id="dailyStaffId"
            v-model:date="dailyDate"
            v-model:active-only="dailyActiveOnly"
            :staff="dailyStaff"
            :date-items="dateItems"
            :count="dailyRows.length"
        >
        </ReportDailyToolbar>
        <ReportTable :loading="loading" min-width="1040px" sticky-width="104px" max-height="64vh" data-testid="daily-table">
            <thead>
                <tr>
                    <th class="is-sticky">{{ labels.staffDate }}</th>
                    <th v-if="!singleStaff" class="is-sticky-2">{{ labels.staffName }}</th>
                    <th :class="{ 'is-sticky-2': singleStaff }">{{ labels.bandTime }}</th>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.bandUnknown }}</th><th class="num">{{ labels.staffWorking }}</th>
                    <th class="num group-start">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th>
                    <th class="num group-start">{{ labels.bandVisits }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="{ row, dateStart, groupStart } in dailyRows"
                    :key="`${row.business_date}-${row.staff_id}-${row.band_code}`"
                    :class="{ 'row-group-start': groupStart, 'row-muted': !isActive(row) }"
                >
                    <th class="is-sticky"><template v-if="dateStart || (singleStaff && groupStart)">{{ formatReportDate(row.business_date ?? '') }}</template></th>
                    <th v-if="!singleStaff" class="is-sticky-2"><template v-if="groupStart">{{ row.staff_name }}</template></th>
                    <td :class="{ 'is-sticky-2': singleStaff }">{{ row.band_label }}</td>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.occupied_unknown_count" /></td>
                    <td class="num"><ReportValue :value="row.working_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.visit_count ?? null" /></td>
                </tr>
                <tr v-if="dailyRows.length === 0">
                    <td :colspan="singleStaff ? 8 : 9" class="empty-cell">{{ report.daily_rows.length === 0 ? labels.staffNoData : labels.dailyNoMatch }}</td>
                </tr>
            </tbody>
        </ReportTable>
    </SectionCard>
</template>

<style scoped>
.band-note { color: #6b7785; font-size: 0.85rem; margin-top: 8px; }
</style>
