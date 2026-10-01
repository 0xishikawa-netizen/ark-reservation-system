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
/** お客様の頭文字（見出しの丸いアイコン）。 */
const customerInitial = computed(() => (props.customer?.name ?? '').trim().charAt(0) || '–');
/** 施術時間メーター：予約時間に対する実施合計の割合（100% で頭打ち）。 */
const meterPercent = computed(() => (reservedMinutes.value ? Math.min(100, Math.round((treatmentTotal.value / reservedMinutes.value) * 100)) : 0));
/** 会計明細の説明は必要な時だけ開く。明細が空の時は最初から開いておく。 */
const helpOpen = ref(lines.value.length === 0);
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

    <!-- お客様と予約の内容（誰の・どの予約の会計か）を最初に1枚で見せる。 -->
    <section class="hero">
        <div class="hero__who">
            <div class="hero__avatar" aria-hidden="true">{{ customerInitial }}</div>
            <div class="hero__name">
                <a v-if="customer" :href="customer.url" class="hero__link">{{ customer.name }}</a>
                <span v-else class="hero__anon">{{ labels.anonymous }}</span>
                <span v-if="customer" class="hero__member">{{ customer.member_no }}</span>
            </div>
            <div class="hero__chips">
                <span v-if="visit" class="pill" :class="`pill--${visit.status}`">{{ labels.visitStatus }} {{ statusText(visit.status) }}</span>
                <span v-if="visit" class="pill pill--plain">{{ visit.reservation_id ? labels.reservationLinked : labels.walkIn }}</span>
                <span class="pill" :class="`pill--${checkout?.status ?? 'none'}`" data-testid="checkout-status">{{ labels.checkoutStatus }} {{ statusText(checkout?.status) }}</span>
            </div>
        </div>
        <!-- 予約内容（予約から来た時）。実施内容は下の施術で変更できる。 -->
        <dl v-if="visit?.reservation" class="hero__facts" data-testid="reservation-summary">
            <div class="fact">
                <dt>{{ MESSAGES.visitCompletion.reservationSummary }}</dt>
                <dd class="fact__time">{{ visit.reservation.starts_at }}〜{{ visit.reservation.ends_at }}</dd>
            </div>
            <div v-if="visit.reservation.service_name" class="fact fact--wide">
                <dt>{{ labels.service }}</dt>
                <dd>{{ visit.reservation.service_name }}</dd>
            </div>
            <div v-if="visit.reservation.staff_name" class="fact">
                <dt>{{ labels.primaryStaff }}</dt>
                <dd>{{ visit.reservation.staff_name }}<span v-if="visit.reservation.is_staff_requested" class="pill pill--nominated">{{ labels.nominationShort }}</span></dd>
            </div>
            <div v-if="visit.reservation.booth_name" class="fact">
                <dt>{{ labels.booth }}</dt>
                <dd>{{ visit.reservation.booth_name }}</dd>
            </div>
            <div v-if="visit.reservation.payment_method && paymentMethodLabel(visit.reservation.payment_method)" class="fact">
                <dt>{{ labels.reservationPayment }}</dt>
                <dd>{{ paymentMethodLabel(visit.reservation.payment_method) }}</dd>
            </div>
            <div v-if="visit.reservation.buffer_min > 0" class="fact">
                <dt>{{ labels.interval }}</dt>
                <dd>{{ visit.reservation.buffer_min }}{{ labels.minutesUnit }}</dd>
            </div>
        </dl>
    </section>

    <div class="entry-grid">
        <div class="entry-main">
            <!-- ① 施術 -->
            <section v-if="mode === 'visit'" class="step">
                <header class="step__head">
                    <span class="step__no">1</span>
                    <div>
                        <h2 class="step__title">{{ labels.treatments }}</h2>
                        <p class="step__sub">{{ labels.treatmentsSub }}</p>
                    </div>
                </header>

                <!-- 担当・指名（来店全体） -->
                <div class="assign">
                    <v-select v-model="primaryStaffId" :items="staffItems" :label="labels.primaryStaff" :readonly="!visitEditable" hide-details clearable prepend-inner-icon="mdi-account-outline" @update:model-value="touch" />
                    <v-select v-model="nominated" :items="staffItems" :label="labels.nominations" :readonly="!visitEditable" multiple chips closable-chips hide-details prepend-inner-icon="mdi-star-outline" data-testid="nominations" @update:model-value="touch" />
                    <p class="assign__hint">{{ labels.nominationsHint }}</p>
                </div>

                <article v-for="(row, index) in treatments" :key="index" class="tcard" :data-testid="`treatment-${index}`">
                    <header class="tcard__head">
                        <span class="tcard__no">{{ index + 1 }}</span>
                        <span class="tcard__name">{{ services.find((s) => s.id === row.service_id)?.name ?? labels.service }}</span>
                        <span class="pill pill--plain">{{ row.actual_minutes || 0 }}{{ labels.minutesUnit }}</span>
                        <span v-if="!staffMinutesOk(row)" class="pill pill--warn" role="alert">{{ labels.staffMinutesMismatch }}</span>
                        <v-btn v-if="visitEditable" class="tcard__remove" icon="mdi-delete-outline" variant="text" size="small" :aria-label="labels.remove" @click="treatments.splice(index, 1); touch()" />
                    </header>
                    <div class="tcard__grid">
                        <v-select :model-value="row.service_id" :items="serviceItems" :label="labels.service" :readonly="!visitEditable" hide-details class="tcard__menu" @update:model-value="(v: number | null) => onTreatmentService(row, v)" />
                        <TimeField :model-value="row.started_at ?? ''" :label="labels.startTime" :min-time="hours.opens_at" :max-time="hours.closes_at" :step-minutes="5"
                            :readonly="!visitEditable" clearable @update:model-value="(v: string) => { row.started_at = v || null; touch(); }" />
                        <v-text-field v-model.number="row.actual_minutes" type="number" min="1" :label="labels.minutes" :suffix="labels.minutesUnit" :readonly="!visitEditable" hide-details @update:model-value="touch" />
                        <v-select v-if="(booths ?? []).length > 0" v-model="row.booth_id" :items="boothItemsFor(row)" item-title="name" item-value="id" :label="labels.booth"
                            :readonly="!visitEditable" density="comfortable" variant="outlined" hide-details clearable @update:model-value="touch" />
                    </div>

                    <!-- 実際に施術したスタッフ（複数可） -->
                    <div class="staffbox">
                        <p class="staffbox__title"><v-icon icon="mdi-account-group-outline" size="16" />{{ labels.treatmentStaff }}</p>
                        <div v-for="(staffRow, staffIndex) in row.staff" :key="staffIndex" class="staffbox__row">
                            <v-select v-model="staffRow.staff_id" :items="staffItems" :label="labels.staff" :readonly="!visitEditable" hide-details bg-color="surface" @update:model-value="touch" />
                            <TimeField :model-value="staffRow.started_at ?? ''" :label="labels.startTime" :min-time="hours.opens_at" :max-time="hours.closes_at" :step-minutes="5"
                                :readonly="!visitEditable" clearable bg-color="surface" @update:model-value="(v: string) => { staffRow.started_at = v || null; touch(); }" />
                            <v-text-field v-model.number="staffRow.actual_minutes" type="number" min="1" :label="labels.staffMinutes" :suffix="labels.minutesUnit" :readonly="!visitEditable" hide-details bg-color="surface" @update:model-value="touch" />
                            <v-btn v-if="visitEditable" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="row.staff.splice(staffIndex, 1); touch()" />
                            <span v-else />
                        </div>
                        <p v-if="row.staff.length === 0" class="muted">{{ labels.noTreatmentStaff }}</p>
                        <v-btn v-if="visitEditable" size="small" variant="text" color="primary" prepend-icon="mdi-plus" @click="row.staff.push({ staff_id: null, actual_minutes: 0, started_at: null }); touch()">{{ labels.addStaff }}</v-btn>
                    </div>
                </article>

                <button v-if="visitEditable" type="button" class="adder" data-testid="add-treatment" @click="addTreatment">
                    {{ labels.addTreatment }}
                </button>

                <!-- 実施施術の合計と予約の時間。超える時は予約の延長（競合確認つき）が必要。 -->
                <div v-if="reservedMinutes !== null" class="meter" :class="{ 'meter--over': treatmentTotal > reservedMinutes }">
                    <div class="meter__label">
                        <span data-testid="composition-total">{{ MESSAGES.checkout.compositionTotal.replace('{total}', String(treatmentTotal)).replace('{reserved}', String(reservedMinutes)) }}</span>
                    </div>
                    <div class="meter__track"><div class="meter__bar" :style="{ width: `${meterPercent}%` }" /></div>
                    <p v-if="treatmentTotal > reservedMinutes" class="warn" role="alert">{{ MESSAGES.checkout.compositionExceeds }}</p>
                </div>
            </section>

            <!-- ② 会計明細 -->
            <section class="step">
                <header class="step__head">
                    <span class="step__no">{{ mode === 'visit' ? 2 : 1 }}</span>
                    <div>
                        <h2 class="step__title">{{ labels.lines }}</h2>
                        <p class="step__sub">{{ labels.linesSub }}</p>
                    </div>
                    <button type="button" class="step__help" :aria-expanded="helpOpen" @click="helpOpen = !helpOpen">
                        {{ labels.linesHelpToggle }}
                    </button>
                </header>
                <!-- 会計明細の意味が分かるよう、何を入れる欄かを説明する（開閉式）。 -->
                <div v-show="helpOpen" class="help" data-testid="lines-help">
                    <p>{{ labels.linesHelp }}</p>
                    <p>{{ labels.linesHelpAllocation }}</p>
                </div>

                <div v-if="checkoutEditable" class="adders">
                    <span class="adders__label">{{ labels.addLineLabel }}</span>
                    <button v-if="mode === 'visit'" type="button" class="chipbtn" data-testid="add-service-line" @click="addLine('service')">{{ labels.addService }}</button>
                    <button type="button" class="chipbtn" data-testid="add-product-line" @click="addLine('product')">{{ labels.addProduct }}</button>
                    <button type="button" class="chipbtn" @click="addLine('ticket')">{{ labels.addTicket }}</button>
                    <button type="button" class="chipbtn" @click="addLine('membership')">{{ labels.addMembership }}</button>
                    <button type="button" class="chipbtn" @click="addLine('other')">{{ labels.addOther }}</button>
                </div>

                <div v-if="lines.length === 0" class="empty">
                    <v-icon icon="mdi-receipt-text-outline" size="28" />
                    <p>{{ labels.linesEmpty }}</p>
                </div>

                <article v-for="(line, index) in lines" :key="index" class="lcard" :class="`lcard--${line.item_type}`" :data-testid="`line-${index}`">
                    <div class="lcard__main">
                        <span class="lcard__type">{{ typeLabel[line.item_type] }}</span>
                        <v-select v-if="line.item_type !== 'other'" :model-value="masterId(line)" :items="masterItems(line.item_type)" :label="labels.itemName" :readonly="!checkoutEditable" hide-details class="lcard__name" @update:model-value="(v: number | null) => onMaster(line, v)" />
                        <v-text-field v-else v-model="line.item_name" :label="labels.itemName" :readonly="!checkoutEditable" hide-details class="lcard__name" @update:model-value="touch" />
                        <v-text-field v-model.number="line.quantity" type="number" min="1" :label="labels.quantity" :readonly="!checkoutEditable" hide-details @update:model-value="touch" />
                        <MoneyField :model-value="line.unit_amount" :label="labels.unitAmount" :readonly="!checkoutEditable" hide-details @update:model-value="(v: number | null) => { line.unit_amount = v ?? 0; touch(); }" />
                        <v-select v-model="line.tax_category_id" :items="taxItems" :label="labels.taxCategory" :readonly="!checkoutEditable" hide-details @update:model-value="touch" />
                        <div class="lcard__total">
                            <small>{{ labels.lineTotal }}</small>
                            <strong><ReportValue :value="lineGross(line)" format="money" /></strong>
                        </div>
                        <v-btn v-if="checkoutEditable" icon="mdi-delete-outline" variant="text" size="small" :aria-label="labels.remove" @click="lines.splice(index, 1); touch()" />
                    </div>
                    <div class="lcard__meta">
                        <span class="muted">{{ labels.net }} <ReportValue :value="lineSplit(line)?.net ?? null" format="money" /> ／ {{ labels.tax }} <ReportValue :value="lineSplit(line)?.tax ?? null" format="money" /></span>
                        <v-select v-if="line.item_type === 'service' && mode === 'visit'" v-model="line.treatment_index" :items="treatmentItems" :label="labels.linkedTreatment"
                            :readonly="!checkoutEditable" hide-details clearable class="lcard__link" @update:model-value="touch" />
                        <v-switch v-model="line.is_staff_allocatable" :label="labels.allocatable" :readonly="!checkoutEditable" color="primary" density="compact" hide-details @update:model-value="touch" />
                        <small v-if="line.item_type === 'ticket'" class="muted">{{ labels.ticketNote }}</small>
                        <small v-if="line.item_type === 'membership'" class="muted">{{ labels.membershipNote }}</small>
                    </div>
                    <div v-if="line.is_staff_allocatable" class="alloc">
                        <p class="alloc__title">
                            <v-icon icon="mdi-account-cash-outline" size="16" />{{ labels.allocations }}
                            <span class="alloc__state" :class="allocationOk(line) ? 'alloc__state--ok' : 'alloc__state--ng'">
                                <ReportValue :value="line.allocations.reduce((sum, a) => sum + Number(a.amount || 0), 0)" format="money" /> ／ <ReportValue :value="lineGross(line)" format="money" />
                            </span>
                        </p>
                        <div v-for="(allocation, allocationIndex) in line.allocations" :key="allocationIndex" class="alloc__row">
                            <v-select v-model="allocation.staff_id" :items="staffItems" :label="labels.staff" :readonly="!checkoutEditable" hide-details bg-color="surface" @update:model-value="touch" />
                            <MoneyField :model-value="allocation.amount" :label="labels.amount" :readonly="!checkoutEditable" hide-details bg-color="surface" @update:model-value="(v: number | null) => { allocation.amount = v ?? 0; touch(); }" />
                            <v-btn v-if="checkoutEditable" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="line.allocations.splice(allocationIndex, 1); touch()" />
                        </div>
                        <div v-if="checkoutEditable" class="alloc__actions">
                            <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-plus" @click="line.allocations.push({ staff_id: primaryStaffId ?? staff[0]?.id ?? 0, amount: 0 }); touch()">{{ labels.addAllocation }}</v-btn>
                            <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-scale-balance" @click="allocateByMinutes(line)">{{ labels.allocateByMinutes }}</v-btn>
                        </div>
                        <p v-if="!allocationOk(line)" class="warn" role="alert">{{ labels.allocationMismatch }}</p>
                    </div>
                </article>
            </section>
        </div>

        <!-- お会計（常に右に表示） -->
        <aside class="entry-side">
            <div class="bill">
                <div class="bill__total">
                    <span class="bill__label">{{ labels.gross }}</span>
                    <strong class="bill__amount" data-testid="gross-total"><ReportValue :value="totals.gross" format="money" /></strong>
                    <span class="bill__breakdown">{{ labels.net }} <ReportValue :value="totals.net" format="money" /> ・ {{ labels.tax }} <ReportValue :value="totals.tax" format="money" /></span>
                </div>

                <div class="bill__section">
                    <p class="bill__title">{{ labels.tenders }}</p>
                    <p class="hint">{{ labels.tendersHelp }}</p>
                    <div v-for="(tender, index) in tenders" :key="index" class="tender">
                        <div class="tender__head">
                            <v-select v-model="tender.payment_method_id" :items="methodItems" :label="labels.paymentMethod" :readonly="!checkoutEditable || tender.external" hide-details @update:model-value="touch" />
                            <v-btn v-if="checkoutEditable && !tender.external" icon="mdi-close" variant="text" size="small" :aria-label="labels.remove" @click="tenders.splice(index, 1); touch()" />
                        </div>
                        <div class="tender__amounts">
                            <MoneyField :model-value="tender.amount" :label="labels.amount" :readonly="!checkoutEditable || tender.external" hide-details @update:model-value="(v: number | null) => { tender.amount = v ?? 0; touch(); }" />
                            <MoneyField v-if="needsRetailSplit" :model-value="tender.retail_amount" :label="labels.retailPortion" :readonly="!checkoutEditable || tender.external" hide-details :data-testid="`tender-retail-${index}`" @update:model-value="(v: number | null) => { tender.retail_amount = v; touch(); }" />
                        </div>
                    </div>
                    <p v-if="needsRetailSplit" class="hint">{{ labels.retailSplitHint }}</p>
                    <p v-if="needsRetailSplit && retailAllocated !== retailGross" class="warn" role="alert">{{ labels.retailSplitMismatch }}</p>
                    <button v-if="checkoutEditable" type="button" class="adder adder--small" data-testid="add-tender"
                        @click="tenders.push({ payment_method_id: paymentMethods[0]?.id ?? null, amount: Math.max(totals.difference, 0), retail_amount: null }); touch()">
                        {{ labels.addTender }}
                    </button>
                </div>

                <!-- 支払合計と差額。一致していれば緑、ずれていれば赤で示す。 -->
                <div class="balance" :class="lines.length === 0 ? 'balance--idle' : totals.difference === 0 ? 'balance--ok' : 'balance--ng'">
                    <div class="balance__row"><span>{{ labels.paid }}</span><ReportValue :value="totals.paid" format="money" /></div>
                    <div class="balance__row balance__row--diff"><span>{{ labels.difference }}</span><ReportValue :value="totals.difference" format="money" /></div>
                    <p v-if="lines.length > 0" class="balance__msg" :role="totals.difference !== 0 ? 'alert' : undefined">
                        <v-icon :icon="totals.difference === 0 ? 'mdi-check-circle' : 'mdi-alert-circle'" size="16" />
                        {{ totals.difference === 0 ? labels.balanced : labels.mismatch }}
                    </p>
                </div>

                <p v-if="!checkoutEditable" class="muted">{{ labels.readOnly }}<template v-if="checkout?.void_reason">（{{ checkout.void_reason }}）</template></p>
                <p v-if="dirty" class="unsaved"><v-icon icon="mdi-circle-medium" size="16" />{{ labels.unsaved }}</p>
                <div v-if="validationErrors.length > 0" class="errors" role="alert" data-testid="entry-errors">
                    <p>{{ MESSAGES.checkout.validationFailed }}</p>
                    <ul><li v-for="message in validationErrors" :key="message">{{ message }}</li></ul>
                </div>

                <div class="bill__actions">
                    <v-btn v-if="mode === 'visit' && (visitEditable || (checkout?.status === 'draft'))" color="primary" variant="flat" size="large" block :loading="busy" prepend-icon="mdi-check" data-testid="complete-entry" @click="complete">{{ labels.complete }}</v-btn>
                    <v-btn v-if="mode === 'sale' && checkout?.status === 'draft'" color="primary" variant="flat" size="large" block :loading="busy" prepend-icon="mdi-check" data-testid="finalize-entry" @click="finalize">{{ labels.finalize }}</v-btn>
                    <v-btn v-if="visitEditable || checkoutEditable" color="primary" variant="outlined" block :loading="busy" prepend-icon="mdi-content-save-outline" data-testid="save-entry" @click="save()">{{ labels.save }}</v-btn>
                    <v-btn v-if="canVoid && checkout?.status === 'finalized'" color="error" variant="text" block prepend-icon="mdi-cancel" data-testid="void-entry" @click="voidOpen = true">{{ labels.void }}</v-btn>
                </div>
            </div>
        </aside>
    </div>

    <v-dialog v-model="voidOpen" max-width="420">
        <v-card>
            <v-card-title>{{ labels.void }}</v-card-title>
            <v-card-text>
                <p class="mb-3">{{ labels.voidConfirm }}</p>
                <v-text-field v-model="voidReason" :label="labels.voidReason" hide-details maxlength="255" />
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
/* ---- 全体 ---- */
.entry-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
.entry-main { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.entry-side { position: sticky; top: 76px; }
@media (max-width: 1100px) { .entry-grid { grid-template-columns: 1fr; } .entry-side { position: static; } }
.muted, .hint { color: rgba(var(--v-theme-on-surface), 0.6); font-size: 0.8125rem; }
.hint { margin: 0 0 8px; line-height: 1.5; }
.warn { color: rgb(var(--v-theme-error)); font-size: 0.8125rem; margin: 6px 0 0; }
.unsaved { display: flex; align-items: center; gap: 2px; color: #995e00; font-size: 0.8125rem; margin: 0; }

/* 小さなラベル（状態・時間） */
.pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700;
    background: rgba(var(--v-theme-primary), 0.08); color: rgb(var(--v-theme-primary)); white-space: nowrap; }
.pill--plain { background: rgba(var(--v-theme-on-surface), 0.06); color: rgba(var(--v-theme-on-surface), 0.75); }
.pill--draft, .pill--in_progress, .pill--arrived { background: #fff4e0; color: #8a5300; }
.pill--completed, .pill--finalized { background: #e6f4ea; color: #1e6b34; }
.pill--voided { background: #fdecea; color: #b42318; }
.pill--none { background: rgba(var(--v-theme-on-surface), 0.06); color: rgba(var(--v-theme-on-surface), 0.65); }
.pill--warn { background: #fdecea; color: #b42318; }
.pill--nominated { margin-left: 6px; padding: 0 8px; font-size: 0.6875rem; }

/* ---- ヒーロー（お客様・予約） ---- */
.hero { display: flex; flex-wrap: wrap; align-items: center; gap: 16px 32px; margin-bottom: 20px; padding: 18px 22px;
    background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), 0.08); border-radius: 14px;
    box-shadow: 0 1px 2px rgb(18 25 60 / 6%); }
.hero__who { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 14px; }
.hero__avatar { display: grid; place-items: center; width: 46px; height: 46px; border-radius: 50%; background: rgb(var(--v-theme-primary));
    color: #fff; font-size: 1.125rem; font-weight: 800; }
.hero__name { display: flex; flex-direction: column; }
.hero__link { font-size: 1.125rem; font-weight: 800; color: rgb(var(--v-theme-on-surface)); text-decoration: none; }
.hero__link:hover { text-decoration: underline; }
.hero__anon { font-size: 1rem; font-weight: 700; color: rgba(var(--v-theme-on-surface), 0.6); }
.hero__member { font-size: 0.75rem; color: rgba(var(--v-theme-on-surface), 0.55); font-variant-numeric: tabular-nums; }
.hero__chips { display: flex; flex-wrap: wrap; gap: 6px; }
.hero__facts { display: flex; flex-wrap: wrap; gap: 10px 28px; margin: 0 0 0 auto; padding-left: 28px; border-left: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
@media (max-width: 1100px) { .hero__facts { margin-left: 0; padding-left: 0; border-left: 0; } }
.fact { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.fact dt { font-size: 0.6875rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.55); }
.fact dd { margin: 0; font-size: 0.875rem; font-weight: 700; }
.fact--wide dd { max-width: 280px; }
.fact__time { color: rgb(var(--v-theme-primary)); font-variant-numeric: tabular-nums; font-size: 1rem !important; }

/* ---- ステップ ---- */
.step { padding: 20px 22px 22px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
    border-radius: 14px; box-shadow: 0 1px 2px rgb(18 25 60 / 6%); }
.step__head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
.step__no { display: grid; flex: 0 0 auto; place-items: center; width: 28px; height: 28px; border-radius: 50%;
    background: rgb(var(--v-theme-primary)); color: #fff; font-size: 0.875rem; font-weight: 800; }
.step__title { margin: 0; font-size: 1.0625rem; font-weight: 800; line-height: 28px; }
.step__sub { margin: 2px 0 0; font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), 0.6); }
.step__help { display: inline-flex; align-items: center; gap: 4px; margin-left: auto; padding: 4px 10px; border: 0; border-radius: 999px;
    background: rgba(var(--v-theme-primary), 0.08); color: rgb(var(--v-theme-primary)); font-size: 0.75rem; font-weight: 700; cursor: pointer; white-space: nowrap; }
.help { margin: -4px 0 16px; padding: 12px 16px; border-left: 3px solid rgb(var(--v-theme-primary)); border-radius: 8px;
    background: rgba(var(--v-theme-primary), 0.05); font-size: 0.8125rem; line-height: 1.7; }
.help p { margin: 0; }
.help p + p { margin-top: 6px; }

/* 担当・指名 */
.assign { display: grid; grid-template-columns: 280px minmax(0, 420px); justify-content: start; gap: 12px; margin-bottom: 18px; padding: 14px;
    border-radius: 10px; background: rgba(var(--v-theme-on-surface), 0.025); }
.assign__hint { grid-column: 1 / -1; margin: 0; font-size: 0.75rem; color: rgba(var(--v-theme-on-surface), 0.6); }
@media (max-width: 700px) { .assign { grid-template-columns: 1fr; } }

/* 施術カード */
.tcard { margin-top: 12px; border: 1px solid rgba(var(--v-theme-on-surface), 0.1); border-radius: 12px; overflow: hidden; }
.tcard__head { display: flex; align-items: center; gap: 10px; padding: 10px 10px 10px 14px; background: rgba(var(--v-theme-primary), 0.04);
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06); }
.tcard__no { display: grid; flex: 0 0 auto; place-items: center; width: 22px; height: 22px; border-radius: 6px;
    background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary)); font-size: 0.75rem; font-weight: 800; }
.tcard__name { min-width: 0; overflow: hidden; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
.tcard__remove { margin-left: auto; }
.tcard__grid { display: grid; grid-template-columns: minmax(220px, 340px) 140px 120px 200px; justify-content: start; gap: 12px; padding: 16px 14px 4px; }
@media (max-width: 1280px) { .tcard__grid { grid-template-columns: minmax(0, 340px) minmax(0, 200px); } .tcard__menu { grid-column: 1 / -1; } }
.staffbox { margin: 12px 14px 14px; padding: 12px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), 0.03); }
.staffbox__title { display: flex; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 0.8125rem; font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.75); }
.staffbox__row { display: grid; grid-template-columns: 260px 140px 120px 36px; justify-content: start; gap: 10px; align-items: center; margin-bottom: 10px; }
@media (max-width: 1280px) { .staffbox__row { grid-template-columns: minmax(0, 260px) 140px 120px 36px; } }

