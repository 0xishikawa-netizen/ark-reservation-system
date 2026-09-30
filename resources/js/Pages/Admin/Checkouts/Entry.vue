<script setup lang="ts">
import type { RequestPayload } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MoneyField, PageHeader, SectionCard, TimeField } from '@/components/ark';
import { allocateByWeight, splitInclusive } from '@/components/checkout/amounts';
import { ReportValue } from '@/components/reports';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type ItemType = 'service' | 'product' | 'ticket' | 'membership' | 'other';
interface Priced { id: number; name: string; price: number; tax_category_id: number | null }
interface ServiceMaster extends Priced { duration_min: number; requires_staff: boolean; booth_ids?: number[] }
interface TaxCategoryMaster { id: number; name: string; rate_bps: number | null }
interface Option { id: number; name: string }
interface StaffRow { staff_id: number | null; actual_minutes: number; started_at: string | null }
interface TreatmentRow { service_id: number | null; actual_minutes: number; started_at: string | null; booth_id?: number | null; staff: StaffRow[] }
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
    reservation?: { booked_minutes?: number; starts_at: string; ends_at: string; buffer_min: number; service_name: string | null; staff_name: string | null;
        booth_name: string | null; is_staff_requested: boolean; payment_method: string | null } | null;
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
    booths?: Option[];
    businessHours?: { opens_at: string; closes_at: string };
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
    service_id: row.service_id, actual_minutes: row.actual_minutes, started_at: row.started_at, booth_id: row.booth_id ?? null,
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
/** 予約時の支払区分（回数券・月額・事前決済）。現地払いは表示しない。 */
const paymentMethodLabel = (method: string): string | null => MESSAGES.visitCompletion.reservationPayment[method] ?? null;
const methodItems = computed(() => props.paymentMethods.map((m) => ({ title: MESSAGES.reporting.paymentMethodHeadings[m.code] ?? m.name, value: m.id })));
const treatmentItems = computed(() => treatments.value.map((row, index) => ({
    title: `${index + 1}. ${props.services.find((s) => s.id === row.service_id)?.name ?? labels.treatments}`,
    value: index,
})));
/** 開始時刻の選択肢はこの日の営業時間に合わせる。 */
const hours = computed(() => ({
    opens_at: props.businessHours?.opens_at ?? '00:00',
    closes_at: props.businessHours?.closes_at ?? '23:55',
}));
/** 施術のブースは、そのメニューで使えるブースだけ（未設定のメニューは全ブース）。選択済みの対象外ブースは残して注記する。 */
function boothItemsFor(row: TreatmentRow): Option[] {
    const all = props.booths ?? [];
    const allowed = props.services.find((s) => s.id === row.service_id)?.booth_ids ?? [];
    const list = allowed.length === 0 ? [...all] : all.filter((booth) => allowed.includes(booth.id));
    const current = all.find((booth) => booth.id === row.booth_id);
    if (current && !list.some((booth) => booth.id === current.id)) {
        list.push({ ...current, name: `${current.name}${MESSAGES.reservation.boothNotAllowedSuffix}` });
    }
    return list;
}
const typeLabel: Record<ItemType, string> = {
    service: labels.itemService, product: labels.itemProduct, ticket: labels.itemTicket, membership: labels.itemMembership, other: labels.itemOther,
};

