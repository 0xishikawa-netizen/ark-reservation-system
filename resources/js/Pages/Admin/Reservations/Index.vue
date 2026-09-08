<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface ReservationRow {
    id: number;
    starts_at: string;
    ends_at: string;
    customer_name: string;
    service_name: string;
    staff_id: number | null;
    staff_name: string | null;
    status: string;
    source: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface ReservationPaginator {
    data: ReservationRow[];
    current_page: number;
    last_page: number;
    total: number;
    links: PaginationLink[];
}

interface StaffOption {
    user_id: number;
    display_name: string;
}

interface Filters {
    date: string | null;
    staff_id: number | null;
    status: string | null;
}

const props = defineProps<{
    reservations: ReservationPaginator;
    staff: StaffOption[];
    filters: Filters;
}>();
const page = usePage();
const canManage = computed(() => page.props.auth.can.reservationsManage);

const date = ref(props.filters.date ?? '');
const staffId = ref<number | null>(props.filters.staff_id);
const status = ref<string | null>(props.filters.status);

const headers = [
    { title: '日時', key: 'starts_at', sortable: false },
    { title: '顧客', key: 'customer_name', sortable: false },
    { title: 'サービス', key: 'service_name', sortable: false },
    { title: '担当', key: 'staff_name', sortable: false },
    { title: '状態', key: 'status', sortable: false },
    { title: '予約元', key: 'source', sortable: false },
    { title: '', key: 'actions', sortable: false },
] as const;

const statusItems = [
    { title: '予約確定', value: 'confirmed' },
    { title: '完了', value: 'completed' },
    { title: 'No-show', value: 'no_show' },
    { title: 'キャンセル', value: 'canceled' },
    { title: '支払い待ち', value: 'pending_payment' },
    { title: '外部連携待ち', value: 'pending_external_sync' },
    { title: '期限切れ', value: 'expired' },
];

const statusLabels: Record<string, string> = {
    confirmed: '予約確定',
    completed: '完了',
    no_show: 'No-show',
    canceled: 'キャンセル',
    pending_payment: '支払い待ち',
    pending_external_sync: '外部連携待ち',
    expired: '期限切れ',
};

const sourceLabels: Record<string, string> = {
    ARK_WEB: 'ARK Web',
    ADMIN: '管理',
    HOTPEPPER: 'Hot Pepper',
    EPARK: 'EPARK',
    PEAK_MANAGER: 'Peak Manager',
};

const statusColors: Record<string, string> = {
    confirmed: 'primary',
    completed: 'success',
    no_show: 'warning',
    canceled: 'grey',
    pending_payment: 'orange',
    pending_external_sync: 'info',
    expired: 'grey-darken-1',
};

const sourceColors: Record<string, string> = {
    ARK_WEB: 'teal',
    ADMIN: 'deep-purple',
    HOTPEPPER: 'pink',
    EPARK: 'blue',
    PEAK_MANAGER: 'indigo',
};

const statusColor = (value: string): string => statusColors[value] ?? 'grey';
const sourceColor = (value: string): string => sourceColors[value] ?? 'grey';

function applyFilters(): void {
    router.get('/admin/reservations', {
        date: date.value || undefined,
        staff_id: staffId.value ?? undefined,
        status: status.value ?? undefined,
    }, { preserveState: true, replace: true });
}

function clearFilters(): void {
    date.value = '';
    staffId.value = null;
    status.value = null;
    applyFilters();
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));
}

function openReservation(reservationId: number): void {
    if (canManage.value) {
        router.visit(`/admin/reservations/${reservationId}/edit`);
    }
}
</script>

<template>
    <Head title="予約" />

    <div class="d-flex align-center justify-space-between mb-6 flex-wrap ga-3">
        <div>
            <h1 class="text-h4">予約</h1>
            <div class="text-medium-emphasis mt-1">全 {{ reservations.total }} 件</div>
        </div>
        <v-btn v-if="canManage" color="primary" href="/admin/reservations/create">
            新規予約
        </v-btn>
    </div>

    <v-card>
        <v-card-text>
            <v-form class="filter-grid" @submit.prevent="applyFilters">
                <v-text-field v-model="date" type="date" label="日付" hide-details />
                <v-select
                    v-model="staffId"
                    :items="staff"
                    item-title="display_name"
                    item-value="user_id"
                    label="担当"
                    clearable
                    hide-details
                />
                <v-select
                    v-model="status"
                    :items="statusItems"
                    label="状態"
                    clearable
                    hide-details
                />
                <div class="d-flex ga-2 align-center">
                    <v-btn type="submit" variant="tonal">絞り込む</v-btn>
                    <v-btn variant="text" @click="clearFilters">クリア</v-btn>
                </div>
            </v-form>
        </v-card-text>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="reservations.data"
            item-value="id"
            hide-default-footer
            hover
            no-data-text="該当する予約はありません。"
        >
            <template #item.starts_at="{ item }">
                {{ formatDateTime(item.starts_at) }}
            </template>
            <template #item.staff_name="{ item }">
                {{ item.staff_name ?? '担当なし' }}
            </template>
            <template #item.status="{ item }">
                <v-chip :color="statusColor(item.status)" size="small">
                    {{ statusLabels[item.status] ?? item.status }}
                </v-chip>
            </template>
            <template #item.source="{ item }">
                <v-chip :color="sourceColor(item.source)" size="small" variant="tonal">
                    {{ sourceLabels[item.source] ?? item.source }}
                </v-chip>
            </template>
            <template #item.customer_name="{ item }">
                <button
                    v-if="canManage"
                    type="button"
                    class="customer-link"
                    @click="openReservation(item.id)"
                >
                    {{ item.customer_name }}
                </button>
                <span v-else>{{ item.customer_name }}</span>
            </template>
            <template #item.actions="{ item }">
                <v-btn
                    v-if="canManage"
                    size="small"
                    variant="text"
                    @click="openReservation(item.id)"
                >
                    編集
                </v-btn>
            </template>
        </v-data-table>

        <v-divider />
        <div class="d-flex align-center justify-center ga-4 pa-4">
            <Link
                v-if="reservations.links[0]?.url"
                :href="reservations.links[0].url"
                preserve-scroll
            >
                前へ
            </Link>
            <span>{{ reservations.current_page }} / {{ reservations.last_page }}</span>
            <Link
                v-if="reservations.links[reservations.links.length - 1]?.url"
                :href="reservations.links[reservations.links.length - 1]?.url ?? ''"
                preserve-scroll
            >
                次へ
            </Link>
        </div>
    </v-card>
</template>

<style scoped>
.filter-grid {
    display: grid;
    grid-template-columns: minmax(160px, 1fr) minmax(180px, 1fr) minmax(180px, 1fr) auto;
    gap: 1rem;
}

.customer-link {
    color: rgb(var(--v-theme-primary));
    text-decoration: underline;
}

@media (max-width: 900px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
