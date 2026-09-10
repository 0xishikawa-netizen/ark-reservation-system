<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

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
}

interface ServiceOption {
    id: number;
    name: string;
    duration_min: number;
    requires_staff: boolean;
    staff_ids: number[];
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
const actionProcessing = ref(false);
const cancelReason = ref('');
const adjustmentForm = useForm({
    final_amount: props.payment_summary.final_amount ?? props.payment_summary.original_amount,
});

const service = computed<ServiceOption | null>(() =>
    props.services.find((item) => item.id === props.reservation.service_id) ?? null,
);

const eligibleStaff = computed(() => props.staff.filter((staff) =>
    service.value?.staff_ids.includes(staff.user_id) ?? false,
));

const form = useForm({
    starts_at: props.reservation.starts_at as string | null,
    staff_id: props.reservation.staff_id,
    booth_id: props.reservation.booth_id,
    version: props.reservation.version,
    notes: props.reservation.notes ?? '',
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

const statusLabels: Record<string, string> = {
    confirmed: '予約確定',
    completed: '完了',
    no_show: 'No-show',
    canceled: 'キャンセル',
    pending_payment: '支払い待ち',
    pending_external_sync: '外部連携待ち',
    expired: '期限切れ',
};

watch(selectedStaffId, clearResourceAvailability);
watch(selectedBoothId, clearResourceAvailability);
watch(date, clearDateAvailability);

function clearResourceAvailability(): void {
    slots.value = [];
    form.staff_id = selectedStaffId.value;
    form.booth_id = selectedBoothId.value;
    availabilityError.value = '';
    availabilityLoaded.value = false;
}

function clearDateAvailability(): void {
    slots.value = [];
    form.starts_at = null;
    availabilityError.value = '';
    availabilityLoaded.value = false;
}

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
            throw new Error('空き時間を取得できませんでした。');
        }

        slots.value = (await response.json()) as AvailabilitySlot[];
        availabilityLoaded.value = true;
    } catch (error: unknown) {
        availabilityError.value = error instanceof Error
            ? error.message
            : '空き時間を取得できませんでした。';
    } finally {
        loadingSlots.value = false;
    }
}

function selectSlot(slot: AvailabilitySlot): void {
    form.starts_at = slot.starts_at;
    form.staff_id = selectedStaffId.value ?? slot.available_staff_ids[0] ?? null;
    form.booth_id = selectedBoothId.value;
}

