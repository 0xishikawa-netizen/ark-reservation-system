<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MonthField, PageHeader, SectionCard } from '@/components/ark';
import { formatReportDate, MonthlyReportTabs, ReportFilterBar, ReportFilterField, ReportTable, ReportText, ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Line { type: string; name: string | null; quantity: number; gross: number; net: number; tax: number }
interface Sales { treatment_gross: number; retail_gross: number; retail_items: string[]; lines: Line[]; payments: { code: string; name: string; amount: number }[]; net: number; tax: number; gross: number }
interface VisitRow {
    kind: 'visit'; id: number; date: string; customer_name: string; member_no: string | null; reserved_menu: string | null;
    treatments: { name: string | null; category: string | null; minutes: number | null; booth: string | null }[];
    entitlement: string | null; primary_staff: string | null; actual_staff: string[]; staff_minutes: { name: string | null; minutes: number | null }[];
    nominated: string[]; nomination_known: boolean; next_reservation: boolean | null; is_new: boolean | null; gender: string | null;
    age_decade: number | null; channel: string | null; checkout_exemption: string | null; sales: Sales | null;
}
interface StoreRow { kind: 'store_sale'; id: number; date: string; sales: Sales }
interface Report {
    month_key: string; legacy_sheet_name: string; rows: VisitRow[]; store_sales: StoreRow[];
    totals: { visit_count: number; visit_gross: number; store_gross: number; treatment_gross: number; retail_gross: number; unsettled_visit_count: number };
}

defineProps<{ report: Report }>();
const hub = MESSAGES.monthlyHub;
const methodName = (code: string, name: string): string => MESSAGES.reporting.paymentMethodHeadings[code] ?? name;
const payments = (sales: Sales | null): string => (sales?.payments ?? []).map((p) => `${methodName(p.code, p.name)} ${p.amount.toLocaleString()}円`).join(' ／ ');
// 分類（M/T/A…）があれば「T30」のように、無ければ施術名と分数を出す（旧日計表のメニュー欄に合わせる）。
const treatmentText = (row: VisitRow): string => row.treatments
    .map((t) => (t.category ? `${t.category}${t.minutes ?? ''}` : `${t.name ?? ''}${t.minutes === null ? '' : ` ${t.minutes}分`}`).trim())
    .join(' + ');
const genderLabel = (value: string | null): string | null => hub.genders[value ?? ''] ?? null;

function reload(month: string): void {
    const [year, m] = month.split('-').map(Number);
    router.get('/admin/reports/daily-ledger', { year, month: m }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="hub.tabs.ledger" />
    <PageHeader :title="hub.title" :subtitle="hub.ledgerSubtitle" />
    <MonthlyReportTabs active="ledger" :month="report.month_key" />
    <ReportFilterBar>
        <ReportFilterField size="md"><MonthField :model-value="report.month_key" :label="MESSAGES.calendar.targetMonth" data-testid="month-input" @update:model-value="reload" /></ReportFilterField>
        <span class="legacy" data-testid="legacy-sheet">{{ hub.ledgerLegacy.replace('{sheet}', report.legacy_sheet_name) }}</span>
    </ReportFilterBar>

    <SectionCard :title="`${hub.ledgerVisits}（${report.totals.visit_count}件）`" class="mb-4">
        <ReportTable max-height="none" page-sticky-header min-width="1500px" sticky-width="72px" data-testid="ledger-visits">
            <thead><tr>
                <th class="is-sticky">{{ hub.colDate }}</th><th>{{ hub.colCustomer }}</th><th>{{ hub.colMenu }}</th><th>{{ hub.colTreatments }}</th>
                <th>{{ hub.colPrimary }}</th><th>{{ hub.colActual }}</th><th>{{ hub.colNominated }}</th><th>{{ hub.colRetail }}</th>
                <th class="num">{{ hub.colTreatmentSales }}</th><th class="num">{{ hub.colRetailSales }}</th><th>{{ hub.colPayments }}</th>
                <th>{{ hub.colNext }}</th><th>{{ hub.colNew }}</th><th>{{ hub.colGender }}</th><th>{{ hub.colAge }}</th><th>{{ hub.colChannel }}</th><th>{{ hub.details }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in report.rows" :key="row.id" :data-visit-id="row.id">
                    <th class="is-sticky">{{ formatReportDate(row.date) }}</th>
                    <td>{{ row.customer_name }}<small v-if="row.member_no" class="muted"> {{ row.member_no }}</small></td>
                    <td><ReportText :value="row.reserved_menu" /><small v-if="row.entitlement" class="muted">（{{ row.entitlement === 'ticket' ? hub.entitlementTicket : hub.entitlementMembership }}）</small></td>
                    <td><ReportText :value="treatmentText(row) || null" /></td>
                    <td><ReportText :value="row.primary_staff" /></td>
                    <td><ReportText :value="row.actual_staff.join('、') || null" /></td>
                    <td><ReportText :value="row.nomination_known ? (row.nominated.join('、') || hub.no) : null" /></td>
                    <td><ReportText :value="row.sales?.retail_items.join('、') || null" /></td>
                    <td class="num">
                        <template v-if="row.sales"><ReportValue :value="row.sales.treatment_gross" format="money" /></template>
                        <span v-else-if="row.checkout_exemption" class="muted">{{ hub.exempt.replace('{reason}', MESSAGES.visitCompletion.exemptionReasons[row.checkout_exemption] ?? row.checkout_exemption) }}</span>
                        <span v-else class="warn">{{ hub.unsettled }}</span>
                    </td>
                    <td class="num"><ReportValue :value="row.sales?.retail_gross ?? null" format="money" /></td>
                    <td><ReportText :value="payments(row.sales) || null" /></td>
                    <td><ReportText :value="row.next_reservation === null ? null : (row.next_reservation ? hub.yes : hub.no)" /></td>
                    <td><ReportText :value="row.is_new === null ? null : (row.is_new ? hub.newVisit : hub.repeatVisit)" /></td>
                    <td><ReportText :value="genderLabel(row.gender)" /></td>
                    <td><ReportText :value="row.age_decade === null ? null : hub.ageDecade.replace('{age}', String(row.age_decade))" /></td>
                    <td><ReportText :value="row.channel" /></td>
                    <td>
                        <details v-if="row.sales || row.staff_minutes.length">
                            <summary>{{ hub.details }}</summary>
                            <div class="detail">
                                <div v-for="(t, i) in row.treatments" :key="`t${i}`">{{ t.name }} {{ t.minutes }}分<template v-if="t.booth"> ／ {{ t.booth }}</template></div>
                                <div v-for="(s, i) in row.staff_minutes" :key="`s${i}`">{{ s.name }} {{ s.minutes }}分</div>
                                <template v-if="row.sales">
                                    <div v-for="(l, i) in row.sales.lines" :key="`l${i}`">{{ l.name }} ×{{ l.quantity }} {{ l.gross.toLocaleString() }}円</div>
                                    <div class="muted">{{ hub.netTax.replace('{net}', row.sales.net.toLocaleString()).replace('{tax}', row.sales.tax.toLocaleString()) }}</div>
                                </template>
                            </div>
                        </details>
                    </td>
                </tr>
                <tr v-if="report.rows.length === 0"><td colspan="17" class="empty-cell">{{ hub.ledgerNoRows }}</td></tr>
            </tbody>
            <tfoot><tr>
                <th class="is-sticky">{{ hub.total }}</th><td colspan="7" />
                <td class="num"><ReportValue :value="report.totals.visit_gross" format="money" /></td><td colspan="8"><small class="muted">{{ hub.totalsVisit }}</small></td>
            </tr></tfoot>
        </ReportTable>
    </SectionCard>

    <SectionCard :title="hub.ledgerStoreSales">
        <ReportTable max-height="none" min-width="760px" data-testid="ledger-store-sales">
            <thead><tr><th class="is-sticky">{{ hub.colDate }}</th><th>{{ hub.colRetail }}</th><th class="num">{{ hub.colTotal }}</th><th>{{ hub.colPayments }}</th></tr></thead>
            <tbody>
                <tr v-for="row in report.store_sales" :key="row.id">
                    <th class="is-sticky">{{ formatReportDate(row.date) }}</th>
                    <td>{{ row.sales.lines.map((l) => `${l.name}×${l.quantity}`).join('、') }}</td>
                    <td class="num"><ReportValue :value="row.sales.gross" format="money" /></td>
                    <td>{{ payments(row.sales) }}</td>
                </tr>
                <tr v-if="report.store_sales.length === 0"><td colspan="4" class="empty-cell">{{ hub.ledgerNoStoreSales }}</td></tr>
            </tbody>
            <tfoot><tr><th class="is-sticky">{{ hub.totalsStore }}</th><td /><td class="num"><ReportValue :value="report.totals.store_gross" format="money" /></td><td /></tr></tfoot>
        </ReportTable>
    </SectionCard>
</template>

<style scoped>
.legacy { align-self: center; font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), 0.6); }
.muted { color: rgba(var(--v-theme-on-surface), 0.55); }
.warn { color: #b45309; font-size: 0.75rem; }
.detail { font-size: 0.75rem; line-height: 1.5; white-space: normal; min-width: 220px; }
details summary { cursor: pointer; font-size: 0.75rem; color: rgb(var(--v-theme-primary)); }
</style>
