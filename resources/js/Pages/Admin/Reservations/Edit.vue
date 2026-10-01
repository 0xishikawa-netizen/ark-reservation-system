<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { DateField, EmptyValue, PageHeader, SectionCard, StatusChip, MoneyField } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    reservationSourceColor,
    reservationSourceLabel,
    reservationStatusLabel,
} from '@/design/tokens';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

interface ReservationDetail {
    id: number;
    customer_id: number;
    customer_name: string;
    service_id: number;
    service_name: string;
    staff_id: number | null;
    staff_name: string | null;
    booth_id: number | null;
    booth_name: string | null;
    starts_at: string;
    ends_at: string;
    status: string;
    source: string;
    version: number;
    notes: string | null;
    is_staff_requested: boolean;
}

interface ServiceOption {
    id: number;
    name: string;
    duration_min: number;
    requires_staff: boolean;
    staff_ids: number[];
    /** メニューで使えるブース（空＝全有効ブース）。 */
    booth_ids?: number[];
}

interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

interface BoothOption {
    id: number;
    name: string;
}

interface AvailabilitySlot {
    starts_at: string;
    ends_at: string;
    available_staff_ids: number[];
}

interface PaymentRefundRow {
    amount: number;
    status: string;
    reason: string;
    created_at: string | null;
}

interface PaymentRow {
    id: number;
    kind: string;
    kind_label: string;
    amount: number;
    status: string;
    status_label: string;
    stripe_payment_intent_id: string | null;
    stripe_charge_id: string | null;
    refunded_amount: number;
    needs_attention: boolean;
    created_at: string | null;
    refunds: PaymentRefundRow[];
}

interface PaymentSummary {
    original_amount: number;
    captured_total: number;
    refunded_total: number;
    net_received: number;
    final_amount: number | null;
    delta: number;
    in_flight_addon: { id: number; amount: number; status: string } | null;
    payments: PaymentRow[];
}

const props = defineProps<{
    reservation: ReservationDetail;
    services: ServiceOption[];
    staff: StaffOption[];
    booths: BoothOption[];
    payment_summary: PaymentSummary;
}>();

const selectedStaffId = ref<number | null>(props.reservation.staff_id);
const selectedBoothId = ref<number | null>(props.reservation.booth_id);
const date = ref(props.reservation.starts_at.slice(0, 10));
const slots = ref<AvailabilitySlot[]>([]);
const loadingSlots = ref(false);
const availabilityLoaded = ref(false);
const availabilityError = ref('');
const dialog = ref<'cancel' | 'complete' | 'no-show' | null>(null);
const exemptionReason = ref<string | null>(null);
const page = usePage();
const canCheckout = computed(() => Boolean((page.props as { auth?: { can?: { checkoutsManage?: boolean } } }).auth?.can?.checkoutsManage));

function openVisitEntry(): void {
    router.post(`/admin/reservations/${props.reservation.id}/visit`);
}
const actionProcessing = ref(false);
const cancelReason = ref('');
// 保存直後だけ「確認」ボタンを出す（保存前から出しておくと、まだ保存していない
// 変更を確認しに行けると誤解されるため）。担当・ブース・日付を変えたら消す。
const justSaved = ref(false);
const adjustmentForm = useForm({
    final_amount: props.payment_summary.final_amount ?? props.payment_summary.original_amount,
});

const service = computed<ServiceOption | null>(() =>
    props.services.find((item) => item.id === props.reservation.service_id) ?? null,
);

const eligibleStaff = computed(() => {
    const list = props.staff.filter((staff) => service.value?.staff_ids.includes(staff.user_id) ?? false);
    // 今の担当がこのメニューを担当できなくなっていても（施術可否・資格の変更後）、ID の数字ではなく名前で出す。
    const current = props.staff.find((staff) => staff.user_id === selectedStaffId.value);
    if (current && !list.some((staff) => staff.user_id === current.user_id)) {
        list.push({ ...current, display_name: `${current.display_name}${MESSAGES.reservation.staffNotEligibleSuffix}` });
    }

    return list;
});

