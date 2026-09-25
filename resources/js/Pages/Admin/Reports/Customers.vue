<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type Dimension = keyof typeof MESSAGES.reporting.customerDimension;
interface Bucket { value: string | null; label: string | null; count: number }
interface Breakdown { status: 'available' | 'not_captured'; basis: string | null; buckets: Bucket[] }
interface Reach { numerator: number; denominator: number; rate: number | null }
interface CustomerReport {
    year: number; month: number; cohort_month: string; as_of_date: string;
    new_customers: number; returning_customers: number; churn_customers: number | null;
    reach: Record<'2' | '6' | '10', Reach>;
    breakdowns: Record<Dimension, Breakdown>;
}

const props = defineProps<{ report: CustomerReport; dataEndpoint: string; monthlyReportUrl: string }>();
const report = ref<CustomerReport>(props.report);
const selectedMonth = ref(props.report.cohort_month);
const selectedAsOf = ref(props.report.as_of_date);
const loading = ref(false);
const error = ref<string | null>(null);
const labels = MESSAGES.reporting;
const displayedDimensions: Dimension[] = [
    'course', 'visit_purpose', 'gender', 'age_at_first_visit', 'age_decade',
    'motivation', 'referrer', 'prefecture', 'municipality', 'first_staff',
    'future_reservation', 'reached_2', 'reached_6', 'reached_10',
];
const count = (value: number | null): string => value === null ? '—' : new Intl.NumberFormat('ja-JP').format(value);
const percent = (value: number | null): string => value === null ? '—' : `${(value * 100).toFixed(1)}%`;
const bucketLabel = (dimension: Dimension, bucket: Bucket): string => {
    if (bucket.value === null) return labels.customerUnknown;
    if (['future_reservation', 'reached_2', 'reached_6', 'reached_10'].includes(dimension)) {
        return bucket.value === 'true' ? labels.customerTrue : labels.customerFalse;
    }
    return bucket.label ?? bucket.value;
};

async function loadReport(): Promise<void> {
    const [year, month] = selectedMonth.value.split('-').map(Number);
    if (!year || !month || !selectedAsOf.value) return;
    loading.value = true;
    error.value = null;
    try {
        const query = new URLSearchParams({ year: String(year), month: String(month), as_of_date: selectedAsOf.value });
        const response = await fetch(`${props.dataEndpoint}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json() as { data: CustomerReport };
        report.value = payload.data;
    } catch {
        error.value = labels.customerLoadFailed;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head :title="labels.customerTitle" />
    <PageHeader :title="labels.customerTitle" :subtitle="labels.customerSubtitle" />
    <p class="report-link"><a :href="monthlyReportUrl">{{ labels.customerBackToMonthly }}</a></p>
    <SectionCard :title="labels.customerConditions" class="mb-5">
        <div class="report-controls">
            <label>{{ labels.customerMonth }}<input v-model="selectedMonth" data-testid="month-input" type="month" @change="loadReport"></label>
            <label>{{ labels.customerAsOf }}<input v-model="selectedAsOf" data-testid="as-of-input" type="date" @change="loadReport"></label>
        </div>
        <p v-if="loading" role="status">{{ labels.customerLoading }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
    </SectionCard>
    <div class="kpi-grid mb-5" :aria-busy="loading">
        <SectionCard :title="labels.customerNew"><strong data-testid="new-count">{{ count(report.new_customers) }}</strong></SectionCard>
        <SectionCard :title="labels.customerReturning"><strong data-testid="returning-count">{{ count(report.returning_customers) }}</strong></SectionCard>
        <SectionCard :title="labels.customerChurn"><strong data-testid="churn-count">{{ count(report.churn_customers) }}</strong></SectionCard>
        <SectionCard v-for="threshold in (['2', '6', '10'] as const)" :key="threshold"
            :title="threshold === '2' ? labels.customerReach2 : threshold === '6' ? labels.customerReach6 : labels.customerReach10">
            <strong :data-testid="`reach-${threshold}`">{{ percent(report.reach[threshold].rate) }}</strong>
            <small>{{ count(report.reach[threshold].numerator) }} / {{ count(report.reach[threshold].denominator) }}</small>
        </SectionCard>
    </div>
    <p class="progress-note">{{ labels.customerProgressNote }} {{ report.cohort_month }} / {{ report.as_of_date }}</p>
    <SectionCard :title="labels.customerBreakdown">
        <div class="breakdown-grid">
            <section v-for="dimension in displayedDimensions" :key="dimension" class="breakdown" :data-testid="`breakdown-${dimension}`">
                <h3>{{ labels.customerDimension[dimension] }}</h3>
                <p v-if="report.breakdowns[dimension].status === 'not_captured'">
                    {{ labels.customerNotCaptured }}（{{ labels.customerUnknown }} {{ count(report.breakdowns[dimension].buckets.find((bucket) => bucket.value === null)?.count ?? 0) }}）
                </p>
                <p v-else-if="report.breakdowns[dimension].buckets.length === 0">{{ labels.customerNoData }}</p>
                <ul v-else>
                    <li v-for="bucket in report.breakdowns[dimension].buckets" :key="bucket.value ?? 'unknown'">
                        <span>{{ bucketLabel(dimension, bucket) }}</span><strong>{{ count(bucket.count) }}</strong>
                    </li>
                </ul>
            </section>
        </div>
    </SectionCard>
</template>

<style scoped>
.report-link { margin: 0 0 16px; }.report-controls { display: flex; flex-wrap: wrap; gap: 16px; }
.report-controls label { display: grid; gap: 6px; color: #495365; font-size: 13px; font-weight: 700; }
.report-controls input { border: 1px solid #cbd2dc; border-radius: 8px; padding: 9px 12px; font: inherit; }
.kpi-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.kpi-grid strong { display: block; color: #15233d; font-size: 23px; }.kpi-grid small { color: #667085; }
.progress-note { color: #526275; font-size: 13px; }.breakdown-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
.breakdown { border-top: 1px solid #e6e9ee; padding-top: 12px; }.breakdown h3 { font-size: 15px; margin: 0 0 10px; }
.breakdown ul { margin: 0; padding: 0; list-style: none; }.breakdown li { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; }
@media (max-width: 800px) { .kpi-grid, .breakdown-grid { grid-template-columns: 1fr; } }
</style>
