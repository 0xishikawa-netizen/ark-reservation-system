<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { DateField, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { reservationStatusLabel } from '@/design/tokens';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: GuestBookingLayout });

interface ReservationSummary {
    id: number;
    date: string;
    time: string;
    service_name: string;
    staff_name: string | null;
    status: string;
    payment_status: string;
    payment_method: string;
    version: number;
    can_cancel: boolean;
    can_reschedule: boolean;
}

interface ManagementProps {
    availability_url: string;
    staff_id: number | null;
    reschedule_url: string;
    cancel_url: string;
    checkout_url: string;
    upgrade_url: string;
}

interface MemberUpgradeProps {
    available: boolean;
    email: string;
    email_required: boolean;
}

interface AvailabilitySlot {
    starts_at: string;
    ends_at: string;
    available_staff_ids: number[];
}

const props = defineProps<{
    reservation: ReservationSummary;
    management: ManagementProps;
    member_upgrade: MemberUpgradeProps;
}>();

const changeOpen = ref(false);
const cancelOpen = ref(false);
const date = ref('');
const slots = ref<AvailabilitySlot[]>([]);
const loadingSlots = ref(false);
const availabilityError = ref('');
const upgradeVisible = ref(true);
const upgradeOpen = ref(false);

const changeForm = useForm({
    staff_id: props.management.staff_id,
    starts_at: null as string | null,
    version: props.reservation.version,
    reservation: null as string | null,
});

const cancelForm = useForm({
    reason: '',
    reservation: null as string | null,
});

const upgradeForm = useForm({
    email: props.member_upgrade.email,
    password: '',
    password_confirmation: '',
});

const headline = computed(() => {
    if (props.reservation.status === 'confirmed') {
        return '予約完了';
    }
    if (props.reservation.status === 'pending_payment') {
        return '予約枠を確保しました';
    }
    if (props.reservation.status === 'canceled') {
        return 'キャンセル済み';
    }

    return '予約状況';
});

const paymentStatusLabels: Record<string, string> = {
    unpaid: '来店時支払い',
    pending_payment: '支払い待ち',
    authorized: '予約確保中',
    paid: '支払い完了',
    failed: '支払い失敗',
    voided: '取消済み',
    refunded: '返金済み',
    partially_refunded: '一部返金済み',
};

const paymentMethodLabel = computed(() =>
    props.reservation.payment_method === 'single'
        ? 'オンライン事前決済'
        : '来店時に支払う',
);

const showCheckout = computed(() =>
    props.reservation.payment_method === 'single'
        && props.reservation.status === 'pending_payment',
);

const cancelDialogTitle = computed(() =>
    props.reservation.status === 'pending_payment'
        ? 'この仮予約をキャンセルしますか？'
        : '本当にキャンセルしますか？',
);

const cancelDialogDescription = computed(() =>
    props.reservation.status === 'pending_payment'
        ? '仮予約をキャンセルすると、確保中の予約枠は解放されます。'
        : MESSAGES.reservation.cancelReleasesSlot,
);

const showUpgrade = computed(() =>
    props.member_upgrade.available
        && props.reservation.status === 'confirmed'
        && upgradeVisible.value,
);

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
    changeForm.staff_id = props.management.staff_id;
    changeForm.starts_at = null;
    slots.value = [];
});

