<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { MonthlyReportTabs, ReportFilterBar, ReportFilterField, ReportKpi, ReportTable, ReportText, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Group { key: string; total: number; completed: number; canceled: number; no_show: number }
interface Report {
    month_key: string;
    totals: { reservation_count: number; completed: number; canceled: number; no_show: number; upcoming: number;
        cancel_rate: number | null; no_show_rate: number | null; next_reservation_rate: number | null };
    by_source: Group[]; by_weekday: Group[]; by_hour: Group[]; by_staff: Group[];
    lead_time: { buckets: Record<string, number>; unknown: number };
}

defineProps<{ report: Report }>();
const hub = MESSAGES.monthlyHub;
const sections: { key: 'by_source' | 'by_weekday' | 'by_hour' | 'by_staff'; title: string; label: (key: string) => string | null }[] = [
    { key: 'by_source', title: hub.bySource, label: (key) => hub.sources[key] ?? key },
    { key: 'by_weekday', title: hub.byWeekday, label: (key) => hub.weekdays[Number(key)] ?? key },
    { key: 'by_hour', title: hub.byHour, label: (key) => `${key}:00` },
    { key: 'by_staff', title: hub.byStaff, label: (key) => (key === '' ? hub.unknownStaff : key) },
];
const rate = (numerator: number, denominator: number): number | null => (denominator === 0 ? null : numerator / denominator);

function reload(month: string): void {
    const [year, m] = month.split('-').map(Number);
    router.get('/admin/reports/reservation-analysis', { year, month: m }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="hub.tabs.reservations" />
    <PageHeader :title="hub.title" :subtitle="hub.reservationSubtitle" />
    <MonthlyReportTabs active="reservations" :month="report.month_key" />
    <ReportFilterBar>
        <ReportFilterField size="md"><MonthField :model-value="report.month_key" :label="MESSAGES.calendar.targetMonth" data-testid="month-input" @update:model-value="reload" /></ReportFilterField>
    </ReportFilterBar>

    <div class="report-kpi-grid">
        <ReportKpi :label="hub.reservationCount" emphasis><ReportValue :value="report.totals.reservation_count" /></ReportKpi>
        <ReportKpi :label="hub.completed"><ReportValue :value="report.totals.completed" /></ReportKpi>
        <ReportKpi :label="hub.upcoming"><ReportValue :value="report.totals.upcoming" /></ReportKpi>
        <ReportKpi :label="hub.cancelRate"><ReportValue :value="report.totals.cancel_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /><template #caption>{{ hub.canceled }} {{ report.totals.canceled }}</template></ReportKpi>
        <ReportKpi :label="hub.noShowRate"><ReportValue :value="report.totals.no_show_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /><template #caption>{{ hub.noShow }} {{ report.totals.no_show }}</template></ReportKpi>
        <ReportKpi :label="hub.nextReservation"><ReportValue :value="report.totals.next_reservation_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
    </div>
    <p class="note">{{ hub.rateHint }}</p>

    <div class="analysis-grid">
        <SectionCard v-for="section in sections" :key="section.key" :title="section.title">
            <ReportTable max-height="none" min-width="0" :data-testid="`analysis-${section.key}`">
                <thead><tr><th></th><th class="num">{{ hub.count }}</th><th class="num">{{ hub.completed }}</th><th class="num">{{ hub.canceled }}</th><th class="num">{{ hub.noShow }}</th><th class="num">{{ hub.cancelRate }}</th></tr></thead>
                <tbody>
                    <tr v-for="row in report[section.key]" :key="row.key">
                        <th><ReportText :value="section.label(row.key)" /></th>
                        <td class="num"><ReportValue :value="row.total" /></td>
                        <td class="num"><ReportValue :value="row.completed" /></td>
                        <td class="num"><ReportValue :value="row.canceled" /></td>
                        <td class="num"><ReportValue :value="row.no_show" /></td>
                        <td class="num"><ReportValue :value="rate(row.canceled, row.total)" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    </tr>
                    <tr v-if="report[section.key].length === 0"><td colspan="6" class="empty-cell"><ReportText :value="null" /></td></tr>
                </tbody>
            </ReportTable>
        </SectionCard>
        <SectionCard :title="hub.leadTime">
            <ReportTable max-height="none" min-width="0" data-testid="analysis-lead-time">
                <tbody>
                    <tr v-for="(count, key) in report.lead_time.buckets" :key="key">
                        <th>{{ hub.leadBuckets[key] ?? key }}</th>
                        <td class="num"><ReportValue :value="count" /></td>
                        <td class="num"><ReportValue :value="rate(count, report.totals.reservation_count)" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    </tr>
                </tbody>
            </ReportTable>
        </SectionCard>
    </div>
</template>

<style scoped>
.note { color: #6b7785; font-size: 0.8125rem; margin: 4px 0 16px; }
.analysis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; }
</style>