/* 追加ボタン（破線） */
.adder { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 12px; padding: 12px;
    border: 1.5px dashed rgba(var(--v-theme-primary), 0.35); border-radius: 12px; background: transparent;
    color: rgb(var(--v-theme-primary)); font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.adder:hover { background: rgba(var(--v-theme-primary), 0.05); }
.adder--small { padding: 8px; font-size: 0.8125rem; border-radius: 10px; }

/* 施術時間メーター */
.meter { margin-top: 16px; }
.meter__label { margin-bottom: 6px; font-size: 0.8125rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.7); }
.meter__track { height: 6px; border-radius: 999px; background: rgba(var(--v-theme-on-surface), 0.08); overflow: hidden; }
.meter__bar { height: 100%; border-radius: inherit; background: rgb(var(--v-theme-primary)); transition: width 0.2s; }
.meter--over .meter__bar { background: rgb(var(--v-theme-error)); }
.meter--over .meter__label { color: rgb(var(--v-theme-error)); }

/* 明細の追加 */
.adders { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 4px; }
.adders__label { margin-right: 2px; font-size: 0.8125rem; font-weight: 700; color: rgba(var(--v-theme-on-surface), 0.7); }
.chipbtn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; border: 1px solid rgba(var(--v-theme-primary), 0.3);
    border-radius: 999px; background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-primary)); font-size: 0.8125rem; font-weight: 700;
    cursor: pointer; transition: background 0.15s; }
