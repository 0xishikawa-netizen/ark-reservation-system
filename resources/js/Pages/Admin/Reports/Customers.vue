<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { DateField, EmptyValue, MonthField, PageHeader } from '@/components/ark';
import { MonthlyReportTabs, ReportFilterBar, ReportFilterField, ReportKpi, ReportTable, ReportValue } from '@/components/reports';
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
    cross_tabs?: { motivation: CrossRow[]; first_staff: CrossRow[] };
}
interface CrossRow { value: string | null; label: string | null; new_customers: number; reached_2: number; reached_2_rate: number | null }
interface BreakdownSection { key: string; title: string; icon: string; dimensions: Dimension[] }

const props = defineProps<{ report: CustomerReport; dataEndpoint: string; monthlyReportUrl: string }>();
const report = ref<CustomerReport>(props.report);
const selectedMonth = ref(props.report.cohort_month);
const selectedAsOf = ref(props.report.as_of_date);
const loading = ref(false);
const error = ref<string | null>(null);
const labels = MESSAGES.reporting;
const thresholds = ['2', '6', '10'] as const;
const reachLabel = { '2': labels.customerReach2, '6': labels.customerReach6, '10': labels.customerReach10 } as const;

// 属性は「誰が来たか・その後どうなったか → 初回に何をしたか → どこから来たか・どこに住むか」の順に2列で並べる。
const sections: BreakdownSection[] = [
    { key: 'profile', title: labels.customerSectionProfile, icon: 'mdi-account-outline', dimensions: ['gender', 'age_decade', 'age_at_first_visit'] },
    { key: 'reach', title: labels.customerSectionReach, icon: 'mdi-stairs-up', dimensions: ['reached_2', 'reached_6', 'reached_10'] },
    { key: 'first', title: labels.customerSectionFirstVisit, icon: 'mdi-clipboard-account-outline', dimensions: ['course', 'first_staff', 'future_reservation', 'visit_purpose'] },
    { key: 'acquisition', title: labels.customerSectionAcquisition, icon: 'mdi-bullhorn-outline', dimensions: ['motivation', 'referrer'] },
    { key: 'region', title: labels.customerSectionRegion, icon: 'mdi-map-marker-outline', dimensions: ['prefecture', 'municipality'] },
];
const booleanDimensions: Dimension[] = ['future_reservation', 'reached_2', 'reached_6', 'reached_10', 'referrer'];
/** 複数選択の属性は人数の合計が新規人数を超えるため合計を出さない。 */
const multiSelect = (dimension: Dimension): boolean => report.value.breakdowns[dimension].basis === 'first_visit_karte_snapshot_multi_select';
const crossTables = [
    { key: 'motivation' as const, title: labels.customerCrossMotivation },
    { key: 'first_staff' as const, title: labels.customerCrossFirstStaff },
];

const bucketLabel = (dimension: Dimension, bucket: Bucket): string => {
    if (bucket.value === null) return labels.customerUnknown;
    if (booleanDimensions.includes(dimension)) {
        return bucket.value === 'true' ? labels.customerTrue : labels.customerFalse;
    }
    return bucket.label ?? bucket.value;
};
/** 内訳の横棒の長さ（その属性内で最大の件数を100%とした見た目の比率。数値としては表示しない）。 */
const barWidth = (dimension: Dimension, bucket: Bucket): string => {
    const max = Math.max(...report.value.breakdowns[dimension].buckets.map((item) => item.count), 0);
    return max > 0 ? `${(bucket.count / max) * 100}%` : '0%';
};
const bucketTotal = (dimension: Dimension): number => report.value.breakdowns[dimension].buckets.reduce((sum, bucket) => sum + bucket.count, 0);

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

function changeMonth(value: string): void {
    if (value === selectedMonth.value) return;
    selectedMonth.value = value;
    void loadReport();
}

function changeAsOf(value: string): void {
    if (!value || value === selectedAsOf.value) return;
    selectedAsOf.value = value;
    void loadReport();
}
</script>

