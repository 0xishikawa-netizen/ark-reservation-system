<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { DateField } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateTime, timeLabel } from '@/utils/dateFormat';
import { formatYenSuffix } from '@/utils/money';

defineOptions({ layout: CustomerLayout });

/** 日付変更後に空き時間を取りに行くまでの待ち時間（ミリ秒）。連続入力での多重取得を防ぐ。 */
const AVAILABILITY_DEBOUNCE_MS = 150;

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

const statusLabels: Record<string, string> = MESSAGES.customerUi.reservations.statuses;

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

    availabilityTimer = setTimeout(() => void loadAvailability(), AVAILABILITY_DEBOUNCE_MS);
});

watch(() => props.reservation.version, (version) => {
    changeForm.version = version;
    changeForm.staff_id = props.reservation.staff_id;
    changeForm.starts_at = null;
    slots.value = [];
});

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
    <Head :title="MESSAGES.customerUi.reservationShow.title" />

    <v-alert
        v-if="addon_payment"
        type="warning"
        variant="tonal"
        class="mx-auto mb-4"
        max-width="640"
    >
        <div class="font-weight-bold mb-1">{{ MESSAGES.customerUi.reservationShow.addonRequired }}</div>
        <div class="mb-3">{{ fillMessage(MESSAGES.customerUi.reservationShow.addonRequest, { amount: formatYenSuffix(addon_payment.amount) }) }}</div>
        <v-btn
            color="primary"
            :href="`/mypage/reservations/${reservation.id}/addon/checkout`"
        >
            {{ MESSAGES.customerUi.reservationShow.addonPay }}
        </v-btn>
    </v-alert>

    <v-card class="mx-auto" max-width="640">
        <v-card-title class="d-flex align-center ga-3 pt-5">
            <span
                class="service-color"
                :style="{ backgroundColor: reservation.color ?? '#757575' }"
            />
            {{ MESSAGES.customerUi.reservationShow.title }}
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
                {{ MESSAGES.customerUi.reservationShow.paidByTicket }}
            </v-chip>
            <v-chip
                v-if="reservation.payment_method === 'membership'"
                color="secondary"
                class="mb-4 ml-2"
            >
                {{ MESSAGES.customerUi.reservationShow.paidByMembership }}
            </v-chip>
            <v-list lines="two">
                <v-list-item :title="MESSAGES.customerUi.reservationShow.dateTime" :subtitle="formatDateTime(reservation.starts_at, 'long')" />
                <v-list-item :title="MESSAGES.customerUi.reservationShow.service" :subtitle="reservation.service_name" />
                <v-list-item :title="MESSAGES.customerUi.reservationShow.duration" :subtitle="fillMessage(MESSAGES.customerUi.reservationShow.minutes, { min: String(reservation.duration_min) })" />
                <v-list-item :title="MESSAGES.customerUi.reservationShow.price" :subtitle="formatYenSuffix(reservation.price)" />
                <v-list-item :title="MESSAGES.customerUi.reservationShow.staff" :subtitle="reservation.staff_name ?? MESSAGES.customerUi.reservations.staffUndecided" />
                <v-list-item
                    v-if="reservation.cancel_reason"
                    :title="MESSAGES.customerUi.reservationShow.cancelReason"
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
            <v-btn href="/mypage/reservations" variant="text">{{ MESSAGES.customerUi.reservationShow.backToList }}</v-btn>
            <v-spacer />
            <v-btn
                v-if="canModify"
                color="primary"
                variant="outlined"
                @click="changeOpen = true"
            >
                {{ MESSAGES.customerUi.bookingConfirmation.reschedule }}
            </v-btn>
            <v-btn
                v-if="canModify"
                color="error"
                variant="outlined"
                @click="cancelOpen = true"
            >
                {{ MESSAGES.customerUi.bookingConfirmation.cancel }}
            </v-btn>
        </v-card-actions>
    </v-card>

    <v-dialog v-model="changeOpen" max-width="560">
        <v-card :title="MESSAGES.customerUi.bookingConfirmation.rescheduleTitle">
            <v-card-text>
                <DateField
                    v-model="date"
                    :label="MESSAGES.customerUi.bookingConfirmation.newDate"
                    :min="today()"
                    class="mb-4"
                />
                <v-select
                    v-if="date !== ''"
                    v-model="changeForm.starts_at"
                    :items="slotItems"
                    :loading="loadingSlots"
                    :label="MESSAGES.customerUi.reservationShow.time"
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
                <v-btn variant="text" @click="changeOpen = false">{{ MESSAGES.customerUi.bookingConfirmation.close }}</v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    :disabled="changeForm.starts_at === null"
                    :loading="changeForm.processing"
                    @click="reschedule"
                >
                    {{ MESSAGES.customerUi.bookingConfirmation.rescheduleSubmit }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="cancelOpen" max-width="480">
        <v-card :title="MESSAGES.customerUi.reservationShow.cancelTitle">
            <v-card-text>
                <p class="mb-4">{{ MESSAGES.reservation.cancelReleasesSlot }}</p>
                <v-textarea
                    v-model="cancelForm.reason"
                    :label="MESSAGES.customerUi.bookingConfirmation.reasonOptional"
                    maxlength="255"
                    rows="3"
                    :error-messages="cancelForm.errors.reason"
                />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelOpen = false">{{ MESSAGES.customerUi.bookingConfirmation.back }}</v-btn>
                <v-spacer />
                <v-btn
                    color="error"
                    :loading="cancelForm.processing"
                    @click="cancelReservation"
                >
                    {{ MESSAGES.customerUi.bookingConfirmation.cancelSubmit }}
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
