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

    <v-card class="mx-auto" max-width="640">
        <v-card-title class="text-h5 pt-5">予約する</v-card-title>
        <v-card-subtitle>サービスから順番に選択してください</v-card-subtitle>
        <v-progress-linear
            :model-value="step * 20"
            color="primary"
            height="6"
            class="mt-4"
        />

        <v-card-text class="pa-5">
            <v-alert
                v-if="services.length === 0"
                type="info"
                variant="tonal"
            >
                現在オンライン予約できるサービスはありません。
            </v-alert>

            <section v-else-if="step === 1" aria-labelledby="service-step">
                <div id="service-step" class="text-subtitle-1 font-weight-bold mb-3">
                    1. サービスを選択
                </div>
                <v-select
                    v-model="serviceId"
                    :items="serviceItems"
                    label="サービス"
                    :error-messages="form.errors.service_id"
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

            <section v-else-if="step === 2" aria-labelledby="staff-step">
                <div id="staff-step" class="text-subtitle-1 font-weight-bold mb-3">
                    2. 担当スタッフを選択
                </div>
                <v-select
                    v-model="staffId"
                    :items="staffItems"
                    label="担当スタッフ"
                    persistent-hint
                    hint="指名なしの場合は、予約確定時に空いているスタッフを割り当てます。"
                    :error-messages="form.errors.staff_id"
                />
                <div class="d-flex ga-3 mt-4">
                    <v-btn variant="text" @click="step = 1">戻る</v-btn>
                    <v-btn color="primary" class="flex-grow-1" @click="step = 3">
                        次へ
                    </v-btn>
                </div>
            </section>

            <section v-else-if="step === 3" aria-labelledby="date-step">
                <div id="date-step" class="text-subtitle-1 font-weight-bold mb-3">
                    3. 日付を選択
                </div>
                <v-text-field
                    v-model="date"
                    type="date"
                    label="予約日"
                    :min="today()"
                    :error-messages="form.errors.starts_at"
                />
                <v-alert
                    v-if="availabilityError"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ availabilityError }}
                </v-alert>
                <div class="d-flex ga-3">
                    <v-btn variant="text" @click="step = 2">戻る</v-btn>
                    <v-btn
                        color="primary"
                        class="flex-grow-1"
                        :disabled="date === ''"
                        :loading="loadingSlots"
                        @click="loadAvailability"
                    >
                        空き時間を見る
                    </v-btn>
                </div>
            </section>

            <section v-else-if="step === 4" aria-labelledby="slot-step">
                <div id="slot-step" class="text-subtitle-1 font-weight-bold mb-3">
                    4. 空き時間を選択
                </div>
                <v-alert
                    v-if="slots.length === 0"
                    type="info"
                    variant="tonal"
                    class="mb-4"
                >
                    選択日に予約できる時間はありません。
                </v-alert>
                <div v-else class="slot-grid mb-5">
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
                <div class="d-flex ga-3">
                    <v-btn variant="text" @click="step = 3">戻る</v-btn>
                    <v-btn
                        color="primary"
                        class="flex-grow-1"
                        :disabled="selectedStartsAt === null"
                        @click="confirmSelection"
                    >
                        予約内容を確認
                    </v-btn>
                </div>
            </section>

            <section v-else aria-labelledby="confirm-step">
                <div id="confirm-step" class="text-subtitle-1 font-weight-bold mb-3">
                    5. 予約内容を確認
                </div>
                <v-list lines="two" class="mb-4">
                    <v-list-item title="サービス" :subtitle="selectedService?.name" />
                    <v-list-item title="所要時間" :subtitle="`${selectedService?.duration_min}分`" />
                    <v-list-item title="料金" :subtitle="formatPrice(selectedService?.price ?? 0)" />
                    <v-list-item title="担当" :subtitle="selectedStaffName" />
                    <v-list-item
                        title="日時"
                        :subtitle="selectedStartsAt ? formatDateTime(selectedStartsAt) : ''"
                    />
                </v-list>
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
                <div class="d-flex ga-3">
                    <v-btn variant="text" @click="step = 4">戻る</v-btn>
                    <v-btn
                        color="primary"
                        size="large"
                        class="flex-grow-1"
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
.slot-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

@media (max-width: 420px) {
    .slot-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
