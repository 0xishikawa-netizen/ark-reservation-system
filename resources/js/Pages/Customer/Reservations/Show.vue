<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { DateField } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: CustomerLayout });

interface ReservationDetail {
    id: number;
    service_id: number;
    service_name: string;
    duration_min: number;
    price: number;
    color: string | null;
    staff_id: number | null;
    staff_name: string | null;
    starts_at: string;
    ends_at: string;
    status: string;
    payment_method: string;
    version: number;
    cancel_reason: string | null;
    can_cancel: boolean;
    can_reschedule: boolean;
}

interface AvailabilitySlot {
    starts_at: string;
    ends_at: string;
    available_staff_ids: number[];
}

interface AddonPayment {
    id: number;
    amount: number;
    status: string;
}

const props = defineProps<{
    reservation: ReservationDetail;
    addon_payment: AddonPayment | null;
}>();

const changeOpen = ref(false);
const cancelOpen = ref(false);
const date = ref('');
const slots = ref<AvailabilitySlot[]>([]);
const loadingSlots = ref(false);
const availabilityError = ref('');

const changeForm = useForm({
    staff_id: props.reservation.staff_id,
    starts_at: null as string | null,
    version: props.reservation.version,
    reservation: null as string | null,
});

const cancelForm = useForm({
    reason: '',
    reservation: null as string | null,
});

const canModify = computed(() => {
    const startsAt = new Date(props.reservation.starts_at.replace(' ', 'T'));

    return props.reservation.can_cancel
        && props.reservation.can_reschedule
        && props.reservation.status === 'confirmed'
        && startsAt.getTime() > Date.now();
});

const statusLabels: Record<string, string> = {
    confirmed: '予約確定',
    completed: '完了',
    no_show: '来店なし',
    canceled: 'キャンセル',
    expired: '期限切れ',
};

/**
 * 日付が変わったら空き時間を自動で取り直す。「空き時間を見る」ボタンを押させる方式は
 * 何のためのボタンか分かりにくいため、他の予約フォームと同じく明示操作なしで
 * 最新の候補を出す（§空き時間の自動取得）。
 */
let availabilityTimer: ReturnType<typeof setTimeout> | null = null;

watch(date, () => {
    if (availabilityTimer !== null) {
        clearTimeout(availabilityTimer);
    }

    slots.value = [];
    changeForm.starts_at = null;
    availabilityError.value = '';

    if (date.value === '') {
        return;
    }

    availabilityTimer = setTimeout(() => void loadAvailability(), 150);
});

watch(() => props.reservation.version, (version) => {
    changeForm.version = version;
    changeForm.staff_id = props.reservation.staff_id;
    changeForm.starts_at = null;
    slots.value = [];
});

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

function formatPrice(price: number): string {
    return `${new Intl.NumberFormat('ja-JP').format(price)}円`;
}

function timeLabel(value: string): string {
    return value.slice(11, 16);
}

