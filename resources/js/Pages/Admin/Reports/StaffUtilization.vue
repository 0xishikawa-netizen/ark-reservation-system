<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EmptyValue, MonthField, PageHeader, SectionCard } from '@/components/ark';
import { formatReportDate, MonthlyReportTabs, ReportDailyToolbar, ReportFilterBar, ReportFilterField, ReportSelect, ReportTable, ReportValue, useDailyFilter } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Option { id: number; name: string; code?: string }
interface Row {
    staff_id: number; staff_name: string; business_date?: string;
    employment_type_id?: number | null; employment_type_name?: string | null;
    working_minutes: number | null; working_minutes_source?: 'actual' | 'scheduled_fallback' | 'unknown';
    scheduled_shift_present?: boolean; occupied_minutes: number | null; bookable_minutes: number;
    patient_count: number; future_reservation_count: number | null;
    nomination_count: number | null; nomination_supported: boolean;
    reservation_rate: number | null; nomination_rate: number | null;
    legacy_utilization_rate: number | null; bookable_utilization_rate: number | null;
}
interface Report {
    month_key: string; as_of_date: string; staff: Option[]; employment_types: Option[];
    unknown_primary_visit_count: number; daily_rows: Row[]; monthly_rows: Row[];
}
type DailyGrouping = 'date' | 'staff';

