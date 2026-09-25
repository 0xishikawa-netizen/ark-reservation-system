<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
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

const props = defineProps<{ report: Report; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const report = ref<Report>(props.report);
const month = ref(props.report.month_key);
const staffId = ref<number | null>(null);
const typeId = ref<number | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const staffOptions = computed(() => report.value.staff);
const typeOptions = computed(() => report.value.employment_types);
const count = (value: number | null): string => value === null ? labels.staffUnknown : new Intl.NumberFormat('ja-JP').format(value);
const rate = (value: number | null): string => value === null ? '—' : `${(value * 100).toFixed(1)}%`;
const source = (row: Row): string => {
    if (row.working_minutes_source === 'actual') return labels.staffActual;
    if (row.working_minutes_source === 'scheduled_fallback') return labels.staffFallback;
    return row.scheduled_shift_present === false ? labels.staffNoShift : labels.staffUnknown;
};

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
</script>

<template>
    <Head :title="labels.staffTitle" />
    <PageHeader :title="labels.staffTitle" :subtitle="labels.staffSubtitle" />
    <p class="mb-4"><a href="/admin/reports/monthly">{{ labels.customerBackToMonthly }}</a></p>
    <SectionCard :title="labels.customerConditions" class="mb-5">
        <div class="d-flex flex-wrap ga-3 align-center">
            <label>{{ labels.staffMonth }} <input v-model="month" data-testid="month-input" type="month" @change="loadReport"></label>
            <label>{{ labels.staffType }}
                <select v-model="typeId" data-testid="type-filter" @change="loadReport">
                    <option :value="null">{{ labels.staffAll }}</option>
                    <option v-for="type in typeOptions" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
            </label>
            <label>{{ labels.staffName }}
                <select v-model="staffId" data-testid="staff-filter" @change="loadReport">
                    <option :value="null">{{ labels.staffAll }}</option>
                    <option v-for="staff in staffOptions" :key="staff.id" :value="staff.id">{{ staff.name }}</option>
                </select>
            </label>
        </div>
        <p v-if="loading" role="status">{{ labels.staffLoading }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p class="text-caption mt-2">{{ labels.staffUnknownPrimary }}: {{ count(report.unknown_primary_visit_count) }} / {{ report.as_of_date }}</p>
    </SectionCard>
    <SectionCard :title="labels.staffMonthly" class="mb-5">
        <div class="table-scroll" :aria-busy="loading">
            <table data-testid="monthly-table">
                <thead><tr>
                    <th>{{ labels.staffName }}</th><th>{{ labels.staffEmployment }}</th><th>{{ labels.staffOccupied }}</th><th>{{ labels.staffPatients }}</th>
                    <th>{{ labels.staffWorking }}</th><th>{{ labels.staffFuture }}</th><th>{{ labels.staffNomination }}</th>
                    <th>{{ labels.staffReservationRate }}</th><th>{{ labels.staffNominationRate }}</th>
                    <th>{{ labels.staffLegacyRate }}</th><th>{{ labels.staffBookable }}</th><th>{{ labels.staffBookableRate }}</th>
                </tr></thead>
                <tbody>
                    <tr v-for="row in report.monthly_rows" :key="`${row.staff_id}-${row.employment_type_id ?? 'unknown'}`">
                        <td>{{ row.staff_name }}</td><td>{{ row.employment_type_name ?? labels.staffUnknown }}</td><td>{{ count(row.occupied_minutes) }}</td><td>{{ count(row.patient_count) }}</td>
                        <td>{{ count(row.working_minutes) }}</td><td>{{ count(row.future_reservation_count) }}</td>
                        <td>{{ count(row.nomination_count) }}</td><td>{{ rate(row.reservation_rate) }}</td>
                        <td>{{ rate(row.nomination_rate) }}</td><td>{{ rate(row.legacy_utilization_rate) }}</td>
                        <td>{{ count(row.bookable_minutes) }}</td><td>{{ rate(row.bookable_utilization_rate) }}</td>
                    </tr>
                    <tr v-if="report.monthly_rows.length === 0"><td colspan="12">{{ labels.staffNoData }}</td></tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
    <SectionCard :title="labels.staffDaily">
        <div class="table-scroll" :aria-busy="loading">
            <table data-testid="daily-table">
                <thead><tr>
                    <th>{{ labels.staffDate }}</th><th>{{ labels.staffName }}</th><th>{{ labels.staffEmployment }}</th>
                    <th>{{ labels.staffOccupied }}</th><th>{{ labels.staffPatients }}</th><th>{{ labels.staffWorking }}</th>
                    <th>{{ labels.staffFuture }}</th><th>{{ labels.staffNomination }}</th><th>{{ labels.staffReservationRate }}</th>
                    <th>{{ labels.staffNominationRate }}</th><th>{{ labels.staffLegacyRate }}</th><th>{{ labels.staffBookable }}</th>
                    <th>{{ labels.staffBookableRate }}</th>
                </tr></thead>
                <tbody>
                    <tr v-for="row in report.daily_rows" :key="`${row.staff_id}-${row.business_date}`">
                        <td>{{ row.business_date }}</td><td>{{ row.staff_name }}</td><td>{{ row.employment_type_name ?? labels.staffUnknown }}</td>
                        <td>{{ count(row.occupied_minutes) }}</td><td>{{ count(row.patient_count) }}</td>
                        <td>{{ count(row.working_minutes) }} <small>({{ source(row) }})</small></td>
                        <td>{{ count(row.future_reservation_count) }}</td><td>{{ count(row.nomination_count) }}</td>
                        <td>{{ rate(row.reservation_rate) }}</td><td>{{ rate(row.nomination_rate) }}</td>
                        <td>{{ rate(row.legacy_utilization_rate) }}</td><td>{{ count(row.bookable_minutes) }}</td>
                        <td>{{ rate(row.bookable_utilization_rate) }}</td>
                    </tr>
                    <tr v-if="report.daily_rows.length === 0"><td colspan="13">{{ labels.staffNoData }}</td></tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>

<style scoped>
.table-scroll { overflow-x: auto; }
table { border-collapse: collapse; min-width: 1100px; width: 100%; }
th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: right; white-space: nowrap; }
th:first-child, td:first-child { text-align: left; }
select, input { margin-left: 8px; padding: 6px; border: 1px solid #aaa; border-radius: 4px; }
</style>
