<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface CustomerOption {
    user_id: number;
    name: string;
    kana: string | null;
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

interface StaffSelectOption {
    title: string;
    value: number | null;
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

interface Prefill {
    date: string | null;
    staff_id: number | null;
    starts_at: string | null;
}

const props = defineProps<{
    services: ServiceOption[];
    staff: StaffOption[];
    booths: BoothOption[];
    prefill: Prefill;
}>();

const customerItems = ref<CustomerOption[]>([]);
const customerSearch = ref('');
const loadingCustomers = ref(false);
const loadingSlots = ref(false);
const availabilityLoaded = ref(false);
const availabilityError = ref('');
const slots = ref<AvailabilitySlot[]>([]);
const selectedServiceId = ref<number | null>(null);
const selectedStaffId = ref<number | null>(props.prefill.staff_id);
const selectedBoothId = ref<number | null>(null);
const date = ref(props.prefill.date ?? props.prefill.starts_at?.slice(0, 10) ?? '');
let customerSearchTimer: ReturnType<typeof setTimeout> | null = null;

const form = useForm({
    customer_id: null as number | null,
    service_id: null as number | null,
    staff_id: props.prefill.staff_id,
    booth_id: null as number | null,
    starts_at: props.prefill.starts_at,
    notes: '',
    reservation: null as string | null,
});

const selectedService = computed<ServiceOption | null>(() =>
    props.services.find((service) => service.id === selectedServiceId.value) ?? null,
);

const staffItems = computed<StaffOption[]>(() => {
    if (selectedService.value === null) {
        return props.staff;
    }

    return props.staff.filter((staff) =>
        selectedService.value?.staff_ids.includes(staff.user_id),
    );
});

const staffSelectItems = computed<StaffSelectOption[]>(() => [
    { title: '指名なし（自動割当）', value: null },
    ...staffItems.value.map((staff) => ({
        title: staff.display_name,
        value: staff.user_id,
    })),
]);

watch(selectedServiceId, () => {
    form.service_id = selectedServiceId.value;

    if (selectedStaffId.value !== null
        && !staffItems.value.some((staff) => staff.user_id === selectedStaffId.value)) {
        selectedStaffId.value = null;
    }

    clearAvailability();
});

watch(selectedStaffId, clearAvailability);
watch(selectedBoothId, clearAvailability);
watch(date, clearAvailability);

function clearAvailability(): void {
    slots.value = [];
    form.starts_at = null;
    form.staff_id = selectedStaffId.value;
    form.booth_id = selectedBoothId.value;
    availabilityError.value = '';
    availabilityLoaded.value = false;
}

function updateCustomerSearch(value: string | null): void {
    customerSearch.value = value ?? '';

    if (customerSearchTimer !== null) {
        clearTimeout(customerSearchTimer);
    }

    customerSearchTimer = setTimeout(() => {
        void loadCustomers();
    }, 250);
}

async function loadCustomers(): Promise<void> {
    const query = customerSearch.value.trim();

    if (query === '') {
        customerItems.value = [];
        return;
    }

    loadingCustomers.value = true;

    try {
        const response = await fetch(
            `/admin/reservations/customer-search?q=${encodeURIComponent(query)}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error('顧客を検索できませんでした。');
        }

        customerItems.value = (await response.json()) as CustomerOption[];
    } catch {
        customerItems.value = [];
    } finally {
        loadingCustomers.value = false;
    }
}

async function loadAvailability(): Promise<void> {
    if (selectedServiceId.value === null || date.value === '') {
        return;
    }

    loadingSlots.value = true;
    availabilityLoaded.value = false;
    availabilityError.value = '';
    form.starts_at = null;

    const params = new URLSearchParams({
        service_id: String(selectedServiceId.value),
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

function assignedStaffName(): string | null {
    const assignedStaffId = form.staff_id;

    if (assignedStaffId === null || selectedStaffId.value !== null) {
        return null;
    }

    return props.staff.find((staff) => staff.user_id === assignedStaffId)?.display_name ?? null;
}

function timeLabel(value: string): string {
    return value.slice(11, 16);
}

function submit(): void {
    form.service_id = selectedServiceId.value;
    form.booth_id = selectedBoothId.value;
    form.post('/admin/reservations', { errorBag: 'reservation' });
}
</script>

<template>
    <Head title="新規予約" />

    <v-card max-width="840" title="新規予約">
        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-autocomplete
                    v-model="form.customer_id"
                    :items="customerItems"
                    item-title="name"
                    item-value="user_id"
                    label="顧客"
                    placeholder="氏名・カナ・電話番号で検索"
                    no-filter
                    :loading="loadingCustomers"
                    :error-messages="form.errors.customer_id"
                    @update:search="updateCustomerSearch"
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item
                            v-bind="itemProps"
                            :subtitle="item.raw.kana ?? undefined"
                        />
                    </template>
                </v-autocomplete>

                <v-select
                    v-model="selectedServiceId"
                    :items="services"
                    item-title="name"
                    item-value="id"
                    label="サービス"
                    :error-messages="form.errors.service_id"
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item
                            v-bind="itemProps"
                            :subtitle="`${item.raw.duration_min}分`"
                        />
                    </template>
                </v-select>

                <div class="field-grid">
                    <v-select
                        v-model="selectedStaffId"
                        :items="staffSelectItems"
                        label="担当スタッフ"
                        clearable
                        persistent-hint
                        hint="指名なしは空き枠選択時に自動割当します。"
                        :error-messages="form.errors.staff_id"
                    />
                    <v-select
                        v-model="selectedBoothId"
                        :items="booths"
                        item-title="name"
                        item-value="id"
                        label="ブース（任意）"
                        clearable
                        :error-messages="form.errors.booth_id"
                    />
                </div>

                <div class="d-flex ga-3 align-start flex-wrap">
                    <v-text-field
                        v-model="date"
                        type="date"
                        label="予約日"
                        class="flex-grow-1"
                        :error-messages="form.errors.starts_at"
                    />
                    <v-btn
                        color="primary"
                        variant="outlined"
                        height="56"
                        :disabled="selectedServiceId === null || date === ''"
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
                <div v-else class="slot-grid mb-4">
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
                    v-if="assignedStaffName()"
                    type="info"
                    variant="tonal"
                    class="mb-4"
                >
                    担当は {{ assignedStaffName() }} に自動割当されます。
                </v-alert>

                <v-textarea
                    v-model="form.notes"
                    label="備考"
                    maxlength="1000"
                    rows="3"
                    counter
                    :error-messages="form.errors.notes"
                />

                <v-alert
                    v-if="form.errors.reservation"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ form.errors.reservation }}
                </v-alert>

                <div class="d-flex ga-3">
                    <v-btn
                        type="submit"
                        color="primary"
                        :loading="form.processing"
                        :disabled="form.customer_id === null || form.starts_at === null"
                    >
                        予約を作成
                    </v-btn>
                    <v-btn variant="text" href="/admin/schedule">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
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