function addTreatment(): void {
    const service = props.services[0];
    treatments.value.push({
        service_id: service?.id ?? null,
        actual_minutes: service?.duration_min ?? 60,
        started_at: props.visit?.reservation_starts_at ?? null,
        booth_id: treatments.value[treatments.value.length - 1]?.booth_id ?? null,
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
        if (service) {
            // 明細名の選択とコース別集計のため、施術料はサービスIDも持たせる。
            line.service_id = service.id;
            applyMaster(line, service);
        }
        line.treatment_index = index;
        line.is_staff_allocatable = index !== null;
    }
    lines.value.push(line);
    touch();
}

/** 選択後の表示が見切れないよう、名前だけを表示し、価格は候補一覧の補足に出す。 */
function masterItems(type: ItemType): { title: string; value: number; props: { subtitle: string } }[] {
    const list = type === 'service' ? props.services : type === 'product' ? props.products
        : type === 'ticket' ? props.ticketProducts : type === 'membership' ? props.membershipPlans : [];
    return list.map((m) => ({ title: m.name, value: m.id, props: { subtitle: `${m.price.toLocaleString()}円` } }));
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

/**
 * 担当時間で按分する。対象施術を選んでいればその施術の担当時間、未選択なら来店の全施術
 * （T30＋M15＋A15 のような構成全体）の担当時間をスタッフごとに合計して按分する（Task 11-29）。
 */
function allocateByMinutes(line: LineRow): void {
    const rows = line.treatment_index === null ? treatments.value : [treatments.value[line.treatment_index]].filter(Boolean);
    const minutesByStaff = new Map<number, number>();
    for (const row of rows) {
        for (const s of row.staff) {
            if (s.staff_id !== null) minutesByStaff.set(s.staff_id, (minutesByStaff.get(s.staff_id) ?? 0) + Number(s.actual_minutes));
        }
    }
    if (minutesByStaff.size === 0) return;
    const staffIds = [...minutesByStaff.keys()];
    const amounts = allocateByWeight(lineGross(line), staffIds.map((id) => minutesByStaff.get(id) ?? 0));
    line.allocations = staffIds.map((id, index) => ({ staff_id: id, amount: amounts[index] }));
    touch();
}

/** 実施施術の合計分数と、予約で確保した分数（延長を含む）。超えている時は延長が必要。 */
const treatmentTotal = computed(() => treatments.value.reduce((sum, row) => sum + Number(row.actual_minutes || 0), 0));
const reservedMinutes = computed(() => props.visit?.reservation?.booked_minutes ?? null);

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

// サーバー側の入力チェックで弾かれた内容（例: 支払額 0 円）を画面に出す。出さないと保存が無反応に見える。
const page = usePage();
const validationErrors = computed<string[]>(() => [...new Set(Object.values((page.props.errors ?? {}) as Record<string, string>))]);

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

// 予約から開いた未会計の来店では、予約メニューの施術料を下書き明細として1行用意する（Task 11-27）。
// 回数券・月額・事前決済の予約は施術料を二重に請求しないよう用意しない。保存するまでは確定しない。
if (props.mode === 'visit' && props.checkout === null && props.visit?.editable && props.visit.reservation
    && !['ticket', 'membership', 'single'].includes(props.visit.reservation.payment_method ?? '')
    && treatments.value.length > 0 && lines.value.length === 0) {
    addLine('service');
    const prefilled = lines.value[0];
    if (prefilled) allocateByMinutes(prefilled);
}
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
                <!-- 予約内容（予約から来た時）。実施内容は下の施術で変更できる。 -->
                <div v-if="visit?.reservation" class="reservation-summary" data-testid="reservation-summary">
                    <span class="muted">{{ MESSAGES.visitCompletion.reservationSummary }}</span>
                    <strong>{{ visit.reservation.starts_at }}〜{{ visit.reservation.ends_at }}</strong>
                    <span v-if="visit.reservation.service_name">{{ visit.reservation.service_name }}</span>
                    <span v-if="visit.reservation.staff_name">{{ labels.primaryStaff }} {{ visit.reservation.staff_name }}<template v-if="visit.reservation.is_staff_requested">（{{ labels.nominations }}）</template></span>
                    <span v-if="visit.reservation.booth_name">{{ visit.reservation.booth_name }}</span>
                    <span v-if="visit.reservation.buffer_min > 0" class="muted">{{ MESSAGES.visitCompletion.bufferAfter.replace('{min}', String(visit.reservation.buffer_min)) }}</span>
                    <span v-if="visit.reservation.payment_method && paymentMethodLabel(visit.reservation.payment_method)" class="muted">{{ paymentMethodLabel(visit.reservation.payment_method) }}</span>
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
                    <div class="field-row">
                        <strong class="row-no">{{ index + 1 }}.</strong>
                        <v-select :model-value="row.service_id" :items="serviceItems" :label="labels.service" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="f-grow" @update:model-value="(v: number | null) => onTreatmentService(row, v)" />
                        <div class="f-time">
                            <TimeField :model-value="row.started_at ?? ''" :label="labels.startTime" :min-time="hours.opens_at" :max-time="hours.closes_at" :step-minutes="5"
                                :readonly="!visitEditable" clearable density="compact" @update:model-value="(v: string) => { row.started_at = v || null; touch(); }" />
                        </div>
                        <v-text-field v-model.number="row.actual_minutes" type="number" min="1" :label="labels.minutes" :suffix="labels.minutesUnit" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="f-min" @update:model-value="touch" />
                        <v-select v-if="(booths ?? []).length > 0" v-model="row.booth_id" :items="boothItemsFor(row)" item-title="name" item-value="id" :label="MESSAGES.checkout.booth"
                            :readonly="!visitEditable" density="compact" variant="outlined" hide-details clearable class="f-mid" @update:model-value="touch" />
                        <v-btn v-if="visitEditable" icon="mdi-delete-outline" variant="text" size="small" :aria-label="labels.remove" @click="treatments.splice(index, 1); touch()" />
                    </div>
                    <p class="sub-label">{{ labels.treatmentStaff }}</p>
                    <div v-for="(staffRow, staffIndex) in row.staff" :key="staffIndex" class="field-row indent">
                        <v-select v-model="staffRow.staff_id" :items="staffItems" :label="labels.staff" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="f-mid" @update:model-value="touch" />
                        <div class="f-time">
                            <TimeField :model-value="staffRow.started_at ?? ''" :label="labels.startTime" :min-time="hours.opens_at" :max-time="hours.closes_at" :step-minutes="5"
                                :readonly="!visitEditable" clearable density="compact" @update:model-value="(v: string) => { staffRow.started_at = v || null; touch(); }" />
                        </div>
                        <v-text-field v-model.number="staffRow.actual_minutes" type="number" min="1" :label="labels.staffMinutes" :suffix="labels.minutesUnit" :readonly="!visitEditable"
                            density="compact" variant="outlined" hide-details class="f-min" @update:model-value="touch" />
                        <v-btn v-if="visitEditable" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="row.staff.splice(staffIndex, 1); touch()" />
                    </div>
                    <div class="indent">
                        <v-btn v-if="visitEditable" size="small" variant="text" prepend-icon="mdi-plus" @click="row.staff.push({ staff_id: null, actual_minutes: 0, started_at: null }); touch()">{{ labels.addStaff }}</v-btn>
                        <span v-if="!staffMinutesOk(row)" class="warn" role="alert">{{ labels.staffMinutesMismatch }}</span>
                    </div>
                </div>
                <div class="composition-row">
                    <v-btn v-if="visitEditable" variant="outlined" color="primary" size="small" prepend-icon="mdi-plus" data-testid="add-treatment" @click="addTreatment">{{ labels.addTreatment }}</v-btn>
                    <!-- 実施施術の合計と予約の時間。超える時は予約の延長（競合確認つき）が必要。 -->
                    <span v-if="reservedMinutes !== null" class="composition-total" :class="{ warn: treatmentTotal > reservedMinutes }" data-testid="composition-total">
                        {{ MESSAGES.checkout.compositionTotal.replace('{total}', String(treatmentTotal)).replace('{reserved}', String(reservedMinutes)) }}
                    </span>
                    <span v-if="reservedMinutes !== null && treatmentTotal > reservedMinutes" class="warn" role="alert">{{ MESSAGES.checkout.compositionExceeds }}</span>
                </div>
            </SectionCard>

            <SectionCard :title="labels.lines" class="mt-4">
                <!-- 会計明細の意味が分かるよう、何を入れる欄かを先に説明する。 -->
                <div class="help" data-testid="lines-help">
                    <p>{{ labels.linesHelp }}</p>
                    <p>{{ labels.linesHelpAllocation }}</p>
                </div>
                <div v-if="checkoutEditable" class="add-buttons">
                    <span class="sub-label">{{ labels.addLineLabel }}</span>
                    <v-btn v-if="mode === 'visit'" size="small" variant="outlined" prepend-icon="mdi-plus" data-testid="add-service-line" @click="addLine('service')">{{ labels.addService }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" data-testid="add-product-line" @click="addLine('product')">{{ labels.addProduct }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" @click="addLine('ticket')">{{ labels.addTicket }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" @click="addLine('membership')">{{ labels.addMembership }}</v-btn>
                    <v-btn size="small" variant="outlined" prepend-icon="mdi-plus" @click="addLine('other')">{{ labels.addOther }}</v-btn>
                </div>
                <p v-if="lines.length === 0" class="muted mt-3">{{ labels.linesEmpty }}</p>
                <div v-for="(line, index) in lines" :key="index" class="line" :data-testid="`line-${index}`">
                    <div class="field-row">
                        <v-chip size="small" label class="type-chip">{{ typeLabel[line.item_type] }}</v-chip>
                        <v-select v-if="line.item_type !== 'other'" :model-value="masterId(line)" :items="masterItems(line.item_type)" :label="labels.itemName" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="f-grow" @update:model-value="(v: number | null) => onMaster(line, v)" />
                        <v-text-field v-else v-model="line.item_name" :label="labels.itemName" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="f-grow" @update:model-value="touch" />
                        <v-text-field v-model.number="line.quantity" type="number" min="1" :label="labels.quantity" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="f-qty" @update:model-value="touch" />
                        <MoneyField :model-value="line.unit_amount" :label="labels.unitAmount" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="f-money" @update:model-value="(v: number | null) => { line.unit_amount = v ?? 0; touch(); }" />
                        <v-select v-model="line.tax_category_id" :items="taxItems" :label="labels.taxCategory" :readonly="!checkoutEditable"
                            density="compact" variant="outlined" hide-details class="f-tax" @update:model-value="touch" />
                        <div class="line-total">
                            <small class="muted">{{ labels.lineTotal }}</small>
                            <strong class="num"><ReportValue :value="lineGross(line)" format="money" /></strong>
                        </div>
                        <v-btn v-if="checkoutEditable" icon="mdi-delete-outline" variant="text" size="small" :aria-label="labels.remove" @click="lines.splice(index, 1); touch()" />
                    </div>
                    <div class="indent sub">
                        <span class="muted">{{ labels.net }} <ReportValue :value="lineSplit(line)?.net ?? null" format="money" /> ／ {{ labels.tax }} <ReportValue :value="lineSplit(line)?.tax ?? null" format="money" /></span>
                        <v-select v-if="line.item_type === 'service' && mode === 'visit'" v-model="line.treatment_index" :items="treatmentItems" :label="labels.linkedTreatment"
                            :readonly="!checkoutEditable" density="compact" variant="outlined" hide-details clearable class="f-mid" @update:model-value="touch" />
                        <v-checkbox v-model="line.is_staff_allocatable" :label="labels.allocatable" :readonly="!checkoutEditable" density="compact" hide-details @update:model-value="touch" />
                        <small v-if="line.item_type === 'ticket'" class="muted">{{ labels.ticketNote }}</small>
                        <small v-if="line.item_type === 'membership'" class="muted">{{ labels.membershipNote }}</small>
                    </div>
                    <div v-if="line.is_staff_allocatable" class="indent allocations">
                        <p class="sub-label">{{ labels.allocations }}</p>
                        <div v-for="(allocation, allocationIndex) in line.allocations" :key="allocationIndex" class="field-row">
                            <v-select v-model="allocation.staff_id" :items="staffItems" :label="labels.staff" :readonly="!checkoutEditable"
                                density="compact" variant="outlined" hide-details class="f-mid" @update:model-value="touch" />
                            <MoneyField :model-value="allocation.amount" :label="labels.amount" :readonly="!checkoutEditable"
                                density="compact" variant="outlined" hide-details class="f-money" @update:model-value="(v: number | null) => { allocation.amount = v ?? 0; touch(); }" />
                            <v-btn v-if="checkoutEditable" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="line.allocations.splice(allocationIndex, 1); touch()" />
                        </div>
                        <div v-if="checkoutEditable">
                            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="line.allocations.push({ staff_id: primaryStaffId ?? staff[0]?.id ?? 0, amount: 0 }); touch()">{{ labels.addAllocation }}</v-btn>
                            <v-btn size="small" variant="text" prepend-icon="mdi-scale-balance" @click="allocateByMinutes(line)">{{ labels.allocateByMinutes }}</v-btn>
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
                <p class="hint">{{ labels.tendersHelp }}</p>
                <div v-for="(tender, index) in tenders" :key="index" class="tender">
                    <div class="tender-head">
                        <v-select v-model="tender.payment_method_id" :items="methodItems" :label="labels.paymentMethod" :readonly="!checkoutEditable || tender.external"
                            density="compact" variant="outlined" hide-details class="f-grow" @update:model-value="touch" />
                        <v-btn v-if="checkoutEditable && !tender.external" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="tenders.splice(index, 1); touch()" />
                    </div>
                    <div class="tender-amounts">
                        <MoneyField :model-value="tender.amount" :label="labels.amount" :readonly="!checkoutEditable || tender.external"
                            density="compact" variant="outlined" hide-details @update:model-value="(v: number | null) => { tender.amount = v ?? 0; touch(); }" />
                        <MoneyField v-if="needsRetailSplit" :model-value="tender.retail_amount" :label="labels.retailPortion" :readonly="!checkoutEditable || tender.external"
                            density="compact" variant="outlined" hide-details :data-testid="`tender-retail-${index}`" @update:model-value="(v: number | null) => { tender.retail_amount = v; touch(); }" />
                    </div>
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
                <div v-if="validationErrors.length > 0" class="warn" role="alert" data-testid="entry-errors">
                    <p>{{ MESSAGES.checkout.validationFailed }}</p>
                    <ul><li v-for="message in validationErrors" :key="message">{{ message }}</li></ul>
                </div>
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
.meta-row, .staff-row, .add-buttons, .sub { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
/* outlined入力の浮きラベルが上の行と重ならないよう、行間を空ける */
.staff-row { row-gap: 16px; }
.field-row { display: flex; flex-wrap: wrap; gap: 16px 10px; align-items: center; }
.field-row + .field-row { margin-top: 14px; }
/* 見切れないよう、各欄に最低幅を持たせて折り返す */
.f-grow { flex: 1 1 280px; min-width: 220px; }
.f-mid { flex: 1 1 170px; min-width: 150px; }
.f-time { flex: 0 0 150px; }
.f-min { flex: 0 0 116px; }
.f-qty { flex: 0 0 88px; }
.f-money { flex: 0 0 150px; }
.f-tax { flex: 0 0 150px; }
.row-no { min-width: 20px; }
.type-chip { flex: 0 0 auto; }
.line-total { display: flex; flex-direction: column; align-items: flex-end; min-width: 96px; }
.sub-label { margin: 12px 0 8px; font-size: 0.8125rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.7); }
.add-buttons .sub-label { margin: 0 4px 0 0; }
.help { background: rgba(var(--v-theme-primary), 0.05); border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; font-size: 0.85rem; line-height: 1.6; }
.help p + p { margin-top: 4px; }
.composition-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 8px; }
.composition-total { font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), 0.7); }
.reservation-summary { display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: baseline; margin-top: 8px; font-size: 0.875rem; }
.treatment, .line { border-top: 1px solid #e4e8ee; padding: 14px 0; }
.indent { margin-left: 28px; margin-top: 8px; }
.sub { margin-top: 10px; }
.tender { border: 1px solid #e4e8ee; border-radius: 8px; padding: 10px; margin-top: 10px; }
.tender-head { display: flex; gap: 4px; align-items: center; }
.tender-amounts { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 12px; }
.summary { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; margin: 0; }
.summary dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
.strong { font-weight: 700; }
.num { font-variant-numeric: tabular-nums; text-align: right; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.hint, .muted { color: #6b7785; font-size: 0.85rem; }
.warn { color: #b42318; font-size: 0.85rem; }
.unsaved { color: #995e00; font-size: 0.85rem; }
</style>