const props = defineProps<{ report: Report; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const report = ref<Report>(props.report);
const month = ref(props.report.month_key);
const staffId = ref<number | null>(null);
const typeId = ref<number | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

const typeItems = computed(() => [
    { title: labels.staffAll, value: null as number | null },
    ...report.value.employment_types.map((type) => ({ title: type.name, value: type.id as number | null })),
]);
const staffItems = computed(() => [
    { title: labels.staffAll, value: null as number | null },
    ...report.value.staff.map((staff) => ({ title: staff.name, value: staff.id as number | null })),
]);

/** 出勤mが無い理由（勤務予定なし / 未取得）。 */
const workingEmptyLabel = (row: Row): string => (row.scheduled_shift_present === false ? labels.staffNoShift : labels.staffUnknown);
const sourceNote = (row: Row): string | null => {
    if (row.working_minutes === null) return null;
    if (row.working_minutes_source === 'actual') return labels.staffActual;
    if (row.working_minutes_source === 'scheduled_fallback') return labels.staffFallback;
    return null;
};

// ── 日別一覧の表示だけの絞り込み（読み込み済みデータを絞る。上部の表示条件とは独立） ──
const isActive = (row: Row): boolean => (row.working_minutes ?? 0) > 0 || (row.occupied_minutes ?? 0) > 0 || row.patient_count > 0;
const {
    staffId: dailyStaffId, date: dailyDate, activeOnly: dailyActiveOnly, availableStaff: dailyStaff, dateItems,
    filtered: filteredDailyRows, compareDate, compareStaff,
} = useDailyFilter(computed(() => report.value.daily_rows), computed(() => report.value.staff), isActive);
const dailyGrouping = ref<DailyGrouping>('date');
const singleStaff = computed(() => dailyStaffId.value !== null);
const groupKey = (row: Row): string => (singleStaff.value || dailyGrouping.value === 'date' ? row.business_date ?? '' : String(row.staff_id));
const dailyRows = computed(() => {
    const sorted = [...filteredDailyRows.value].sort((a, b) => (dailyGrouping.value === 'staff' && !singleStaff.value
        ? compareStaff(a, b) || compareDate(a, b)
        : compareDate(a, b) || compareStaff(a, b)));
    // まとまり（日付 or スタッフ）の先頭行だけ見出しセルを出し、区切り線を引く。
    return sorted.map((row, index) => ({ row, groupStart: index === 0 || groupKey(sorted[index - 1]) !== groupKey(row) }));
});
// 左端の固定列。「スタッフごと」ではスタッフ→日付、それ以外は日付→スタッフ（1人表示ではスタッフ列を省く）。
type LeadColumn = 'date' | 'staff';
const leadColumns = computed<LeadColumn[]>(() => {
    if (singleStaff.value) return ['date'];
    return dailyGrouping.value === 'staff' ? ['staff', 'date'] : ['date', 'staff'];
});
const dailyColumnCount = computed(() => leadColumns.value.length + 11);
/** まとまりの見出し列は先頭行だけ表示し、それ以外の列は毎行表示する。 */
const showLead = (index: number, groupStart: boolean): boolean => index > 0 || leadColumns.value.length === 1 || groupStart;

async function loadReport(): Promise<void> {
    const [year, selectedMonth] = month.value.split('-').map(Number);
    if (!year || !selectedMonth) return;
    loading.value = true;
    error.value = null;
    try {
        const params = new URLSearchParams({ year: String(year), month: String(selectedMonth) });
        if (staffId.value !== null) params.set('staff_id', String(staffId.value));
        if (typeId.value !== null) params.set('employment_type_id', String(typeId.value));
        const response = await fetch(`${props.dataEndpoint}?${params}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json() as { data: Report };
        report.value = payload.data;
    } catch {
        error.value = labels.staffLoadFailed;
    } finally {
        loading.value = false;
    }
}

function changeMonth(value: string): void {
    if (value === month.value) return;
    month.value = value;
    void loadReport();
}

function changeType(value: number | null): void {
    typeId.value = value;
    void loadReport();
}

function changeStaff(value: number | null): void {
    staffId.value = value;
    void loadReport();
}
</script>

<template>
    <Head :title="labels.staffTitle" />
    <PageHeader :title="labels.staffTitle" :subtitle="labels.staffSubtitle">
        <template #actions>
            <v-btn href="/admin/reports/monthly" variant="outlined" color="primary" prepend-icon="mdi-arrow-left" data-testid="back-to-monthly">
                {{ labels.customerBackToMonthly }}
            </v-btn>
        </template>
    </PageHeader>
    <MonthlyReportTabs active="staff" :month="month" />

    <ReportFilterBar :loading="loading" :loading-text="labels.staffLoading" :error="error">
        <ReportFilterField size="md">
            <MonthField :model-value="month" :label="labels.staffMonth" data-testid="month-input" @update:model-value="changeMonth" />
        </ReportFilterField>
        <ReportFilterField size="sm">
            <ReportSelect :model-value="typeId" :items="typeItems" :label="labels.staffType" data-testid="type-filter" @update:model-value="changeType" />
        </ReportFilterField>
        <ReportFilterField size="lg">
            <ReportSelect :model-value="staffId" :items="staffItems" :label="labels.staffName" data-testid="staff-filter" @update:model-value="changeStaff" />
        </ReportFilterField>
        <template #meta>
            {{ labels.staffUnknownPrimary }}: <ReportValue :value="report.unknown_primary_visit_count" />件 / {{ labels.annualAsOf }} {{ report.as_of_date }}
        </template>
    </ReportFilterBar>

    <SectionCard :title="labels.staffMonthly" class="mb-4">
        <ReportTable :loading="loading" min-width="1180px" sticky-width="148px" max-height="none" data-testid="monthly-table">
            <thead>
                <tr class="group-row">
                    <th rowspan="2" class="is-sticky">{{ labels.staffName }}</th>
                    <th rowspan="2">{{ labels.staffEmployment }}</th>
                    <th colspan="3" class="group-start">{{ labels.staffGroupActual }}</th>
                    <th colspan="4" class="group-start">{{ labels.staffGroupReservation }}</th>
                    <th colspan="3" class="group-start">{{ labels.staffGroupRate }}</th>
                </tr>
                <tr>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.staffPatients }}</th><th class="num">{{ labels.staffWorking }}</th>
                    <th class="num group-start">{{ labels.staffFuture }}</th><th class="num">{{ labels.staffNomination }}</th>
                    <th class="num">{{ labels.staffReservationRate }}</th><th class="num">{{ labels.staffNominationRate }}</th>
                    <th class="num group-start">{{ labels.staffLegacyRate }}</th><th class="num">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.monthly_rows" :key="`${row.staff_id}-${row.employment_type_id ?? 'unknown'}`">
                    <th class="is-sticky">{{ row.staff_name }}</th>
                    <td><template v-if="row.employment_type_name">{{ row.employment_type_name }}</template><EmptyValue v-else :label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.patient_count" /></td>
                    <td class="num"><ReportValue :value="row.working_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.future_reservation_count" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.nomination_count" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.reservation_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.nomination_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.legacy_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                </tr>
                <tr v-if="report.monthly_rows.length === 0"><td colspan="12" class="empty-cell">{{ labels.staffNoData }}</td></tr>
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
            <v-btn-toggle
                v-if="!singleStaff"
                v-model="dailyGrouping"
                mandatory
                density="compact"
                variant="outlined"
                color="primary"
                divided
                :aria-label="labels.dailyGrouping"
                data-testid="daily-grouping"
            >
                <v-btn value="date" size="small">{{ labels.dailyByDate }}</v-btn>
                <v-btn value="staff" size="small">{{ labels.dailyByStaff }}</v-btn>
            </v-btn-toggle>
        </ReportDailyToolbar>
        <ReportTable :loading="loading" :min-width="singleStaff ? '1100px' : '1280px'" sticky-width="104px" max-height="64vh" data-testid="daily-table">
            <thead>
                <tr class="group-row">
                    <th v-for="(column, index) in leadColumns" :key="column" rowspan="2" :class="index === 0 ? 'is-sticky' : 'is-sticky-2'">
                        {{ column === 'date' ? labels.staffDate : labels.staffName }}
                    </th>
                    <th rowspan="2">{{ labels.staffEmployment }}</th>
                    <th colspan="3" class="group-start">{{ labels.staffGroupActual }}</th>
                    <th colspan="4" class="group-start">{{ labels.staffGroupReservation }}</th>
                    <th colspan="3" class="group-start">{{ labels.staffGroupRate }}</th>
                </tr>
                <tr>
                    <th class="num group-start">{{ labels.staffOccupied }}</th><th class="num">{{ labels.staffPatients }}</th><th class="num">{{ labels.staffWorking }}</th>
                    <th class="num group-start">{{ labels.staffFuture }}</th><th class="num">{{ labels.staffNomination }}</th>
                    <th class="num">{{ labels.staffReservationRate }}</th><th class="num">{{ labels.staffNominationRate }}</th>
                    <th class="num group-start">{{ labels.staffLegacyRate }}</th><th class="num">{{ labels.staffBookable }}</th><th class="num">{{ labels.staffBookableRate }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="{ row, groupStart } in dailyRows"
                    :key="`${row.staff_id}-${row.business_date}`"
                    :class="{ 'row-group-start': groupStart, 'row-muted': !isActive(row) }"
                >
                    <th v-for="(column, index) in leadColumns" :key="column" :class="index === 0 ? 'is-sticky' : 'is-sticky-2'">
                        <template v-if="showLead(index, groupStart)">
                            {{ column === 'date' ? formatReportDate(row.business_date ?? '') : row.staff_name }}
                        </template>
                    </th>
                    <td><template v-if="row.employment_type_name">{{ row.employment_type_name }}</template><EmptyValue v-else :label="labels.staffUnknown" /></td>
                    <td class="num group-start"><ReportValue :value="row.occupied_minutes" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.patient_count" /></td>
                    <td class="num">
                        <span v-if="sourceNote(row)" class="cell-note">{{ sourceNote(row) }}</span>
                        <ReportValue :value="row.working_minutes" :empty-label="workingEmptyLabel(row)" />
                    </td>
                    <td class="num group-start"><ReportValue :value="row.future_reservation_count" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.nomination_count" :empty-label="labels.staffUnknown" /></td>
                    <td class="num"><ReportValue :value="row.reservation_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.nomination_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num group-start"><ReportValue :value="row.legacy_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    <td class="num"><ReportValue :value="row.bookable_minutes" /></td>
                    <td class="num"><ReportValue :value="row.bookable_utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                </tr>
                <tr v-if="dailyRows.length === 0">
                    <td :colspan="dailyColumnCount" class="empty-cell">{{ report.daily_rows.length === 0 ? labels.staffNoData : labels.dailyNoMatch }}</td>
                </tr>
            </tbody>
        </ReportTable>
    </SectionCard>
</template>
