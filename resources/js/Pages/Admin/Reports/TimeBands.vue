<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Row {
    business_date?: string; staff_id: number | null; staff_name: string | null;
    band_code: string; band_label: string; occupied_minutes: number | null;
    known_occupied_minutes: number; occupied_unknown_count: number;
    working_minutes: number | null; bookable_minutes: number;
    legacy_utilization_rate: number | null; bookable_utilization_rate: number | null;
}
interface Report {
    month_key: string; as_of_date: string; staff: { id: number; name: string }[];
    outside_band_minutes: number; daily_rows: Row[]; monthly_rows: Row[]; overall_rows: Row[];
}

const props = defineProps<{ report: Report; dataEndpoint: string }>();
const labels = MESSAGES.reporting;
const report = ref(props.report);
const month = ref(props.report.month_key);
const staffId = ref<number | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const number = (value: number | null): string => value === null ? labels.staffUnknown : new Intl.NumberFormat('ja-JP').format(value);
const rate = (value: number | null): string => value === null ? '—' : `${(value * 100).toFixed(1)}%`;

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
</script>

<template>
    <Head :title="labels.bandTitle" />
    <PageHeader :title="labels.bandTitle" :subtitle="labels.bandSubtitle" />
    <SectionCard :title="labels.customerConditions" class="mb-5">
        <div class="d-flex flex-wrap ga-3 align-center">
            <label>{{ labels.staffMonth }} <input v-model="month" data-testid="month-input" type="month" @change="loadReport"></label>
            <label>{{ labels.staffName }}
                <select v-model="staffId" data-testid="staff-filter" @change="loadReport">
                    <option :value="null">{{ labels.staffAll }}</option>
                    <option v-for="staff in report.staff" :key="staff.id" :value="staff.id">{{ staff.name }}</option>
                </select>
            </label>
        </div>
        <p v-if="loading" role="status">{{ labels.bandLoading }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p class="text-caption mt-2">{{ labels.bandOutside }}: {{ number(report.outside_band_minutes) }} / {{ report.as_of_date }}</p>
    </SectionCard>
    <SectionCard :title="labels.bandOverall" class="mb-5">
        <div class="table-scroll"><table data-testid="overall-table">
            <thead><tr><th>{{ labels.bandTime }}</th><th>{{ labels.staffOccupied }}</th><th>{{ labels.bandUnknown }}</th><th>{{ labels.staffWorking }}</th><th>{{ labels.staffLegacyRate }}</th><th>{{ labels.staffBookable }}</th><th>{{ labels.staffBookableRate }}</th></tr></thead>
            <tbody><tr v-for="row in report.overall_rows" :key="row.band_code">
                <td>{{ row.band_label }}</td><td>{{ number(row.occupied_minutes) }}</td><td>{{ number(row.occupied_unknown_count) }}</td>
                <td>{{ number(row.working_minutes) }}</td><td>{{ rate(row.legacy_utilization_rate) }}</td>
                <td>{{ number(row.bookable_minutes) }}</td><td>{{ rate(row.bookable_utilization_rate) }}</td>
            </tr><tr v-if="report.overall_rows.length === 0"><td colspan="7">{{ labels.staffNoData }}</td></tr></tbody>
        </table></div>
    </SectionCard>
    <SectionCard :title="labels.staffMonthly" class="mb-5">
        <div class="table-scroll"><table data-testid="monthly-table">
            <thead><tr><th>{{ labels.staffName }}</th><th>{{ labels.bandTime }}</th><th>{{ labels.staffOccupied }}</th><th>{{ labels.bandUnknown }}</th><th>{{ labels.staffWorking }}</th><th>{{ labels.staffLegacyRate }}</th><th>{{ labels.staffBookable }}</th><th>{{ labels.staffBookableRate }}</th></tr></thead>
            <tbody><tr v-for="row in report.monthly_rows" :key="`${row.staff_id}-${row.band_code}`">
                <td>{{ row.staff_name }}</td><td>{{ row.band_label }}</td><td>{{ number(row.occupied_minutes) }}</td><td>{{ number(row.occupied_unknown_count) }}</td>
                <td>{{ number(row.working_minutes) }}</td><td>{{ rate(row.legacy_utilization_rate) }}</td>
                <td>{{ number(row.bookable_minutes) }}</td><td>{{ rate(row.bookable_utilization_rate) }}</td>
            </tr><tr v-if="report.monthly_rows.length === 0"><td colspan="8">{{ labels.staffNoData }}</td></tr></tbody>
        </table></div>
    </SectionCard>
    <SectionCard :title="labels.staffDaily">
        <div class="table-scroll"><table data-testid="daily-table">
            <thead><tr><th>{{ labels.staffDate }}</th><th>{{ labels.staffName }}</th><th>{{ labels.bandTime }}</th><th>{{ labels.staffOccupied }}</th><th>{{ labels.bandUnknown }}</th><th>{{ labels.staffWorking }}</th><th>{{ labels.staffBookable }}</th><th>{{ labels.staffBookableRate }}</th></tr></thead>
            <tbody><tr v-for="row in report.daily_rows" :key="`${row.business_date}-${row.staff_id}-${row.band_code}`">
                <td>{{ row.business_date }}</td><td>{{ row.staff_name }}</td><td>{{ row.band_label }}</td><td>{{ number(row.occupied_minutes) }}</td>
                <td>{{ number(row.occupied_unknown_count) }}</td><td>{{ number(row.working_minutes) }}</td>
                <td>{{ number(row.bookable_minutes) }}</td><td>{{ rate(row.bookable_utilization_rate) }}</td>
            </tr><tr v-if="report.daily_rows.length === 0"><td colspan="8">{{ labels.staffNoData }}</td></tr></tbody>
        </table></div>
    </SectionCard>
</template>

<style scoped>
.table-scroll { overflow-x: auto; }
table { border-collapse: collapse; min-width: 840px; width: 100%; }
th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: right; white-space: nowrap; }
th:first-child, td:first-child { text-align: left; }
select, input { margin-left: 8px; padding: 6px; border: 1px solid #aaa; border-radius: 4px; }
</style>