function formatReservationDate(dateValue: string, timeValue: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(`${dateValue}T${timeValue}:00`));
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

    const url = new URL(props.management.availability_url, window.location.origin);
    url.searchParams.set('date', date.value);

    try {
        const response = await fetch(url.toString(), {
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

function reschedule(): void {
    if (changeForm.starts_at === null) {
        return;
    }

    changeForm.put(props.management.reschedule_url, {
        errorBag: 'reservation',
        preserveScroll: true,
        onSuccess: () => {
            changeOpen.value = false;
            date.value = '';
        },
    });
}

function cancelReservation(): void {
    cancelForm.delete(props.management.cancel_url, {
        errorBag: 'reservation',
        preserveScroll: true,
        onSuccess: () => {
            cancelOpen.value = false;
        },
    });
}

function upgrade(): void {
    upgradeForm.post(props.management.upgrade_url, {
        errorBag: 'memberUpgrade',
        onFinish: () => upgradeForm.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="予約状況" />

    <PageHeader
        :title="headline"
        subtitle="このページは予約内容の確認・変更に使用できます。URLを大切に保管してください。"
    />

    <SectionCard class="mb-4">
        <div class="d-flex flex-wrap align-center ga-2 mb-4">
            <StatusChip
                :status="reservation.status"
                :label="reservationStatusLabel(reservation.status)"
            />
            <StatusChip
                :status="reservation.payment_status"
                :label="paymentStatusLabels[reservation.payment_status] ?? reservation.payment_status"
            />
        </div>

        <v-list lines="two" bg-color="transparent" class="reservation-details">
            <v-list-item
                title="日時"
                :subtitle="formatReservationDate(reservation.date, reservation.time)"
            />
            <v-list-item title="メニュー" :subtitle="reservation.service_name" />
            <v-list-item title="担当" :subtitle="reservation.staff_name ?? 'お任せ'" />
            <v-list-item title="お支払い" :subtitle="paymentMethodLabel" />
            <v-list-item title="予約番号" :subtitle="String(reservation.id)" />
        </v-list>

        <v-alert
            v-if="showCheckout"
            type="warning"
            variant="tonal"
            class="mt-4"
        >
            {{ MESSAGES.reservation.notYetConfirmed }}
        </v-alert>

        <v-btn
            v-if="showCheckout"
            :href="management.checkout_url"
            color="primary"
            size="large"
            block
            class="mt-4"
        >
            お支払いを完了する
        </v-btn>

        <div v-if="reservation.can_reschedule || reservation.can_cancel" class="management-actions mt-5">
            <v-btn
                v-if="reservation.can_reschedule"
                color="accent"
                variant="outlined"
                size="large"
                @click="changeOpen = true"
            >
                日時を変更
            </v-btn>
            <v-btn
                v-if="reservation.can_cancel"
                color="error"
                variant="outlined"
                size="large"
                @click="cancelOpen = true"
            >
                キャンセル
            </v-btn>
        </div>
    </SectionCard>

    <SectionCard
        v-if="showUpgrade"
        title="次回から入力不要にしませんか？"
        subtitle="この予約のお客様情報をそのまま使って会員登録できます。"
        class="upgrade-card"
    >
        <ul class="upgrade-benefits text-body-2">
            <li>予約履歴・次回予約の確認</li>
            <li>決済履歴の確認・再予約</li>
            <li>次回から登録情報の再入力が不要</li>
        </ul>

        <v-expand-transition>
            <v-form v-if="upgradeOpen" class="mt-5" @submit.prevent="upgrade">
                <v-text-field
                    v-model="upgradeForm.email"
                    label="メールアドレス"
                    type="email"
                    autocomplete="email"
                    :hint="member_upgrade.email_required ? '確認メールを受け取れるアドレスを入力してください' : '予約時のメールアドレスを使用できます'"
                    persistent-hint
                    :required="member_upgrade.email_required"
                    :error-messages="upgradeForm.errors.email"
                />
                <v-text-field
                    v-model="upgradeForm.password"
                    label="パスワード"
                    type="password"
                    autocomplete="new-password"
                    :error-messages="upgradeForm.errors.password"
                    required
                />
                <v-text-field
                    v-model="upgradeForm.password_confirmation"
                    label="パスワード（確認）"
                    type="password"
                    autocomplete="new-password"
                    required
                />
                <v-btn
                    type="submit"
                    color="primary"
                    size="large"
                    block
                    :loading="upgradeForm.processing"
                >
                    会員登録を完了する
                </v-btn>
            </v-form>
        </v-expand-transition>

        <div v-if="!upgradeOpen" class="upgrade-actions mt-5">
            <v-btn color="primary" size="large" @click="upgradeOpen = true">
                会員登録する
            </v-btn>
            <v-btn variant="text" size="large" @click="upgradeVisible = false">
                今はしない
            </v-btn>
        </div>
        <v-btn v-else variant="text" block class="mt-2" @click="upgradeOpen = false">
            入力を閉じる
        </v-btn>
    </SectionCard>

    <v-dialog v-model="changeOpen" max-width="560">
        <v-card title="予約日時を変更">
            <v-card-text>
                <DateField
                    v-model="date"
                    label="変更後の日付"
                    :min="today()"
                    class="mb-4"
                />
                <p v-if="loadingSlots" class="text-body-2 text-medium-emphasis mb-4">
                    空き時間を確認しています…
                </p>
                <v-alert v-if="availabilityError" type="error" variant="tonal" class="mb-4">
                    {{ availabilityError }}
                </v-alert>
                <v-alert
                    v-else-if="date && !loadingSlots && slots.length === 0"
                    type="info"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ MESSAGES.availability.noneOnDate }}
                </v-alert>
                <div v-else class="slot-grid">
                    <v-btn
                        v-for="slot in slots"
                        :key="slot.starts_at"
                        :variant="changeForm.starts_at === slot.starts_at ? 'flat' : 'outlined'"
                        color="primary"
                        @click="changeForm.starts_at = slot.starts_at"
                    >
                        {{ timeLabel(slot.starts_at) }}
                    </v-btn>
                </div>
                <v-alert
                    v-if="changeForm.errors.reservation"
                    type="error"
                    variant="tonal"
                    class="mt-4"
                >
                    {{ changeForm.errors.reservation }}
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
        <v-card :title="cancelDialogTitle">
            <v-card-text>
                <p class="mb-4">{{ cancelDialogDescription }}</p>
                <v-textarea
                    v-model="cancelForm.reason"
                    label="理由（任意）"
                    maxlength="255"
                    rows="3"
                    :error-messages="cancelForm.errors.reason"
                />
                <v-alert
                    v-if="cancelForm.errors.reservation"
                    type="error"
                    variant="tonal"
                    class="mt-4"
                >
                    {{ cancelForm.errors.reservation }}
                </v-alert>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelOpen = false">戻る</v-btn>
                <v-spacer />
                <v-btn color="error" :loading="cancelForm.processing" @click="cancelReservation">
                    キャンセルを確定
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.reservation-details {
    padding: 0;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: var(--ark-radius-md);
    overflow: hidden;
}

.reservation-details :deep(.v-list-item:not(:last-child)) {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.management-actions,
.upgrade-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ark-space-3);
}

.management-actions :deep(.v-btn),
.upgrade-actions :deep(.v-btn) {
    min-height: 44px;
}

.upgrade-card {
    border-color: rgba(var(--v-theme-primary), 0.28);
}

.upgrade-benefits {
    display: grid;
    gap: var(--ark-space-2);
    margin: 0;
    padding-left: var(--ark-space-5);
}

.slot-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--ark-space-3);
}

.slot-grid :deep(.v-btn) {
    min-height: 44px;
}

@media (max-width: 599px) {
    .management-actions,
    .upgrade-actions {
        flex-direction: column;
    }

    .management-actions :deep(.v-btn),
    .upgrade-actions :deep(.v-btn) {
        width: 100%;
        min-height: 48px;
    }
}

@media (max-width: 420px) {
    .slot-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