.chipbtn:hover { background: rgba(var(--v-theme-primary), 0.06); }
.empty { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: 12px; padding: 28px 16px; border-radius: 12px;
    background: rgba(var(--v-theme-on-surface), 0.025); color: rgba(var(--v-theme-on-surface), 0.5); font-size: 0.8125rem; }
.empty p { margin: 0; }

/* 明細カード */
.lcard { position: relative; margin-top: 12px; padding: 14px 14px 12px 18px; border: 1px solid rgba(var(--v-theme-on-surface), 0.1); border-radius: 12px; }
.lcard::before { content: ''; position: absolute; top: 12px; bottom: 12px; left: 0; width: 4px; border-radius: 0 4px 4px 0; background: rgb(var(--v-theme-primary)); }
.lcard--product::before { background: #2e9e5b; }
.lcard--ticket::before { background: #e8a33d; }
.lcard--membership::before { background: #7e57c2; }
.lcard--other::before { background: #5a6b7b; }
.lcard__main { display: grid; grid-template-columns: 56px minmax(220px, 340px) 84px 150px 140px minmax(100px, 1fr) 36px; gap: 10px; align-items: center; }
@media (max-width: 1360px) {
    .lcard__main { grid-template-columns: 56px minmax(0, 1fr) 36px; }
    .lcard__main > :nth-child(n+3):not(:last-child) { grid-column: 2 / 3; }
    .lcard__total { justify-self: start; }
}
.lcard__type { font-size: 0.75rem; font-weight: 800; color: rgba(var(--v-theme-on-surface), 0.7); }
.lcard__total { display: flex; flex-direction: column; align-items: flex-end; }
.lcard__total small { font-size: 0.6875rem; color: rgba(var(--v-theme-on-surface), 0.55); }
.lcard__total strong { font-size: 1rem; font-variant-numeric: tabular-nums; }
.lcard__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-top: 10px; padding-left: 66px; }
@media (max-width: 1360px) { .lcard__meta { padding-left: 0; } }
.lcard__link { flex: 0 0 300px; }
.alloc { margin: 12px 0 0 66px; padding: 12px; border-radius: 10px; background: rgba(var(--v-theme-primary), 0.04); }
@media (max-width: 1360px) { .alloc { margin-left: 0; } }
.alloc__title { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 0.8125rem; font-weight: 700; }
.alloc__state { margin-left: auto; padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-variant-numeric: tabular-nums; }
.alloc__state--ok { background: #e6f4ea; color: #1e6b34; }
.alloc__state--ng { background: #fdecea; color: #b42318; }
.alloc__row { display: grid; grid-template-columns: 260px 160px 36px; justify-content: start; gap: 10px; align-items: center; margin-bottom: 8px; }
.alloc__actions { display: flex; flex-wrap: wrap; gap: 6px; }

/* ---- お会計（右） ---- */
.bill { display: flex; flex-direction: column; gap: 16px; padding: 18px; background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-theme-on-surface), 0.08); border-radius: 14px; box-shadow: 0 4px 16px rgb(18 25 60 / 8%); }
.bill__total { display: flex; flex-direction: column; gap: 2px; padding: 16px; border-radius: 12px; background: rgb(var(--v-theme-primary)); color: #fff; }
.bill__label { font-size: 0.75rem; font-weight: 700; opacity: 0.8; }
.bill__amount { font-size: 1.875rem; font-weight: 800; line-height: 1.2; font-variant-numeric: tabular-nums; }
.bill__breakdown { font-size: 0.75rem; opacity: 0.8; font-variant-numeric: tabular-nums; }
.bill__title { margin: 0 0 4px; font-size: 0.9375rem; font-weight: 800; }
.tender { margin-bottom: 10px; padding: 10px; border: 1px solid rgba(var(--v-theme-on-surface), 0.1); border-radius: 10px; }
.tender__head { display: flex; align-items: center; gap: 4px; }
.tender__amounts { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 10px; }
.balance { padding: 12px 14px; border-radius: 12px; background: rgba(var(--v-theme-on-surface), 0.04); }
.balance__row { display: flex; justify-content: space-between; font-size: 0.875rem; font-variant-numeric: tabular-nums; }
.balance__row + .balance__row { margin-top: 4px; }
.balance__row--diff { font-weight: 800; }
.balance__msg { display: flex; align-items: center; gap: 4px; margin: 8px 0 0; font-size: 0.8125rem; font-weight: 700; }
.balance--ok { background: #e6f4ea; color: #1e6b34; }
.balance--ng { background: #fdecea; color: #b42318; }
.errors { padding: 10px 12px; border-radius: 10px; background: #fdecea; color: #b42318; font-size: 0.8125rem; }
.errors p, .errors ul { margin: 0; }
.errors ul { padding-left: 1.2em; }
.bill__actions { display: flex; flex-direction: column; gap: 8px; }
</style>