<template>
    <Head :title="labels.customerTitle" />
    <PageHeader :title="labels.customerTitle" :subtitle="labels.customerSubtitle">
        <template #actions>
            <v-btn :href="monthlyReportUrl" variant="outlined" color="primary" prepend-icon="mdi-arrow-left" data-testid="back-to-monthly">
                {{ labels.customerBackToMonthly }}
            </v-btn>
        </template>
    </PageHeader>
    <MonthlyReportTabs active="customers" :month="selectedMonth" />

    <ReportFilterBar :loading="loading" :loading-text="labels.customerLoading" :error="error">
        <ReportFilterField size="md">
            <MonthField :model-value="selectedMonth" :label="labels.customerMonth" data-testid="month-input" @update:model-value="changeMonth" />
        </ReportFilterField>
        <ReportFilterField size="md">
            <DateField :model-value="selectedAsOf" :label="labels.customerAsOf" :clearable="false" data-testid="as-of-input" @update:model-value="changeAsOf" />
        </ReportFilterField>
        <template #meta>{{ labels.customerCohortMeta }} {{ report.cohort_month }} / {{ labels.customerAsOf }} {{ report.as_of_date }}</template>
    </ReportFilterBar>

    <div class="report-kpi-grid customer-kpis" :aria-busy="loading">
        <ReportKpi :label="labels.customerNew" emphasis>
            <span data-testid="new-count"><ReportValue :value="report.new_customers" /></span><small>人</small>
        </ReportKpi>
        <ReportKpi :label="labels.customerReturning">
            <span data-testid="returning-count"><ReportValue :value="report.returning_customers" /></span><small>人</small>
        </ReportKpi>
        <ReportKpi :label="labels.customerChurn">
            <span data-testid="churn-count"><ReportValue :value="report.churn_customers" :empty-label="MESSAGES.common.notCalculated" /></span><small v-if="report.churn_customers !== null">人</small>
        </ReportKpi>
        <ReportKpi v-for="threshold in thresholds" :key="threshold" :label="reachLabel[threshold]">
            <span :data-testid="`reach-${threshold}`"><ReportValue :value="report.reach[threshold].rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></span>
            <template #caption>{{ report.reach[threshold].numerator }} / {{ report.reach[threshold].denominator }}人</template>
        </ReportKpi>
    </div>
    <p class="progress-note"><v-icon icon="mdi-information-outline" size="15" />{{ labels.customerProgressNote }}</p>

    <h2 class="breakdown-heading">{{ labels.customerBreakdown }}</h2>
    <div class="breakdown-layout" :aria-busy="loading">
        <section v-for="section in sections" :key="section.key" class="breakdown-section" :class="`breakdown-section--${section.key}`">
            <header class="breakdown-section__head">
                <v-icon :icon="section.icon" size="18" />
                <h3>{{ section.title }}</h3>
            </header>
            <div class="breakdown-section__body">
                <article v-for="dimension in section.dimensions" :key="dimension" class="breakdown" :data-testid="`breakdown-${dimension}`">
                    <div class="breakdown__head">
                        <h4>{{ labels.customerDimension[dimension] }}</h4>
                        <span v-if="report.breakdowns[dimension].status === 'available' && multiSelect(dimension)" class="breakdown__total">{{ labels.customerMultiSelect }}</span>
                        <span v-else-if="report.breakdowns[dimension].status === 'available'" class="breakdown__total">{{ bucketTotal(dimension) }}人</span>
                    </div>
                    <p v-if="report.breakdowns[dimension].status === 'not_captured'" class="breakdown__empty">
                        <EmptyValue :label="labels.customerNotCaptured" />
                    </p>
                    <p v-else-if="report.breakdowns[dimension].buckets.length === 0" class="breakdown__empty">{{ labels.customerNoData }}</p>
                    <ul v-else class="breakdown__list">
                        <li v-for="bucket in report.breakdowns[dimension].buckets" :key="bucket.value ?? 'unknown'" :class="{ 'is-unknown': bucket.value === null }">
                            <span class="breakdown__label" :title="bucketLabel(dimension, bucket)">{{ bucketLabel(dimension, bucket) }}</span>
                            <span class="breakdown__bar" aria-hidden="true"><span :style="{ width: barWidth(dimension, bucket) }" /></span>
                            <strong><ReportValue :value="bucket.count" /></strong>
                        </li>
                    </ul>
                </article>
            </div>
        </section>
    </div>

    <h2 class="breakdown-heading">{{ labels.customerCrossHeading }}</h2>
    <div class="cross-layout">
        <section v-for="table in crossTables" :key="table.key" class="breakdown-section" :data-testid="`cross-${table.key}`">
            <header class="breakdown-section__head"><h3>{{ table.title }}</h3></header>
            <ReportTable max-height="none" min-width="420px">
                <thead><tr><th>{{ table.title }}</th><th class="num">{{ labels.customerCrossNew }}</th><th class="num">{{ labels.customerCrossReached2 }}</th><th class="num">{{ labels.customerCrossRate }}</th></tr></thead>
                <tbody>
                    <tr v-for="row in report.cross_tabs?.[table.key] ?? []" :key="row.value ?? 'unknown'" :class="{ 'row-muted': row.value === null }">
                        <td>{{ row.value === null ? labels.customerUnknown : (row.label ?? row.value) }}</td>
                        <td class="num"><ReportValue :value="row.new_customers" /></td>
                        <td class="num"><ReportValue :value="row.reached_2" /></td>
                        <td class="num"><ReportValue :value="row.reached_2_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></td>
                    </tr>
                    <tr v-if="(report.cross_tabs?.[table.key] ?? []).length === 0"><td colspan="4" class="empty-cell">{{ labels.customerNoData }}</td></tr>
                </tbody>
            </ReportTable>
        </section>
    </div>
