<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface StaffOption {
    id: number;
    display_name: string;
}

interface ServiceOption {
    id: number;
    name: string;
    duration_min: number;
    price: number;
    color: string | null;
    staff: StaffOption[];
}

interface AvailabilitySlot {
    starts_at: string;
    ends_at: string;
    available_staff_ids: number[];
}

interface TicketWalletOption {
    id: number;
    product_name: string;
    available: number;
    expires_at: string;
}

interface TicketAvailability {
    available_total: number;
    wallets: TicketWalletOption[];
}

interface MembershipAvailability {
    available: number;
    status: string | null;
}

type PaymentMethod = 'onsite' | 'ticket' | 'card' | 'membership';

const props = defineProps<{
    services: ServiceOption[];
    ticket: TicketAvailability;
    membership: MembershipAvailability;
}>();

const bookableMembershipStatuses = ['active', 'grace', 'canceling'];
const canUseMembership = computed(
    () => props.membership.available >= 1
        && props.membership.status !== null
        && bookableMembershipStatuses.includes(props.membership.status),
);

const step = ref(1);
const serviceId = ref<number | null>(null);
const staffId = ref<number | null>(null);
const date = ref('');
const slots = ref<AvailabilitySlot[]>([]);
const selectedStartsAt = ref<string | null>(null);
const loadingSlots = ref(false);
const availabilityError = ref('');

const selectedService = computed<ServiceOption | null>(() =>
    props.services.find((service) => service.id === serviceId.value) ?? null,
);

const serviceItems = computed(() =>
    props.services.map((service) => ({
        title: `${service.name}（${service.duration_min}分 / ${formatPrice(service.price)}）`,
        value: service.id,
    })),
);

const staffItems = computed<Array<{ title: string; value: number | null }>>(() => [
    { title: '指名なし', value: null },
    ...(selectedService.value?.staff.map((staff) => ({
        title: staff.display_name,
        value: staff.id,
    })) ?? []),
]);

const selectedStaffName = computed(() => {
    if (staffId.value === null) {
        return '指名なし（空いているスタッフを自動割当）';
    }

    return selectedService.value?.staff.find((staff) => staff.id === staffId.value)
        ?.display_name ?? '未選択';
});

const form = useForm({
    service_id: null as number | null,
    staff_id: null as number | null,
    starts_at: null as string | null,
    payment_method: 'onsite' as PaymentMethod,
    reservation: null as string | null,
});

watch(serviceId, () => {
    staffId.value = null;
    date.value = '';
    slots.value = [];
    selectedStartsAt.value = null;
});

watch(staffId, clearAvailability);
watch(date, clearAvailability);

function clearAvailability(): void {
    slots.value = [];
    selectedStartsAt.value = null;
    availabilityError.value = '';
}

function formatPrice(price: number): string {
    return `${new Intl.NumberFormat('ja-JP').format(price)}円`;
}