function today(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

async function loadAvailability(): Promise<void> {
    if (date.value === '') {
        return;
    }

    loadingSlots.value = true;
    availabilityError.value = '';
    changeForm.starts_at = null;

    const params = new URLSearchParams({
        service_id: String(props.reservation.service_id),
        date: date.value,
    });

    if (props.reservation.staff_id !== null) {
        params.set('staff_id', String(props.reservation.staff_id));
    }

    try {
        const response = await fetch(`/reserve/availability?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(MESSAGES.availability.loadFailed);
        }

        slots.value = (await response.json()) as AvailabilitySlot[];
    } catch (error: unknown) {
        availabilityError.value = error instanceof Error
            ? error.message
            : MESSAGES.availability.loadFailed;
    } finally {
        loadingSlots.value = false;
    }
}

const slotItems = computed(() => slots.value.map((slot) => ({
    title: timeLabel(slot.starts_at),
    value: slot.starts_at,
})));

function reschedule(): void {
    if (changeForm.starts_at === null) {
        return;
    }

    changeForm.put(`/mypage/reservations/${props.reservation.id}`, {
        errorBag: 'reservation',
        onSuccess: () => {
            changeOpen.value = false;
        },
    });
}

function cancelReservation(): void {
    cancelForm.delete(`/mypage/reservations/${props.reservation.id}`, {
        errorBag: 'reservation',
    });
}
</script>

<template>
    <Head title="予約詳細" />

    <v-alert
        v-if="addon_payment"
        type="warning"
        variant="tonal"
        class="mx-auto mb-4"
        max-width="640"
    >
        <div class="font-weight-bold mb-1">差額のお支払いが必要です</div>
        <div class="mb-3">{{ formatPrice(addon_payment.amount) }} のお支払い手続きをお願いします。</div>
        <v-btn
            color="primary"
            :href="`/mypage/reservations/${reservation.id}/addon/checkout`"
        >
            差額のお支払い
        </v-btn>
    </v-alert>

    <v-card class="mx-auto" max-width="640">
        <v-card-title class="d-flex align-center ga-3 pt-5">
            <span
                class="service-color"
                :style="{ backgroundColor: reservation.color ?? '#757575' }"
            />
            予約詳細
        </v-card-title>
        <v-card-text>
            <v-chip color="primary" class="mb-4">
                {{ statusLabels[reservation.status] ?? reservation.status }}
            </v-chip>
            <v-chip
                v-if="reservation.payment_method === 'ticket'"
                color="secondary"
                class="mb-4 ml-2"
            >
                お支払い：回数券
            </v-chip>
            <v-chip
                v-if="reservation.payment_method === 'membership'"
                color="secondary"
                class="mb-4 ml-2"
            >
                お支払い：利用権
            </v-chip>
            <v-list lines="two">
                <v-list-item title="日時" :subtitle="formatDateTime(reservation.starts_at)" />
                <v-list-item title="サービス" :subtitle="reservation.service_name" />
                <v-list-item title="所要時間" :subtitle="`${reservation.duration_min}分`" />
                <v-list-item title="料金" :subtitle="formatPrice(reservation.price)" />
                <v-list-item title="担当" :subtitle="reservation.staff_name ?? '未定'" />
                <v-list-item
                    v-if="reservation.cancel_reason"
                    title="キャンセル理由"
                    :subtitle="reservation.cancel_reason"
                />
            </v-list>

            <v-alert
                v-if="changeForm.errors.reservation"
                type="error"
                variant="tonal"
                class="mt-4"
            >
                {{ changeForm.errors.reservation }}
            </v-alert>
        </v-card-text>

        <v-card-actions class="pa-4 flex-wrap ga-2">
            <v-btn href="/mypage/reservations" variant="text">一覧へ戻る</v-btn>
            <v-spacer />
            <v-btn
                v-if="canModify"
                color="primary"
                variant="outlined"
                @click="changeOpen = true"
            >
                日時を変更
            </v-btn>
            <v-btn
                v-if="canModify"
                color="error"
                variant="outlined"
                @click="cancelOpen = true"
            >
                キャンセル
            </v-btn>
        </v-card-actions>
    </v-card>

    <v-dialog v-model="changeOpen" max-width="560">
        <v-card title="予約日時を変更">
            <v-card-text>
                <DateField
                    v-model="date"
                    label="変更後の日付"
                    :min="today()"
                    class="mb-4"
                />
                <v-select
                    v-if="date !== ''"
                    v-model="changeForm.starts_at"
                    :items="slotItems"
                    :loading="loadingSlots"
                    label="時間"
                    variant="outlined"
                    class="mb-4"
                    :disabled="slotItems.length === 0"
                />
                <v-alert
                    v-if="availabilityError"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ availabilityError }}
                </v-alert>
                <v-alert
                    v-else-if="changeForm.errors.reservation"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ changeForm.errors.reservation }}
                </v-alert>
                <v-alert
                    v-else-if="date && !loadingSlots && slots.length === 0"
                    type="info"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ MESSAGES.availability.noneOnDate }}
                </v-alert>
                <div v-if="changeForm.errors.starts_at" class="text-error mt-3">
                    {{ changeForm.errors.starts_at }}
                </div>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="changeOpen = false">閉じる</v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    :disabled="changeForm.starts_at === null"
                    :loading="changeForm.processing"
                    @click="reschedule"
                >
                    この日時に変更
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="cancelOpen" max-width="480">
        <v-card title="予約をキャンセルしますか？">
            <v-card-text>
                <p class="mb-4">{{ MESSAGES.reservation.cancelReleasesSlot }}</p>
                <v-textarea
                    v-model="cancelForm.reason"
                    label="理由（任意）"
                    maxlength="255"
                    rows="3"
                    :error-messages="cancelForm.errors.reason"
                />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelOpen = false">戻る</v-btn>
                <v-spacer />
                <v-btn
                    color="error"
                    :loading="cancelForm.processing"
                    @click="cancelReservation"
                >
                    キャンセルを確定
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.service-color {
    width: 0.75rem;
    height: 1.75rem;
    border-radius: 999px;
}

</style>
