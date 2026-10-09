<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { MonthlyReportTabs, ReportFilterBar, ReportFilterField, ReportKpi, ReportSelect, ReportTable, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

type SalesBasis = 'payment_date' | 'treatment_date';
interface Rates { utilization_rate: number | null; bookable_rate: number | null }
interface AttentionItem { label: string; date: string; url: string }
interface Report {
    month_key: string; sales_basis: SalesBasis; as_of_date: string | null;
    sales: { selected_revenue: number; gross: number; net: number; tax: number; treatment_gross: number; retail_gross: number;
        target: number | null; achievement_rate: number | null; visit_gross: number; store_gross: number; average_per_visit: number | null };
    visits: { visit_count: number; long_visit_count: number; first_visit_count: number; new_customers: number; returning_customers: number;
        churn_customers: number | null; next_reservation_rate: number | null };
    retention: { reach_2: number | null; reach_6: number | null; reach_10: number | null };
    utilization: Rates & { weekday: Rates; weekend: Rates };
    payment_methods: { code: string; name: string; amount: number }[];
    attention: { code: string; count: number; items: AttentionItem[] }[];
}

const props = defineProps<{ report: Report }>();
const hub = MESSAGES.monthlyHub;
const labels = MESSAGES.reporting;
const M = MESSAGES.reportsUi.overview;
const basisItems: { title: string; value: SalesBasis }[] = [
    { title: labels.annualPaymentBasis, value: 'payment_date' },
    { title: labels.annualTreatmentBasis, value: 'treatment_date' },
];
const methodName = (code: string, name: string): string => labels.paymentMethodHeadings[code] ?? name;

function reload(month: string, basis: SalesBasis): void {
    const [year, m] = month.split('-').map(Number);
    router.get('/admin/reports/overview', { year, month: m, basis }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="hub.tabs.overview" />
    <PageHeader :title="hub.title" :subtitle="hub.overviewSubtitle" />
    <MonthlyReportTabs active="overview" :month="report.month_key" :basis="report.sales_basis" />
    <ReportFilterBar>
        <ReportFilterField size="md"><MonthField :model-value="report.month_key" :label="MESSAGES.calendar.targetMonth" data-testid="month-input" @update:model-value="(v: string) => reload(v, report.sales_basis)" /></ReportFilterField>
        <ReportFilterField size="md"><ReportSelect :model-value="report.sales_basis" :items="basisItems" :label="labels.annualBasis" @update:model-value="(v: SalesBasis) => reload(report.month_key, v)" /></ReportFilterField>
    </ReportFilterBar>

    <h3 class="group-title">{{ hub.salesHeading }}</h3>
    <div class="report-kpi-grid">
        <ReportKpi :label="hub.grossSales" emphasis><ReportValue :value="report.sales.gross" format="money" /><template #caption>{{ hub.netSales }} <ReportValue :value="report.sales.net" format="money" /> ／ {{ hub.tax }} <ReportValue :value="report.sales.tax" format="money" /></template></ReportKpi>
        <ReportKpi :label="hub.target"><ReportValue :value="report.sales.target" format="money" :empty-label="MESSAGES.common.notSet" /><template #caption>{{ hub.achievement }} <ReportValue :value="report.sales.achievement_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></template></ReportKpi>
        <ReportKpi :label="hub.treatmentSales"><ReportValue :value="report.sales.treatment_gross" format="money" /></ReportKpi>
        <ReportKpi :label="hub.retailSales"><ReportValue :value="report.sales.retail_gross" format="money" /></ReportKpi>
        <ReportKpi :label="hub.perVisit" data-testid="per-visit"><ReportValue :value="report.sales.average_per_visit" format="money" :empty-label="MESSAGES.common.notCalculated" /><template #caption>{{ hub.storeSales }} <ReportValue :value="report.sales.store_gross" format="money" /></template></ReportKpi>
    </div>
    <p class="note">{{ hub.perVisitHint }}</p>

    <h3 class="group-title">{{ hub.visitsHeading }}</h3>
    <div class="report-kpi-grid">
        <ReportKpi :label="hub.visits" emphasis><ReportValue :value="report.visits.visit_count" /></ReportKpi>
        <ReportKpi :label="hub.newCustomers"><ReportValue :value="report.visits.new_customers" /></ReportKpi>
        <ReportKpi :label="hub.returning"><ReportValue :value="report.visits.returning_customers" /></ReportKpi>
        <ReportKpi :label="hub.churn"><ReportValue :value="report.visits.churn_customers" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
        <ReportKpi :label="hub.long"><ReportValue :value="report.visits.long_visit_count" /></ReportKpi>
        <ReportKpi :label="hub.firstVisits"><ReportValue :value="report.visits.first_visit_count" /></ReportKpi>
        <ReportKpi :label="hub.nextReservation"><ReportValue :value="report.visits.next_reservation_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
    </div>

    <h3 class="group-title">{{ hub.retentionHeading }}・{{ hub.utilizationHeading }}</h3>
    <div class="report-kpi-grid">
        <ReportKpi :label="hub.reach2"><ReportValue :value="report.retention.reach_2" format="percent" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
        <ReportKpi :label="hub.reach6"><ReportValue :value="report.retention.reach_6" format="percent" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
        <ReportKpi :label="hub.reach10"><ReportValue :value="report.retention.reach_10" format="percent" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
        <ReportKpi :label="hub.utilization"><ReportValue :value="report.utilization.utilization_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /><template #caption>{{ hub.bookable }} <ReportValue :value="report.utilization.bookable_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></template></ReportKpi>
        <ReportKpi :label="fillMessage(M.dayTypeBookable, { weekday: hub.weekday, weekend: hub.weekend, bookable: hub.bookable })"><ReportValue :value="report.utilization.weekday.bookable_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /> ／ <ReportValue :value="report.utilization.weekend.bookable_rate" format="percent" :empty-label="MESSAGES.common.notCalculated" /></ReportKpi>
    </div>

    <div class="overview-grid">
        <SectionCard :title="hub.paymentsHeading">
            <ReportTable max-height="none" min-width="0" data-testid="payment-mix">
                <tbody>
                    <tr v-for="method in report.payment_methods" :key="method.code">
                        <th>{{ methodName(method.code, method.name) }}</th>
                        <td class="num"><ReportValue :value="method.amount" format="money" /></td>
                        <td class="num"><ReportValue :value="report.sales.selected_revenue > 0 ? method.amount / report.sales.selected_revenue : null" format="percent" /></td>
                    </tr>
                    <tr v-if="report.payment_methods.length === 0"><td colspan="3" class="empty-cell">{{ hub.noPayments }}</td></tr>
                </tbody>
            </ReportTable>
        </SectionCard>

        <SectionCard :title="hub.attentionHeading" data-testid="attention">
            <p v-if="report.attention.every((a) => a.count === 0)" class="note">{{ hub.attentionNone }}</p>
            <details v-for="item in report.attention.filter((a) => a.count > 0)" :key="item.code" class="attention" :data-code="item.code">
                <summary><span>{{ hub.attention[item.code] }}</span><strong>{{ item.count }}</strong></summary>
                <ul>
                    <li v-for="(entry, index) in item.items" :key="index"><a :href="entry.url">{{ entry.date }} {{ entry.label }}</a></li>
                </ul>
            </details>
        </SectionCard>
    </div>
</template>

<style scoped>
.group-title { margin: 8px 0 8px; font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), 0.72); }
.note { color: #6b7785; font-size: 0.8125rem; margin: 4px 0 16px; }
.overview-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; margin-top: 8px; }
@media (max-width: 1100px) { .overview-grid { grid-template-columns: minmax(0, 1fr); } }
.attention { border-bottom: 1px solid #edf0f4; padding: 6px 0; }
.attention summary { display: flex; justify-content: space-between; cursor: pointer; font-size: 0.875rem; }
.attention summary strong { color: #b45309; }
.attention ul { margin: 6px 0 0 16px; font-size: 0.8125rem; }
</style>