/** 選択中の担当がこのメニューを担当できない時の案内。 */
const selectedStaffIneligible = computed(() => selectedStaffId.value !== null
    && !(service.value?.staff_ids.includes(selectedStaffId.value) ?? false));

/** メニューで使えるブースだけを選択肢にする（紐付けが無いメニューは全ブース）。 */
const boothItems = computed(() => {
    const allowed = service.value?.booth_ids ?? [];
    const list = allowed.length === 0 ? [...props.booths] : props.booths.filter((booth) => allowed.includes(booth.id));
    const current = props.booths.find((booth) => booth.id === selectedBoothId.value);
    if (current && !list.some((booth) => booth.id === current.id)) {
        list.push({ ...current, name: `${current.name}${MESSAGES.reservation.boothNotAllowedSuffix}` });
    }

    return list;
});

const form = useForm({
    starts_at: props.reservation.starts_at as string | null,
    staff_id: props.reservation.staff_id,
    booth_id: props.reservation.booth_id,
    version: props.reservation.version,
    notes: props.reservation.notes ?? '',
    is_staff_requested: props.reservation.is_staff_requested,
    reservation: null as string | null,
});

const isConfirmed = computed(() => props.reservation.status === 'confirmed');
const hasConflict = computed(() => form.errors.reservation?.includes('他で更新') ?? false);
const autoAssignedStaffName = computed(() => {
    const assignedStaffId = form.staff_id;

    if (selectedStaffId.value !== null || assignedStaffId === null) {
        return null;
    }

    return props.staff.find((staff) => staff.user_id === assignedStaffId)?.display_name ?? null;
});

// 担当スタッフ・変更前の予約時刻を、空き時間の取り直し後も可能なら維持する
// （desiredStartsAt。§NewReservationPanel と同じ考え方）。
const desiredStartsAt = ref<string | null>(props.reservation.starts_at);

/**
 * 担当・ブース・日付のどれかが変わったら空き時間を自動で取り直す。
 * 「空き時間を見る」ボタンを押させる方式は何のためのボタンか分かりにくいため、
 * NewReservationPanel と同じく明示操作なしで最新の候補を出す（§空き時間の自動取得）。
 */
let availabilityTimer: ReturnType<typeof setTimeout> | null = null;

watch([selectedStaffId, selectedBoothId, date], () => {
    if (availabilityTimer !== null) {
        clearTimeout(availabilityTimer);
    }

    form.staff_id = selectedStaffId.value;
    form.booth_id = selectedBoothId.value;
    // 担当を外したら指名も外す（指名は担当スタッフに対するもの）。
    if (selectedStaffId.value === null) {
        form.is_staff_requested = false;
    }
    // ユーザーが担当・ブース・日付を実際に変えたときだけ、選択中の時間を白紙に戻す
    // （初回表示時は今の予約時刻をそのまま残す。§loadAvailability 内では消さない）。
    form.starts_at = null;
    justSaved.value = false;

    if (date.value === '') {
        slots.value = [];
        availabilityLoaded.value = false;

        return;
    }

    availabilityTimer = setTimeout(() => void loadAvailability(), 150);
});

onMounted(() => {
    if (date.value !== '') {
        void loadAvailability();
    }
});