</template>

<style scoped>
.cross-layout { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 959px) { .cross-layout { grid-template-columns: 1fr; } }
.customer-kpis {
    grid-template-columns: repeat(6, minmax(0, 1fr));
    margin-bottom: var(--ark-space-2);
}

.progress-note {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 var(--ark-space-5);
    color: rgba(var(--v-theme-on-surface), 0.6);
    font-size: 0.8125rem;
}

.breakdown-heading {
    margin: 0 0 var(--ark-space-3);
    font-size: 1rem;
}

.breakdown-layout {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--ark-space-4);
}

.breakdown-section {
    min-width: 0;
    border: 1px solid #e3e7ee;
    border-radius: var(--ark-radius-lg);
    background: rgb(var(--v-theme-surface));
    box-shadow: var(--ark-shadow-1);
}

/* 初回来店は項目数が多いので横幅いっぱいに置く */
.breakdown-section--first {
    grid-column: 1 / -1;
}

.breakdown-section__head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: var(--ark-space-3) var(--ark-space-4);
    border-bottom: 1px solid #edf0f4;
    color: rgb(var(--v-theme-primary));
}

.breakdown-section__head h3 {
    margin: 0;
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.9375rem;
}

.breakdown-section__body {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: var(--ark-space-4) var(--ark-space-5);
    padding: var(--ark-space-4);
}

.breakdown {
    min-width: 0;
}

.breakdown__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
}

.breakdown__head h4 {
    margin: 0;
    color: rgba(var(--v-theme-on-surface), 0.75);
    font-size: 0.8125rem;
    font-weight: 700;
}

.breakdown__total {
    color: rgba(var(--v-theme-on-surface), 0.5);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
}

.breakdown__empty {
    margin: 0;
    padding: 6px 0;
    color: rgba(var(--v-theme-on-surface), 0.55);
    font-size: 0.8125rem;
}

.breakdown__list {
    max-height: 232px;
    margin: 0;
    padding: 0;
    overflow-y: auto;
    list-style: none;
}

.breakdown__list li {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 72px 40px;
    align-items: center;
    gap: 10px;
    padding: 4px 0;
    border-bottom: 1px dashed #eef1f5;
    font-size: 0.8125rem;
}

.breakdown__list li:last-child {
    border-bottom: 0;
}

.breakdown__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.breakdown__list li.is-unknown .breakdown__label {
    color: rgba(var(--v-theme-on-surface), 0.5);
}

.breakdown__bar {
    height: 6px;
    border-radius: 999px;
    background: #eef1f6;
    overflow: hidden;
}

.breakdown__bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: rgba(var(--v-theme-primary), 0.55);
}

.breakdown__list strong {
    font-variant-numeric: tabular-nums;
    text-align: right;
}

@media (max-width: 1279px) {
    .customer-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

@media (max-width: 959px) {
    .breakdown-layout { grid-template-columns: 1fr; }
}

@media (max-width: 599px) {
    .customer-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
