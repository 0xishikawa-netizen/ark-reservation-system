<script setup lang="ts">
import type { RequestPayload } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageHeader, SectionCard } from '@/components/ark';
import { allocateByWeight, splitInclusive } from '@/components/checkout/amounts';
import { ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type ItemType = 'service' | 'product' | 'ticket' | 'membership' | 'other';
interface Priced { id: number; name: string; price: number; tax_category_id: number | null }
interface ServiceMaster extends Priced { duration_min: number; requires_staff: boolean }
interface TaxCategoryMaster { id: number; name: string; rate_bps: number | null }
interface Option { id: number; name: string }
interface StaffRow { staff_id: number | null; actual_minutes: number; started_at: string | null }
interface TreatmentRow { service_id: number | null; actual_minutes: number; started_at: string | null; staff: StaffRow[] }
interface Allocation { staff_id: number; amount: number }
interface LineRow {
    item_type: ItemType; service_id: number | null; product_id: number | null; ticket_product_id: number | null;
    membership_plan_id: number | null; item_name: string; quantity: number; unit_amount: number;
    tax_category_id: number | null; treatment_index: number | null; is_staff_allocatable: boolean; allocations: Allocation[];
}
interface TenderRow { payment_method_id: number | null; amount: number; retail_amount: number | null; external?: boolean }
interface VisitProps {
    id: number; status: string; reservation_id: number | null; reservation_starts_at: string | null; business_date: string;
    primary_staff_id: number | null; nominations_recorded: boolean; nominated_staff_ids: number[];
    reservation_staff_requested: boolean | null; editable: boolean;
    treatments: (TreatmentRow & { service_name: string | null; category: string | null })[];
}
interface CheckoutProps { id: number; status: string; editable: boolean; void_reason: string | null; lines: LineRow[]; tenders: TenderRow[] }

const props = defineProps<{
    mode: 'visit' | 'sale';
    visit: VisitProps | null;
    checkout: CheckoutProps | null;
    customer: { id: number; name: string | null; member_no: string; url: string } | null;
    date: string;
    services: ServiceMaster[];
    products: Priced[];
    ticketProducts: Priced[];
    membershipPlans: Priced[];
    staff: Option[];
    taxCategories: TaxCategoryMaster[];
    paymentMethods: (Option & { code: string })[];
    canVoid: boolean;
    endpoints: { save: string; complete: string | null; finalize: string | null; void: string | null; index: string };
}>();

const labels = MESSAGES.checkout;
const visitEditable = computed(() => props.mode === 'visit' && (props.visit?.editable ?? false));
const checkoutEditable = computed(() => props.checkout === null || props.checkout.editable);

const primaryStaffId = ref<number | null>(props.visit?.primary_staff_id ?? null);
const nominated = ref<number[]>(
    props.visit?.nominations_recorded
        ? [...props.visit.nominated_staff_ids]
        : (props.visit?.reservation_staff_requested && props.visit.primary_staff_id ? [props.visit.primary_staff_id] : []),
);
const treatments = ref<TreatmentRow[]>((props.visit?.treatments ?? []).map((row) => ({
    service_id: row.service_id, actual_minutes: row.actual_minutes, started_at: row.started_at,
    staff: row.staff.map((staff) => ({ ...staff })),
})));
const lines = ref<LineRow[]>((props.checkout?.lines ?? []).map((line) => ({ ...line, allocations: line.allocations.map((a) => ({ ...a })) })));
const tenders = ref<TenderRow[]>((props.checkout?.tenders ?? []).map((tender) => ({ ...tender })));
const dirty = ref(false);
const busy = ref(false);
const voidOpen = ref(false);
const voidReason = ref('');
const touch = (): void => { dirty.value = true; };

const staffItems = computed(() => props.staff.map((s) => ({ title: s.name, value: s.id })));
const serviceItems = computed(() => props.services.map((s) => ({ title: s.name, value: s.id })));
const taxItems = computed(() => props.taxCategories.map((t) => ({ title: t.name, value: t.id })));
const methodItems = computed(() => props.paymentMethods.map((m) => ({ title: m.name, value: m.id })));
const treatmentItems = computed(() => treatments.value.map((row, index) => ({
    title: `${index + 1}. ${props.services.find((s) => s.id === row.service_id)?.name ?? labels.treatments}`,
    value: index,
})));
const typeLabel: Record<ItemType, string> = {
    service: labels.itemService, product: labels.itemProduct, ticket: labels.itemTicket, membership: labels.itemMembership, other: labels.itemOther,
};

function addTreatment(): void {
    const service = props.services[0];
    treatments.value.push({
        service_id: service?.id ?? null,
        actual_minutes: service?.duration_min ?? 60,
        started_at: props.visit?.reservation_starts_at ?? null,
        staff: primaryStaffId.value ? [{ staff_id: primaryStaffId.value, actual_minutes: service?.duration_min ?? 60, started_at: null }] : [],
    });
    touch();
}

function onTreatmentService(row: TreatmentRow, serviceId: number | null): void {
    row.service_id = serviceId;
    const service = props.services.find((s) => s.id === serviceId);
    if (service) {
        row.actual_minutes = service.duration_min;
        if (row.staff.length === 1) row.staff[0].actual_minutes = service.duration_min;
    }
    touch();
}

const staffMinutesOk = (row: TreatmentRow): boolean => row.staff.length === 0
    || row.staff.reduce((sum, s) => sum + Number(s.actual_minutes || 0), 0) === Number(row.actual_minutes);

function addLine(type: ItemType): void {
    const line: LineRow = {
        item_type: type, service_id: null, product_id: null, ticket_product_id: null, membership_plan_id: null,
        item_name: '', quantity: 1, unit_amount: 0, tax_category_id: props.taxCategories[0]?.id ?? null,
        treatment_index: null, is_staff_allocatable: false, allocations: [],
    };
    if (type === 'service') {
        const index = treatments.value.length > 0 ? 0 : null;
        const service = props.services.find((s) => s.id === treatments.value[0]?.service_id) ?? props.services[0];
        if (service) applyMaster(line, service);
        line.treatment_index = index;
        line.is_staff_allocatable = index !== null;
    }
    lines.value.push(line);
    touch();
}

function masterItems(type: ItemType): { title: string; value: number }[] {
    const list = type === 'service' ? props.services : type === 'product' ? props.products
        : type === 'ticket' ? props.ticketProducts : type === 'membership' ? props.membershipPlans : [];
    return list.map((m) => ({ title: `${m.name}（${m.price.toLocaleString()}円）`, value: m.id }));
}

function masterId(line: LineRow): number | null {
    return line.item_type === 'service' ? line.service_id : line.item_type === 'product' ? line.product_id
        : line.item_type === 'ticket' ? line.ticket_product_id : line.item_type === 'membership' ? line.membership_plan_id : null;
}

function applyMaster(line: LineRow, master: Priced): void {
    line.item_name = master.name;
    line.unit_amount = master.price;
    if (master.tax_category_id !== null) line.tax_category_id = master.tax_category_id;
}

function onMaster(line: LineRow, id: number | null): void {
    const list: Priced[] = line.item_type === 'service' ? props.services : line.item_type === 'product' ? props.products
        : line.item_type === 'ticket' ? props.ticketProducts : props.membershipPlans;
    const master = list.find((m) => m.id === id);
    if (line.item_type === 'service') line.service_id = id;
    if (line.item_type === 'product') line.product_id = id;
    if (line.item_type === 'ticket') line.ticket_product_id = id;
    if (line.item_type === 'membership') line.membership_plan_id = id;
    if (master) applyMaster(line, master);
    touch();
}

const rateOf = (taxCategoryId: number | null): number | null => props.taxCategories.find((t) => t.id === taxCategoryId)?.rate_bps ?? null;
const lineGross = (line: LineRow): number => Number(line.unit_amount || 0) * Number(line.quantity || 0);
const lineSplit = (line: LineRow) => splitInclusive(lineGross(line), rateOf(line.tax_category_id));
const allocationOk = (line: LineRow): boolean => !line.is_staff_allocatable
    || line.allocations.reduce((sum, a) => sum + Number(a.amount || 0), 0) === lineGross(line);

function allocateByMinutes(line: LineRow): void {
    const row = line.treatment_index === null ? null : treatments.value[line.treatment_index];
    if (!row || row.staff.length === 0) return;
    const staff = row.staff.filter((s) => s.staff_id !== null);
    const amounts = allocateByWeight(lineGross(line), staff.map((s) => Number(s.actual_minutes)));
    line.allocations = staff.map((s, index) => ({ staff_id: s.staff_id as number, amount: amounts[index] }));
    touch();
}

const retailGross = computed(() => lines.value.filter((l) => l.item_type === 'product').reduce((sum, l) => sum + lineGross(l), 0));
const treatmentGross = computed(() => lines.value.filter((l) => l.item_type !== 'product').reduce((sum, l) => sum + lineGross(l), 0));
/** 施術等と物販が混在し支払が複数の時だけ、各支払の物販分を明示入力する（自動按分しない）。 */
const needsRetailSplit = computed(() => retailGross.value > 0 && treatmentGross.value > 0 && tenders.value.length > 1);
const retailAllocated = computed(() => tenders.value.reduce((sum, t) => sum + Number(t.retail_amount ?? 0), 0));

const totals = computed(() => {
    let net = 0; let tax = 0; let gross = 0;
    for (const line of lines.value) {
        const split = lineSplit(line);
        if (split) { net += split.net; tax += split.tax; }
        gross += lineGross(line);
    }
    const paid = tenders.value.reduce((sum, t) => sum + Number(t.amount || 0), 0);
    return { net, tax, gross, paid, difference: gross - paid };
});

function payload(): RequestPayload {
    const body: Record<string, unknown> = {
        lines: lines.value.map((line) => ({ ...line, allocations: line.is_staff_allocatable ? line.allocations : [] })),
        tenders: tenders.value.filter((t) => !t.external).map((t) => ({
            payment_method_id: t.payment_method_id,
            amount: t.amount,
            retail_amount: needsRetailSplit.value ? Number(t.retail_amount ?? 0) : null,
        })),
    };
    if (visitEditable.value) {
        body.primary_staff_id = primaryStaffId.value;
        body.nominated_staff_ids = nominated.value;
        body.treatments = treatments.value;
    }
    return body as RequestPayload;
}

function save(then?: () => void): void {
    busy.value = true;
    router.put(props.endpoints.save, payload(), {
        preserveScroll: true,
        onSuccess: () => { dirty.value = false; then?.(); },
        onFinish: () => { busy.value = false; },
    });
}

function post(url: string | null, data: RequestPayload = {}): void {
    if (!url) return;
    busy.value = true;
    router.post(url, data, { preserveScroll: true, onFinish: () => { busy.value = false; } });
}

function complete(): void {
    const run = () => post(props.endpoints.complete);
    if (dirty.value && (visitEditable.value || checkoutEditable.value)) save(run); else run();
}

function finalize(): void {
    const run = () => post(props.endpoints.finalize);
    if (dirty.value && checkoutEditable.value) save(run); else run();
}

function submitVoid(): void {
    if (!voidReason.value.trim()) return;
    post(props.endpoints.void, { reason: voidReason.value });
    voidOpen.value = false;
}

const title = computed(() => (props.mode === 'sale' ? labels.saleTitle : labels.entryTitle));
const statusText = (status: string | undefined): string => ({
    draft: labels.statusDraft, completed: labels.statusCompleted, finalized: labels.statusFinalized, voided: labels.statusVoided,
}[status ?? ''] ?? labels.statusNone);
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :subtitle="`${labels.date} ${date}`">
        <template #actions>
            <v-btn variant="outlined" color="primary" prepend-icon="mdi-arrow-left" :href="endpoints.index">{{ labels.backToList }}</v-btn>
        </template>
    </PageHeader>

    <div class="entry-grid">
        <div class="entry-main">
            <SectionCard :title="labels.customer">
                <div class="meta-row">
                    <template v-if="customer"><a :href="customer.url">{{ customer.name }}</a><span class="muted">{{ customer.member_no }}</span></template>
                    <span v-else class="muted">{{ labels.anonymous }}</span>
                    <v-chip v-if="visit" size="small" variant="tonal">{{ labels.visitStatus }}: {{ statusText(visit.status) }}</v-chip>
                    <v-chip v-if="visit" size="small" variant="tonal">{{ visit.reservation_id ? labels.reservationLinked : labels.walkIn }}</v-chip>
                    <v-chip size="small" variant="tonal" data-testid="checkout-status">{{ labels.checkoutStatus }}: {{ statusText(checkout?.status) }}</v-chip>
                </div>
            </SectionCard>

            <SectionCard v-if="mode === 'visit'" :title="labels.treatments" class="mt-4">
                <div class="staff-row">
                    <v-select v-model="primaryStaffId" :items="staffItems" :label="labels.primaryStaff" :readonly="!visitEditable"
                        density="compact" variant="outlined" hide-details clearable class="field-md" @update:model-value="touch" />
                    <v-select v-model="nominated" :items="staffItems" :label="labels.nominations" :readonly="!visitEditable" multiple chips closable-chips
                        density="compact" variant="outlined" hide-details class="field-lg" data-testid="nominations" @update:model-value="touch" />
                </div>
                <p class="hint">{{ labels.nominationsHint }}</p>
                <div v-for="(row, index) in treatments" :key="index" class="treatment" :data-testid="`treatment-${index}`">
                    <div class="line-head">
                        <strong>{{ index + 1 }}.</strong>
                        <v-select :model-value="row.service_id" :items="serviceItems" :label="labels.service" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="field-lg" @update:model-value="(v: number | null) => onTreatmentService(row, v)" />
                        <v-text-field v-model="row.started_at" :label="labels.startTime" placeholder="11:30" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                        <v-text-field v-model.number="row.actual_minutes" type="number" min="1" :label="labels.minutes" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                        <v-btn v-if="visitEditable" icon="mdi-delete-outline" variant="text" size="small" :aria-label="labels.remove" @click="treatments.splice(index, 1); touch()" />
                    </div>
                    <div v-for="(staffRow, staffIndex) in row.staff" :key="staffIndex" class="staff-row indent">
                        <v-select v-model="staffRow.staff_id" :items="staffItems" :label="labels.staff" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="field-md" @update:model-value="touch" />
                        <v-text-field v-model.number="staffRow.actual_minutes" type="number" min="1" :label="labels.minutes" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                        <v-text-field v-model="staffRow.started_at" :label="labels.startTime" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                        <v-btn v-if="visitEditable" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="row.staff.splice(staffIndex, 1); touch()" />
                    </div>
                    <div class="indent">
                        <v-btn v-if="visitEditable" size="small" variant="text" prepend-icon="mdi-plus" @click="row.staff.push({ staff_id: null, actual_minutes: 0, started_at: null }); touch()">{{ labels.addStaff }}</v-btn>
                        <span v-if="!staffMinutesOk(row)" class="warn" role="alert">{{ labels.staffMinutesMismatch }}</span>
                    </div>
                </div>
                <v-btn v-if="visitEditable" variant="outlined" color="primary" size="small" prepend-icon="mdi-plus" data-testid="add-treatment" @click="addTreatment">{{ labels.addTreatment }}</v-btn>
            </SectionCard>

            <SectionCard :title="labels.lines" class="mt-4">
                <div v-if="checkoutEditable" class="add-buttons">
                    <v-btn v-if="mode === 'visit'" size="small" variant="outlined" prepend-icon="mdi-plus" data-testid="add-service-line" @click="addLine('service')">{{ labels.addService }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" data-testid="add-product-line" @click="addLine('product')">{{ labels.addProduct }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" @click="addLine('ticket')">{{ labels.addTicket }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" @click="addLine('membership')">{{ labels.addMembership }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" @click="addLine('other')">{{ labels.addOther }}</v-btn>
                </div>
                <div v-for="(line, index) in lines" :key="index" class="line" :data-testid="`line-${index}`">
                    <div class="line-head">
                        <v-chip size="small" label>{{ typeLabel[line.item_type] }}</v-chip>
                        <v-select v-if="line.item_type !== 'other'" :model-value="masterId(line)" :items="masterItems(line.item_type)" :label="labels.itemName" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="field-lg" @update:model-value="(v: number | null) => onMaster(line, v)" />
                        <v-text-field v-else v-model="line.item_name" :label="labels.itemName" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="field-lg" @update:model-value="touch" />
                        <v-text-field v-model.number="line.quantity" type="number" min="1" :label="labels.quantity" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="field-xs" @update:model-value="touch" />
                        <v-text-field v-model.number="line.unit_amount" type="number" min="0" :label="labels.unitAmount" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                        <v-select v-model="line.tax_category_id" :items="taxItems" :label="labels.taxCategory" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                        <span class="num strong"><ReportValue :value="lineGross(line)" format="money" /></span>
                        <v-btn v-if="checkoutEditable" icon="mdi-delete-outline" variant="text" size="small" :aria-label="labels.remove" @click="lines.splice(index, 1); touch()" />
                    </div>
                    <div class="indent sub">
                        <span>{{ labels.net }} <ReportValue :value="lineSplit(line)?.net ?? null" format="money" /> / {{ labels.tax }} <ReportValue :value="lineSplit(line)?.tax ?? null" format="money" /></span>
                        <v-select v-if="line.item_type === 'service' && mode === 'visit'" v-model="line.treatment_index" :items="treatmentItems" :label="labels.linkedTreatment"
                            :readonly="!checkoutEditable" density="compact" variant="outlined" hide-details clearable class="field-md" @update:model-value="touch" />
                        <v-checkbox v-model="line.is_staff_allocatable" :label="labels.allocatable" :readonly="!checkoutEditable" density="compact" hide-details @update:model-value="touch" />
                        <small v-if="line.item_type === 'ticket'" class="muted">{{ labels.ticketNote }}</small>
                        <small v-if="line.item_type === 'membership'" class="muted">{{ labels.membershipNote }}</small>
                    </div>
                    <div v-if="line.is_staff_allocatable" class="indent allocations">
                        <div v-for="(allocation, allocationIndex) in line.allocations" :key="allocationIndex" class="staff-row">
                            <v-select v-model="allocation.staff_id" :items="staffItems" :label="labels.staff" :readonly="!checkoutEditable"
                                density="compact" variant="outlined" hide-details class="field-md" @update:model-value="touch" />
                            <v-text-field v-model.number="allocation.amount" type="number" min="0" :label="labels.amount" :readonly="!checkoutEditable"
                                density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                            <v-btn v-if="checkoutEditable" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="line.allocations.splice(allocationIndex, 1); touch()" />
                        </div>
                        <div v-if="checkoutEditable">
                            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="line.allocations.push({ staff_id: primaryStaffId ?? staff[0]?.id ?? 0, amount: 0 }); touch()">{{ labels.allocations }}</v-btn>
                            <v-btn v-if="line.treatment_index !== null" size="small" variant="text" prepend-icon="mdi-scale-balance" @click="allocateByMinutes(line)">{{ labels.allocateByMinutes }}</v-btn>
                        </div>
                        <span v-if="!allocationOk(line)" class="warn" role="alert">{{ labels.allocationMismatch }}</span>
                    </div>
                </div>
            </SectionCard>
        </div>

        <aside class="entry-side">
            <SectionCard :title="labels.summary">
                <dl class="summary">
                    <dt>{{ labels.net }}</dt><dd><ReportValue :value="totals.net" format="money" /></dd>
                    <dt>{{ labels.tax }}</dt><dd><ReportValue :value="totals.tax" format="money" /></dd>
                    <dt class="strong">{{ labels.gross }}</dt><dd class="strong" data-testid="gross-total"><ReportValue :value="totals.gross" format="money" /></dd>
                </dl>
                <h4 class="mt-3">{{ labels.tenders }}</h4>
                <div v-for="(tender, index) in tenders" :key="index" class="staff-row">
                    <v-select v-model="tender.payment_method_id" :items="methodItems" :label="labels.paymentMethod" :readonly="!checkoutEditable || tender.external"
                        density="compact" variant="outlined" hide-details class="field-md" @update:model-value="touch" />
                    <v-text-field v-model.number="tender.amount" type="number" min="1" :label="labels.amount" :readonly="!checkoutEditable || tender.external"
                        density="compact" variant="outlined" hide-details class="field-sm" @update:model-value="touch" />
                    <v-text-field v-if="needsRetailSplit" v-model.number="tender.retail_amount" type="number" min="0" :label="labels.retailPortion" :readonly="!checkoutEditable || tender.external"
                        density="compact" variant="outlined" hide-details class="field-sm" :data-testid="`tender-retail-${index}`" @update:model-value="touch" />
                    <v-btn v-if="checkoutEditable && !tender.external" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="tenders.splice(index, 1); touch()" />
                </div>
                <p v-if="needsRetailSplit" class="hint">{{ labels.retailSplitHint }}</p>
                <p v-if="needsRetailSplit && retailAllocated !== retailGross" class="warn" role="alert">{{ labels.retailSplitMismatch }}</p>
                <v-btn v-if="checkoutEditable" size="small" variant="text" prepend-icon="mdi-plus" data-testid="add-tender"
                    @click="tenders.push({ payment_method_id: paymentMethods[0]?.id ?? null, amount: Math.max(totals.difference, 0), retail_amount: null }); touch()">{{ labels.addTender }}</v-btn>
                <dl class="summary mt-2">
                    <dt>{{ labels.paid }}</dt><dd><ReportValue :value="totals.paid" format="money" /></dd>
                    <dt>{{ labels.difference }}</dt><dd :class="{ warn: totals.difference !== 0 }"><ReportValue :value="totals.difference" format="money" /></dd>
                </dl>
                <p v-if="totals.difference !== 0 && lines.length > 0" class="warn" role="alert">{{ labels.mismatch }}</p>
                <p v-if="!checkoutEditable" class="muted">{{ labels.readOnly }}<template v-if="checkout?.void_reason">（{{ checkout.void_reason }}）</template></p>
                <p v-if="dirty" class="unsaved">{{ labels.unsaved }}</p>
                <div class="actions">
                    <v-btn v-if="visitEditable || checkoutEditable" color="primary" variant="outlined" :loading="busy" prepend-icon="mdi-content-save-outline" data-testid="save-entry" @click="save()">{{ labels.save }}</v-btn>
                    <v-btn v-if="mode === 'visit' && (visitEditable || (checkout?.status === 'draft'))" color="primary" variant="flat" :loading="busy" prepend-icon="mdi-check" data-testid="complete-entry" @click="complete">{{ labels.complete }}</v-btn>
                    <v-btn v-if="mode === 'sale' && checkout?.status === 'draft'" color="primary" variant="flat" :loading="busy" prepend-icon="mdi-check" data-testid="finalize-entry" @click="finalize">{{ labels.finalize }}</v-btn>
                    <v-btn v-if="canVoid && checkout?.status === 'finalized'" color="error" variant="text" prepend-icon="mdi-cancel" data-testid="void-entry" @click="voidOpen = true">{{ labels.void }}</v-btn>
                </div>
            </SectionCard>
        </aside>
    </div>

    <v-dialog v-model="voidOpen" max-width="420">
        <v-card>
            <v-card-title>{{ labels.void }}</v-card-title>
            <v-card-text>
                <p class="mb-3">{{ labels.voidConfirm }}</p>
                <v-text-field v-model="voidReason" :label="labels.voidReason" density="compact" variant="outlined" hide-details maxlength="255" />
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="voidOpen = false">{{ labels.close }}</v-btn>
                <v-btn color="error" variant="flat" :disabled="!voidReason.trim()" @click="submitVoid">{{ labels.void }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.entry-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
.entry-side { position: sticky; top: 76px; }
@media (max-width: 1100px) { .entry-grid { grid-template-columns: 1fr; } .entry-side { position: static; } }
.meta-row, .staff-row, .line-head, .add-buttons, .sub { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.treatment, .line { border-top: 1px solid #e4e8ee; padding: 12px 0; }
.indent { margin-left: 24px; margin-top: 8px; }
.field-xs { max-width: 90px; } .field-sm { max-width: 140px; } .field-md { min-width: 180px; max-width: 220px; } .field-lg { min-width: 240px; flex: 1 1 240px; }
.summary { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; margin: 0; }
.summary dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
.strong { font-weight: 700; }
.num { font-variant-numeric: tabular-nums; min-width: 88px; text-align: right; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.hint, .muted { color: #6b7785; font-size: 0.85rem; }
.warn { color: #b42318; font-size: 0.85rem; }
.unsaved { color: #995e00; font-size: 0.85rem; }
</style>