async function loadAvailability(): Promise<void> {
    if (date.value === '') {
        return;
    }

    loadingSlots.value = true;
    availabilityLoaded.value = false;
    availabilityError.value = '';

    const params = new URLSearchParams({
        service_id: String(props.reservation.service_id),
        date: date.value,
    });

    if (selectedStaffId.value !== null) {
        params.set('staff_id', String(selectedStaffId.value));
    }

    if (selectedBoothId.value !== null) {
        params.set('booth_id', String(selectedBoothId.value));
    }

    try {
        const response = await fetch(`/admin/reservations/availability?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(MESSAGES.availability.loadFailed);
        }

        slots.value = (await response.json()) as AvailabilitySlot[];
        availabilityLoaded.value = true;

        const match = desiredStartsAt.value === null
            ? undefined
            : slots.value.find((slot) => timeLabel(slot.starts_at) === timeLabel(desiredStartsAt.value!));

        if (match) {
            selectSlot(match);
        }
    } catch (error: unknown) {
        availabilityError.value = error instanceof Error
            ? error.message
            : MESSAGES.availability.loadFailed;
    } finally {
        loadingSlots.value = false;
    }
}

function selectSlot(slot: AvailabilitySlot): void {
    desiredStartsAt.value = slot.starts_at;
    form.starts_at = slot.starts_at;
    form.staff_id = selectedStaffId.value ?? slot.available_staff_ids[0] ?? null;
    form.booth_id = selectedBoothId.value;
}

const slotItems = computed(() => slots.value.map((slot) => ({
    title: timeLabel(slot.starts_at),
    value: timeLabel(slot.starts_at),
})));

// slots は毎回サーバーから取り直すため区切り文字が reservation.starts_at と
// 揃っている保証がない。時刻部分（HH:MM）だけで突き合わせる。
const selectedSlotValue = computed<string | null>({
    get: () => (form.starts_at ? timeLabel(form.starts_at) : null),
    set: (value) => {
        const slot = value === null ? undefined : slots.value.find((item) => timeLabel(item.starts_at) === value);

        if (slot) {
            selectSlot(slot);
        }
    },
});

function submit(): void {
    justSaved.value = false;
    form.put(`/admin/reservations/${props.reservation.id}`, {
        errorBag: 'reservation',
        preserveScroll: true,
        onSuccess: () => {
            justSaved.value = true;
        },
    });
}

function runAction(): void {
    if (dialog.value === null) {
        return;
    }

    const action = dialog.value;
    const path = action === 'no-show' ? 'no-show' : action;
    actionProcessing.value = true;
    router.patch(
        `/admin/reservations/${props.reservation.id}/${path}`,
        action === 'cancel'
            ? { reason: cancelReason.value }
            : action === 'complete' ? { exemption_reason: exemptionReason.value ?? '' } : {},
        {
            preserveScroll: true,
            onFinish: () => {
                actionProcessing.value = false;
                dialog.value = null;
            },
        },
    );
}

function actionTitle(): string {
    return {
        cancel: '予約をキャンセル',
        complete: MESSAGES.visitCompletion.noCheckoutTitle,
        'no-show': '無断キャンセルに変更',
    }[dialog.value ?? 'cancel'];
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));
}

function timeLabel(value: string): string {
    return value.slice(11, 16);
}

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('ja-JP').format(value)}円`;
}

function submitAdjustment(): void {
    if (adjustmentForm.processing) {
        return;
    }

    adjustmentForm.post(`/admin/reservations/${props.reservation.id}/adjustment`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`予約 #${reservation.id}`" />

    <PageHeader :title="`予約 #${reservation.id}`" :subtitle="formatDateTime(reservation.starts_at)">
        <template #actions>
            <v-btn
                variant="text"
                prepend-icon="mdi-account-outline"
                :href="`/admin/customers/${reservation.customer_id}`"
            >
                顧客詳細へ
            </v-btn>
            <v-btn
                variant="outlined"
                prepend-icon="mdi-calendar-month-outline"
                :href="`/admin/schedule?date=${reservation.starts_at.slice(0, 10)}&reservation=${reservation.id}`"
            >
                ブッキングボードで確認
            </v-btn>
        </template>
    </PageHeader>

    <div class="reservation-page">
    <SectionCard class="reservation-hero mb-6">
        <div class="reservation-hero__row">
            <div class="reservation-hero__icon">
                <v-icon icon="mdi-calendar-check-outline" size="26" />
            </div>
            <div class="reservation-hero__body">
                <div class="reservation-hero__customer">
                    {{ reservation.customer_name }}
                </div>
                <div class="reservation-hero__service">
                    {{ reservation.service_name }}
                </div>
            </div>
            <div class="reservation-hero__chips">
                <StatusChip :status="reservation.status" :label="reservationStatusLabel(reservation.status)" />
                <v-chip :color="reservationSourceColor(reservation.source)" size="small" variant="tonal">
                    {{ reservationSourceLabel(reservation.source) }}
                </v-chip>
            </div>
        </div>

        <v-divider class="my-4" />

        <div class="reservation-hero__facts">
            <div class="reservation-hero__fact">
                <div class="text-caption text-medium-emphasis">日時</div>
                <div class="font-weight-medium">{{ formatDateTime(reservation.starts_at) }}</div>
            </div>
            <div class="reservation-hero__fact">
                <div class="text-caption text-medium-emphasis">担当</div>
                <div class="font-weight-medium">{{ reservation.staff_name ?? '担当なし' }}</div>
            </div>
            <div class="reservation-hero__fact">
                <div class="text-caption text-medium-emphasis">ブース</div>
                <div class="font-weight-medium"><template v-if="reservation.booth_name">{{ reservation.booth_name }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></div>
            </div>
        </div>
    </SectionCard>

    <SectionCard title="日時・担当の変更" subtitle="確定済みの予約のみ変更できます。" class="mb-6">
        <v-alert v-if="!isConfirmed" type="info" variant="tonal" class="mb-5">
            {{ MESSAGES.reservation.notConfirmedNotEditable }}
        </v-alert>

        <v-form @submit.prevent="submit">
            <div class="field-grid">
                <v-select
                    v-model="selectedStaffId"
                    :items="eligibleStaff"
                    item-title="display_name"
                    item-value="user_id"
                    label="担当スタッフ"
                    variant="outlined"
                    density="comfortable"
                    clearable
                    hide-details="auto"
                    :disabled="!isConfirmed"
                    :error-messages="form.errors.staff_id"
                    :hint="selectedStaffIneligible ? MESSAGES.reservation.staffNotEligibleHint : undefined"
                    persistent-hint
                />
                <v-checkbox
                    v-model="form.is_staff_requested"
                    label="指名"
                    density="comfortable"
                    hide-details
                    :disabled="!isConfirmed || selectedStaffId === null"
                    data-testid="edit-nomination"
                />
                <v-select
                    v-model="selectedBoothId"
                    :items="boothItems"
                    item-title="name"
                    item-value="id"
                    :label="(service?.booth_ids ?? []).length > 0 ? MESSAGES.reservation.boothMappedLabel : 'ブース（任意）'"
                    variant="outlined"
                    density="comfortable"
                    clearable
                    hide-details="auto"
                    :disabled="!isConfirmed"
                    :error-messages="form.errors.booth_id"
                />
            </div>

            <div class="d-flex ga-3 align-start flex-wrap mt-4">
                <div class="date-field">
                    <DateField
                        v-model="date"
                        label="変更日"
                        density="comfortable"
                        hide-details="auto"
                        :disabled="!isConfirmed"
                    />
                </div>
                <div v-if="date !== ''" class="date-field">
                    <v-select
                        v-model="selectedSlotValue"
                        :items="slotItems"
                        :loading="loadingSlots"
                        label="時間"
                        variant="outlined"
                        density="comfortable"
                        hide-details="auto"
                        :disabled="!isConfirmed || slotItems.length === 0"
                        :error-messages="form.errors.starts_at"
                    />
                </div>
            </div>

            <v-alert v-if="availabilityError" type="error" variant="tonal" class="mt-4">
                {{ availabilityError }}
            </v-alert>
            <v-alert
                v-else-if="availabilityLoaded && slots.length === 0"
                type="info"
                variant="tonal"
                class="mt-4"
            >
                {{ MESSAGES.availability.noneOnDate }}
            </v-alert>
            <v-alert
                v-if="autoAssignedStaffName"
                type="info"
                variant="tonal"
                class="mt-4"
            >
                担当は {{ autoAssignedStaffName }} に自動割当されます。
            </v-alert>

            <v-divider class="my-5" />

            <v-textarea
                v-model="form.notes"
                label="備考"
                variant="outlined"
                maxlength="1000"
                counter
                rows="4"
                hide-details="auto"
                :error-messages="form.errors.notes"
            />

            <v-alert
                v-if="form.errors.reservation"
                type="error"
                variant="tonal"
                class="mt-4"
            >
                {{ form.errors.reservation }}
                <v-btn
                    v-if="hasConflict"
                    class="ml-3"
                    size="small"
                    variant="outlined"
                    @click="router.reload()"
                >
                    再読込
                </v-btn>
            </v-alert>

            <div class="d-flex ga-3 mt-5">
                <v-btn
                    type="submit"
                    color="primary"
                    :loading="form.processing"
                    :disabled="!isConfirmed"
                >
                    保存
                </v-btn>
                <v-btn
                    v-if="justSaved"
                    variant="outlined"
                    prepend-icon="mdi-calendar-month-outline"
                    :href="`/admin/schedule?date=${reservation.starts_at.slice(0, 10)}&reservation=${reservation.id}`"
                >
                    確認
                </v-btn>
            </div>
        </v-form>
    </SectionCard>

    <SectionCard title="ステータス操作" class="mb-6">
        <v-alert v-if="!isConfirmed" type="info" variant="tonal" class="mb-4">
            {{ MESSAGES.reservation.notConfirmedNotEditable }}
        </v-alert>
        <div class="d-flex ga-3 flex-wrap">
            <!-- 通常の施術は来店・会計で実施内容と会計を確定する（Task 11-27）。 -->
            <v-btn
                v-if="canCheckout"
                color="primary"
                variant="flat"
                prepend-icon="mdi-cash-register"
                :disabled="!isConfirmed && props.reservation.status !== 'completed'"
                data-testid="open-visit-entry"
                @click="openVisitEntry"
            >
                {{ MESSAGES.visitCompletion.visitEntry }}
            </v-btn>
            <v-btn color="success" variant="outlined" :disabled="!isConfirmed" data-testid="complete-without-checkout" @click="exemptionReason = null; dialog = 'complete'">
                {{ MESSAGES.visitCompletion.noCheckoutMenu }}
            </v-btn>
            <v-btn color="warning" variant="outlined" :disabled="!isConfirmed" @click="dialog = 'no-show'">
                無断キャンセル
            </v-btn>
            <v-btn color="error" variant="outlined" :disabled="!isConfirmed" @click="dialog = 'cancel'">
                キャンセル
            </v-btn>
        </div>
    </SectionCard>

    <SectionCard
        title="決済サマリ"
        subtitle="最終施術金額と実質受領額の差額を追加決済または返金で調整します。"
        class="ark-table-section"
    >
        <v-alert
            v-if="payment_summary.in_flight_addon"
            type="warning"
            variant="tonal"
            class="mb-4"
        >
            追加決済 #{{ payment_summary.in_flight_addon.id }}（{{ formatMoney(payment_summary.in_flight_addon.amount) }}）は
            {{ payment_summary.in_flight_addon.status }} です。
        </v-alert>

        <v-row dense class="mb-2">
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">当初金額</div><div>{{ formatMoney(payment_summary.original_amount) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">決済確定額</div><div>{{ formatMoney(payment_summary.captured_total) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">返金総額</div><div>{{ formatMoney(payment_summary.refunded_total) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">実質受領額</div><div class="font-weight-bold">{{ formatMoney(payment_summary.net_received) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">最終施術金額</div><div><EmptyValue v-if="payment_summary.final_amount === null" :label="MESSAGES.common.notSet" /><template v-else>{{ formatMoney(payment_summary.final_amount) }}</template></div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">差額</div><div>{{ formatMoney(payment_summary.delta) }}</div></v-col>
        </v-row>

        <v-form class="d-flex align-start ga-3 flex-wrap mb-5" @submit.prevent="submitAdjustment">
            <MoneyField
                v-model="adjustmentForm.final_amount"
                label="最終施術金額"
                :error-messages="adjustmentForm.errors.final_amount"
                style="max-width: 280px"
            />
            <v-btn
                type="submit"
                color="primary"
                height="56"
                :loading="adjustmentForm.processing"
                :disabled="adjustmentForm.processing"
            >
                差額を反映
            </v-btn>
        </v-form>

        <v-table density="compact">
            <thead>
                <tr>
                    <th>種類</th><th class="text-right">金額</th><th>状態</th>
                    <th>決済ID</th><th>請求ID</th><th class="text-right">返金済み</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="payment in payment_summary.payments" :key="payment.id">
                    <tr>
                        <td>
                            <a :href="`/admin/payments/${payment.id}`">{{ payment.kind_label }}</a>
                            <v-chip v-if="payment.needs_attention" color="warning" size="x-small" class="ml-2">要対応</v-chip>
                        </td>
                        <td class="text-right">{{ formatMoney(payment.amount) }}</td>
                        <td><StatusChip :status="payment.status" :label="payment.status_label" /></td>
                        <td class="text-caption">{{ payment.stripe_payment_intent_id ?? MESSAGES.common.notRecorded }}</td>
                        <td class="text-caption">{{ payment.stripe_charge_id ?? MESSAGES.common.notRecorded }}</td>
                        <td class="text-right">{{ formatMoney(payment.refunded_amount) }}</td>
                    </tr>
                    <tr v-for="(refund, index) in payment.refunds" :key="`${payment.id}-refund-${index}`" class="bg-surface-light">
                        <td class="pl-8 text-caption">↳ 返金：{{ refund.reason }}</td>
                        <td class="text-right text-caption">-{{ formatMoney(refund.amount) }}</td>
                        <td><StatusChip :status="refund.status" :label="refund.status" /></td>
                        <td colspan="3" class="text-caption">{{ refund.created_at ?? MESSAGES.common.notRecorded }}</td>
                    </tr>
                </template>
                <tr v-if="payment_summary.payments.length === 0">
                    <td colspan="6" class="text-center text-medium-emphasis py-6">{{ MESSAGES.payment.noHistory }}</td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>
    </div>

    <v-dialog :model-value="dialog !== null" max-width="520" @update:model-value="value => { if (!value) dialog = null; }">
        <v-card :title="actionTitle()">
            <v-card-text>
                <p>{{ dialog === 'complete' ? MESSAGES.visitCompletion.noCheckoutHint : MESSAGES.common.confirmAction }}</p>
                <v-radio-group v-if="dialog === 'complete'" v-model="exemptionReason" class="mt-2" density="compact" hide-details>
                    <v-radio v-for="(label, value) in MESSAGES.visitCompletion.exemptionReasons" :key="value" :label="label" :value="value" />
                </v-radio-group>
                <v-textarea
                    v-if="dialog === 'cancel'"
                    v-model="cancelReason"
                    class="mt-4"
                    label="キャンセル理由（任意）"
                    maxlength="255"
                    rows="2"
                />
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="dialog = null">戻る</v-btn>
                <v-btn
                    :color="dialog === 'cancel' ? 'error' : 'primary'"
                    :loading="actionProcessing"
                    :disabled="dialog === 'complete' && exemptionReason === null"
                    @click="runAction"
                >
                    実行
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
/* でかいモニターでも1カラムのまま最大幅を制限し、カードが横に間延びしないようにする。
   左寄せだと大画面で右側が余って見づらいため、中央寄せにする。 */
.reservation-page {
    max-width: 880px;
    margin-inline: auto;
}

.date-field {
    width: 260px;
    flex: 0 0 auto;
}

@media (max-width: 600px) {
    .date-field {
        width: 100%;
    }
}

.reservation-hero__row {
    display: flex;
    align-items: center;
    gap: var(--ark-space-4);
}

.reservation-hero__icon {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 999px;
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
}

.reservation-hero__body {
    flex: 1 1 auto;
    min-width: 0;
}

.reservation-hero__customer {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.3;
}

.reservation-hero__service {
    color: rgba(var(--v-theme-on-surface), 0.68);
    margin-top: 2px;
}

.reservation-hero__chips {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: var(--ark-space-2);
}

.reservation-hero__facts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--ark-space-4);
}

@media (max-width: 720px) {
    .reservation-hero__row {
        flex-wrap: wrap;
    }

    .reservation-hero__facts {
        grid-template-columns: 1fr;
    }
}

.field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 720px) {
    .field-grid {
        grid-template-columns: 1fr;
    }
}
</style>