function submit(): void {
    form.put(`/admin/reservations/${props.reservation.id}`, {
        errorBag: 'reservation',
        preserveScroll: true,
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
        action === 'cancel' ? { reason: cancelReason.value } : {},
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
        complete: '来店済み（完了）に変更',
        'no-show': 'No-showに変更',
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
    <Head title="予約編集" />

    <div class="d-flex align-center justify-space-between mb-6 flex-wrap ga-3">
        <div>
            <h1 class="text-h4">予約編集 #{{ reservation.id }}</h1>
            <div class="text-medium-emphasis mt-1">
                {{ reservation.customer_name }}・{{ reservation.service_name }}
            </div>
        </div>
        <v-btn variant="text" :href="`/admin/schedule?date=${reservation.starts_at.slice(0, 10)}`">
            予約台帳へ
        </v-btn>
    </div>

    <v-row>
        <v-col cols="12" lg="8">
            <v-card title="日時・リソース・備考">
                <v-card-text>
                    <v-list density="compact" class="mb-4">
                        <v-list-item title="現在の日時" :subtitle="formatDateTime(reservation.starts_at)" />
                        <v-list-item title="状態" :subtitle="statusLabels[reservation.status] ?? reservation.status" />
                        <v-list-item title="予約元" :subtitle="reservation.source" />
                    </v-list>

                    <v-form @submit.prevent="submit">
                        <div class="field-grid">
                            <v-select
                                v-model="selectedStaffId"
                                :items="eligibleStaff"
                                item-title="display_name"
                                item-value="user_id"
                                label="担当スタッフ"
                                clearable
                                :disabled="!isConfirmed"
                                :error-messages="form.errors.staff_id"
                            />
                            <v-select
                                v-model="selectedBoothId"
                                :items="booths"
                                item-title="name"
                                item-value="id"
                                label="ブース（任意）"
                                clearable
                                :disabled="!isConfirmed"
                                :error-messages="form.errors.booth_id"
                            />
                        </div>

                        <div class="d-flex ga-3 align-start flex-wrap">
                            <v-text-field
                                v-model="date"
                                type="date"
                                label="変更日"
                                class="flex-grow-1"
                                :disabled="!isConfirmed"
                                :error-messages="form.errors.starts_at"
                            />
                            <v-btn
                                color="primary"
                                variant="outlined"
                                height="56"
                                :disabled="!isConfirmed || date === ''"
                                :loading="loadingSlots"
                                @click="loadAvailability"
                            >
                                空き時間を見る
                            </v-btn>
                        </div>

                        <v-alert v-if="availabilityError" type="error" variant="tonal" class="mb-4">
                            {{ availabilityError }}
                        </v-alert>
                        <v-alert
                            v-else-if="availabilityLoaded && slots.length === 0"
                            type="info"
                            variant="tonal"
                            class="mb-4"
                        >
                            選択日に予約できる時間はありません。
                        </v-alert>
                        <div v-if="slots.length > 0" class="slot-grid mb-4">
                            <v-btn
                                v-for="slot in slots"
                                :key="slot.starts_at"
                                :variant="form.starts_at === slot.starts_at ? 'flat' : 'outlined'"
                                color="primary"
                                @click="selectSlot(slot)"
                            >
                                {{ timeLabel(slot.starts_at) }}
                            </v-btn>
                        </div>
                        <v-alert
                            v-if="autoAssignedStaffName"
                            type="info"
                            variant="tonal"
                            class="mb-4"
                        >
                            担当は {{ autoAssignedStaffName }} に自動割当されます。
                        </v-alert>

                        <v-textarea
                            v-model="form.notes"
                            label="備考"
                            maxlength="1000"
                            counter
                            rows="4"
                            :error-messages="form.errors.notes"
                        />

                        <v-alert
                            v-if="form.errors.reservation"
                            type="error"
                            variant="tonal"
                            class="mb-4"
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

                        <v-btn
                            type="submit"
                            color="primary"
                            :loading="form.processing"
                            :disabled="!isConfirmed"
                        >
                            更新
                        </v-btn>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-col>

        <v-col cols="12" lg="4">
            <v-card title="予約ステータス">
                <v-card-text>
                    <v-alert v-if="!isConfirmed" type="info" variant="tonal" class="mb-4">
                        この予約は確定状態ではないため、変更操作はできません。
                    </v-alert>
                    <div class="d-flex flex-column ga-3">
                        <v-btn color="success" :disabled="!isConfirmed" @click="dialog = 'complete'">
                            来店（完了）
                        </v-btn>
                        <v-btn color="warning" variant="outlined" :disabled="!isConfirmed" @click="dialog = 'no-show'">
                            No-show
                        </v-btn>
                        <v-btn color="error" variant="outlined" :disabled="!isConfirmed" @click="dialog = 'cancel'">
                            キャンセル
                        </v-btn>
                    </div>
                </v-card-text>
            </v-card>
        </v-col>
    </v-row>

    <SectionCard
        title="決済サマリ"
        subtitle="最終施術金額と実質受領額の差額を追加決済または返金で調整します。"
        class="mt-6 ark-table-section"
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
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">capture総額</div><div>{{ formatMoney(payment_summary.captured_total) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">返金総額</div><div>{{ formatMoney(payment_summary.refunded_total) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">実質受領額</div><div class="font-weight-bold">{{ formatMoney(payment_summary.net_received) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">最終施術金額</div><div>{{ payment_summary.final_amount === null ? '未設定' : formatMoney(payment_summary.final_amount) }}</div></v-col>
            <v-col cols="6" md="2"><div class="text-caption text-medium-emphasis">差額</div><div>{{ formatMoney(payment_summary.delta) }}</div></v-col>
        </v-row>

        <v-form class="d-flex align-start ga-3 flex-wrap mb-5" @submit.prevent="submitAdjustment">
            <v-text-field
                v-model.number="adjustmentForm.final_amount"
                type="number"
                min="0"
                label="最終施術金額（円）"
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
                    <th>PaymentIntent</th><th>Charge</th><th class="text-right">返金済み</th>
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
                        <td class="text-caption">{{ payment.stripe_payment_intent_id ?? '—' }}</td>
                        <td class="text-caption">{{ payment.stripe_charge_id ?? '—' }}</td>
                        <td class="text-right">{{ formatMoney(payment.refunded_amount) }}</td>
                    </tr>
                    <tr v-for="(refund, index) in payment.refunds" :key="`${payment.id}-refund-${index}`" class="bg-surface-light">
                        <td class="pl-8 text-caption">↳ 返金：{{ refund.reason }}</td>
                        <td class="text-right text-caption">-{{ formatMoney(refund.amount) }}</td>
                        <td><StatusChip :status="refund.status" :label="refund.status" /></td>
                        <td colspan="3" class="text-caption">{{ refund.created_at ?? '—' }}</td>
                    </tr>
                </template>
                <tr v-if="payment_summary.payments.length === 0">
                    <td colspan="6" class="text-center text-medium-emphasis py-6">決済履歴はありません。</td>
                </tr>
            </tbody>
        </v-table>
    </SectionCard>

    <v-dialog :model-value="dialog !== null" max-width="520" @update:model-value="value => { if (!value) dialog = null; }">
        <v-card :title="actionTitle()">
            <v-card-text>
                <p>この操作を実行してよろしいですか？</p>
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
                    @click="runAction"
                >
                    実行
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.slot-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 0.65rem;
}

@media (max-width: 720px) {
    .field-grid {
        grid-template-columns: 1fr;
    }

    .slot-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>