function formatDateTime(value: string): string {
    const parsed = new Date(value.replace(' ', 'T'));

    return new Intl.DateTimeFormat('ja-JP', {
        month: 'long',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}

function timeLabel(value: string): string {
    return value.slice(11, 16);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

function today(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

async function loadAvailability(): Promise<void> {
    if (serviceId.value === null || date.value === '') {
        return;
    }

    loadingSlots.value = true;
    availabilityError.value = '';
    selectedStartsAt.value = null;

    const params = new URLSearchParams({
        service_id: String(serviceId.value),
        date: date.value,
    });

    if (staffId.value !== null) {
        params.set('staff_id', String(staffId.value));
    }

    try {
        const response = await fetch(`/reserve/availability?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('空き時間を取得できませんでした。');
        }

        slots.value = (await response.json()) as AvailabilitySlot[];
        step.value = 4;
    } catch (error: unknown) {
        availabilityError.value = error instanceof Error
            ? error.message
            : '空き時間を取得できませんでした。';
    } finally {
        loadingSlots.value = false;
    }
}

function confirmSelection(): void {
    if (selectedStartsAt.value !== null) {
        step.value = 5;
    }
}

function submit(): void {
    if (serviceId.value === null || selectedStartsAt.value === null) {
        return;
    }

    form.service_id = serviceId.value;
    form.staff_id = staffId.value;
    form.starts_at = selectedStartsAt.value;
    form.post('/reserve', { errorBag: 'reservation' });
}
</script>

<template>
    <Head title="予約する" />

    <v-card class="reserve-card mx-auto" max-width="640">
        <div class="reserve-card__header">
            <v-card-title class="text-h5 pa-0">予約する</v-card-title>
            <v-card-subtitle class="pa-0 mt-1">
                サービスから順番に選択してください
            </v-card-subtitle>
        </div>

        <nav class="reserve-progress" aria-label="予約手順">
            <ol class="reserve-stepper">
                <li
                    class="reserve-stepper__item"
                    :class="{ 'is-current': step === 1, 'is-complete': step > 1 }"
                    :aria-current="step === 1 ? 'step' : undefined"
                >
                    <span class="reserve-stepper__marker" aria-hidden="true">
                        <span v-if="step > 1">✓</span>
                        <span v-else>1</span>
                    </span>
                    <span class="reserve-stepper__label">サービス</span>
                </li>
                <li
                    class="reserve-stepper__item"
                    :class="{ 'is-current': step === 2, 'is-complete': step > 2 }"
                    :aria-current="step === 2 ? 'step' : undefined"
                >
                    <span class="reserve-stepper__marker" aria-hidden="true">
                        <span v-if="step > 2">✓</span>
                        <span v-else>2</span>
                    </span>
                    <span class="reserve-stepper__label">スタッフ</span>
                </li>
                <li
                    class="reserve-stepper__item"
                    :class="{ 'is-current': step === 3, 'is-complete': step > 3 }"
                    :aria-current="step === 3 ? 'step' : undefined"
                >
                    <span class="reserve-stepper__marker" aria-hidden="true">
                        <span v-if="step > 3">✓</span>
                        <span v-else>3</span>
                    </span>
                    <span class="reserve-stepper__label">日付</span>
                </li>
                <li
                    class="reserve-stepper__item"
                    :class="{ 'is-current': step === 4, 'is-complete': step > 4 }"
                    :aria-current="step === 4 ? 'step' : undefined"
                >
                    <span class="reserve-stepper__marker" aria-hidden="true">
                        <span v-if="step > 4">✓</span>
                        <span v-else>4</span>
                    </span>
                    <span class="reserve-stepper__label">時間</span>
                </li>
                <li
                    class="reserve-stepper__item"
                    :class="{ 'is-current': step === 5, 'is-complete': step > 5 }"
                    :aria-current="step === 5 ? 'step' : undefined"
                >
                    <span class="reserve-stepper__marker" aria-hidden="true">
                        <span v-if="step > 5">✓</span>
                        <span v-else>5</span>
                    </span>
                    <span class="reserve-stepper__label">確認</span>
                </li>
            </ol>

            <div class="reserve-progress__mobile" aria-live="polite">
                <div class="reserve-progress__mobile-label">
                    <span>ステップ {{ step }} / 5</span>
                    <strong v-if="step === 1">サービス</strong>
                    <strong v-else-if="step === 2">スタッフ</strong>
                    <strong v-else-if="step === 3">日付</strong>
                    <strong v-else-if="step === 4">時間</strong>
                    <strong v-else>確認</strong>
                </div>
                <v-progress-linear
                    :model-value="step * 20"
                    color="primary"
                    height="4"
                    rounded
                />
            </div>
        </nav>

        <v-card-text class="reserve-card__body">
            <v-alert
                v-if="services.length === 0"
                type="info"
                variant="tonal"
            >
                現在オンライン予約できるサービスはありません。
            </v-alert>

            <section
                v-else-if="step === 1"
                class="reserve-section"
                aria-labelledby="service-step"
            >
                <h2 id="service-step" class="reserve-section__title text-subtitle-1">
                    サービスを選択
                </h2>
                <v-select
                    v-model="serviceId"
                    :items="serviceItems"
                    label="サービス"
                    :error-messages="form.errors.service_id"
                    class="reserve-field"
                />
                <v-btn
                    block
                    color="primary"
                    size="large"
                    :disabled="serviceId === null"
                    @click="step = 2"
                >
                    次へ
                </v-btn>
            </section>

            <section
                v-else-if="step === 2"
                class="reserve-section"
                aria-labelledby="staff-step"
            >
                <h2 id="staff-step" class="reserve-section__title text-subtitle-1">
                    担当スタッフを選択
                </h2>
                <v-select
                    v-model="staffId"
                    :items="staffItems"
                    label="担当スタッフ"
                    persistent-hint
                    hint="指名なしの場合は、予約確定時に空いているスタッフを割り当てます。"
                    :error-messages="form.errors.staff_id"
                    class="reserve-field"
                />
                <div class="reserve-actions">
                    <v-btn class="reserve-back-action" variant="text" @click="step = 1">戻る</v-btn>
                    <v-btn
                        color="primary"
                        size="large"
                        class="reserve-primary-action"
                        @click="step = 3"
                    >
                        次へ
                    </v-btn>
                </div>
            </section>

            <section
                v-else-if="step === 3"
                class="reserve-section"
                aria-labelledby="date-step"
            >
                <h2 id="date-step" class="reserve-section__title text-subtitle-1">
                    日付を選択
                </h2>
                <v-text-field
                    v-model="date"
                    type="date"
                    label="予約日"
                    :min="today()"
                    :error-messages="form.errors.starts_at"
                    class="reserve-field"
                />
                <v-alert
                    v-if="availabilityError"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ availabilityError }}
                </v-alert>
                <div class="reserve-actions">
                    <v-btn class="reserve-back-action" variant="text" @click="step = 2">戻る</v-btn>
                    <v-btn
                        color="primary"
                        size="large"
                        class="reserve-primary-action"
                        :disabled="date === ''"
                        :loading="loadingSlots"
                        @click="loadAvailability"
                    >
                        空き時間を見る
                    </v-btn>
                </div>
            </section>

            <section
                v-else-if="step === 4"
                class="reserve-section"
                aria-labelledby="slot-step"
            >
                <h2 id="slot-step" class="reserve-section__title text-subtitle-1">
                    空き時間を選択
                </h2>
                <v-alert
                    v-if="slots.length === 0"
                    type="info"
                    variant="tonal"
                    class="mb-4"
                >
                    選択日に予約できる時間はありません。
                </v-alert>
                <div v-else class="slot-grid">
                    <v-btn
                        v-for="slot in slots"
                        :key="slot.starts_at"
                        :variant="selectedStartsAt === slot.starts_at ? 'flat' : 'outlined'"
                        color="primary"
                        size="large"
                        @click="selectedStartsAt = slot.starts_at"
                    >
                        {{ timeLabel(slot.starts_at) }}
                    </v-btn>
                </div>
                <div class="reserve-actions">
                    <v-btn class="reserve-back-action" variant="text" @click="step = 3">戻る</v-btn>
                    <v-btn
                        color="primary"
                        size="large"
                        class="reserve-primary-action"
                        :disabled="selectedStartsAt === null"
                        @click="confirmSelection"
                    >
                        予約内容を確認
                    </v-btn>
                </div>
            </section>

            <section v-else class="reserve-section" aria-labelledby="confirm-step">
                <h2 id="confirm-step" class="reserve-section__title text-subtitle-1">
                    予約内容を確認
                </h2>
                <v-card class="reservation-summary" variant="flat">
                    <v-list lines="two" bg-color="transparent">
                        <v-list-item title="サービス" :subtitle="selectedService?.name" />
                        <v-list-item
                            title="所要時間"
                            :subtitle="`${selectedService?.duration_min}分`"
                        />
                        <v-list-item
                            title="料金"
                            :subtitle="formatPrice(selectedService?.price ?? 0)"
                        />
                        <v-list-item title="担当" :subtitle="selectedStaffName" />
                        <v-list-item
                            title="日時"
                            :subtitle="selectedStartsAt ? formatDateTime(selectedStartsAt) : ''"
                        />
                    </v-list>
                </v-card>
                <div class="payment-section">
                    <div class="text-subtitle-1 font-weight-bold mb-2">お支払い方法</div>
                    <v-radio-group
                        v-model="form.payment_method"
                        :error-messages="form.errors.payment_method"
                        class="mb-2"
                    >
                        <v-radio label="店頭でお支払い" value="onsite" />
                        <v-radio label="クレジットカードで事前に支払う" value="card" />
                        <v-radio
                            :label="`回数券を使う（残り ${ticket.available_total} 回）`"
                            value="ticket"
                            :disabled="ticket.available_total < 1"
                        />
                        <v-radio
                            :label="`利用権を使う（当期残り ${membership.available} 回）`"
                            value="membership"
                            :disabled="!canUseMembership"
                        />
                    </v-radio-group>
                </div>
                <v-alert
                    v-if="form.payment_method === 'card'"
                    type="info"
                    variant="tonal"
                    density="comfortable"
                    class="mt-2"
                >
                    次の画面でカード情報を入力します。お支払いが完了すると予約が確定します。
                    お支払いが完了するまで、枠は一時的に確保された状態です。
                </v-alert>
                <v-alert
                    v-if="ticket.available_total < 1"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                >
                    利用可能な回数券がないため、回数券は選択できません。
                </v-alert>
                <v-alert
                    v-else-if="form.payment_method === 'ticket'"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                >
                    <div
                        v-for="wallet in ticket.wallets"
                        :key="wallet.id"
                    >
                        {{ wallet.product_name }}：{{ wallet.available }}回
                        （有効期限 {{ formatDate(wallet.expires_at) }}）
                    </div>
                </v-alert>
                <v-alert
                    v-if="!canUseMembership"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                >
                    利用可能回数がないか、現在の状態では利用権を選択できません。
                </v-alert>
                <v-alert
                    v-else-if="form.payment_method === 'membership'"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                >
                    当期の利用権を 1 回分使用します。
                </v-alert>
                <v-alert
                    v-if="form.errors.reservation"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ form.errors.reservation }}
                </v-alert>
                <div class="reserve-actions">
                    <v-btn class="reserve-back-action" variant="text" @click="step = 4">戻る</v-btn>
                    <v-btn
                        color="primary"
                        size="large"
                        class="reserve-primary-action"
                        :loading="form.processing"
                        @click="submit"
                    >
                        予約する
                    </v-btn>
                </div>
            </section>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.reserve-card__header {
    padding: var(--ark-space-5) var(--ark-space-5) var(--ark-space-4);
}

.reserve-progress {
    padding: 0 var(--ark-space-5) var(--ark-space-5);
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.reserve-stepper {
    display: flex;
    padding: 0;
    margin: 0;
    list-style: none;
}

.reserve-stepper__item {
    position: relative;
    display: flex;
    flex: 1 1 0;
    flex-direction: column;
    align-items: center;
    gap: var(--ark-space-2);
    min-width: 0;
    color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
    font-size: 0.75rem;
    line-height: 1.4;
    text-align: center;
}

.reserve-stepper__item:not(:last-child)::after {
    position: absolute;
    z-index: 0;
    top: 15px;
    left: calc(50% + 18px);
    width: calc(100% - 36px);
    height: 2px;
    background: rgba(var(--v-border-color), var(--v-border-opacity));
    content: '';
}

.reserve-stepper__item.is-complete:not(:last-child)::after {
    background: rgb(var(--v-theme-primary));
}

.reserve-stepper__marker {
    position: relative;
    z-index: 1;
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 50%;
    background: rgb(var(--v-theme-surface-light));
    font-weight: 700;
}

.reserve-stepper__item.is-complete {
    color: rgb(var(--v-theme-primary));
}

.reserve-stepper__item.is-complete .reserve-stepper__marker {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.12);
}

.reserve-stepper__item.is-current {
    color: rgb(var(--v-theme-on-surface));
    font-weight: 700;
}

.reserve-stepper__item.is-current .reserve-stepper__marker {
    border-color: rgb(var(--v-theme-primary));
    background: rgb(var(--v-theme-primary));
    color: rgb(var(--v-theme-on-primary));
    box-shadow: 0 0 0 var(--ark-space-1) rgba(var(--v-theme-primary), 0.12);
}

.reserve-stepper__label {
    white-space: nowrap;
}

.reserve-progress__mobile {
    display: none;
}

.reserve-card__body {
    padding: var(--ark-space-5);
}

.reserve-section__title {
    margin: 0 0 var(--ark-space-4);
    font-weight: 700;
}

.reserve-field {
    margin-bottom: var(--ark-space-2);
}

.reserve-actions {
    display: flex;
    align-items: stretch;
    gap: var(--ark-space-3);
    margin-top: var(--ark-space-5);
}

.reserve-back-action {
    min-height: 44px;
}

.reserve-primary-action {
    flex: 1 1 auto;
    min-height: 44px;
}

.slot-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--ark-space-3);
    margin-bottom: var(--ark-space-5);
}

.slot-grid :deep(.v-btn) {
    min-height: 44px;
}

.reservation-summary {
    margin-bottom: var(--ark-space-5);
    overflow: hidden;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: rgb(var(--v-theme-surface-light));
    box-shadow: none;
}

.reservation-summary :deep(.v-list-item:not(:last-child)) {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.payment-section {
    margin-bottom: var(--ark-space-4);
}

.payment-section :deep(.v-selection-control) {
    min-height: 44px;
}

@media (max-width: 599px) {
    .reserve-card__header {
        padding: var(--ark-space-4) var(--ark-space-4) var(--ark-space-3);
    }

    .reserve-progress {
        padding: 0 var(--ark-space-4) var(--ark-space-4);
    }

    .reserve-stepper {
        display: none;
    }

    .reserve-progress__mobile {
        display: block;
    }

    .reserve-progress__mobile-label {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: var(--ark-space-3);
        margin-bottom: var(--ark-space-2);
        color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
        font-size: 0.8125rem;
    }

    .reserve-progress__mobile-label strong {
        color: rgb(var(--v-theme-on-surface));
        font-size: 0.875rem;
    }

    .reserve-card__body {
        padding: var(--ark-space-4);
    }

    .reserve-actions {
        flex-direction: column;
        gap: var(--ark-space-2);
    }

    .reserve-actions :deep(.v-btn) {
        width: 100%;
        min-height: 48px;
    }

    .reservation-summary :deep(.v-list-item) {
        padding-inline: var(--ark-space-4);
    }
}

@media (max-width: 420px) {
    .slot-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
