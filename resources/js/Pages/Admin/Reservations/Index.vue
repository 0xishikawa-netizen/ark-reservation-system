<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    reservationSourceColor,
    reservationSourceLabel,
    reservationStatusLabel,
    statusColor,
} from '@/design/tokens';
import { DateField, PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateTime } from '@/utils/dateFormat';

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
    customer_id: number | null;
}

interface FilteredCustomer {
    user_id: number;
    name: string;
}

const props = defineProps<{
    reservations: ReservationPaginator;
    staff: StaffOption[];
    filters: Filters;
    filtered_customer: FilteredCustomer | null;
}>();
const page = usePage();
const canManage = computed(() => page.props.auth.can.reservationsManage);

const date = ref(props.filters.date ?? '');
const staffId = ref<number | null>(props.filters.staff_id);
const status = ref<string | null>(props.filters.status);

const headers = [
    { title: MESSAGES.boardUi.reservationIndex.dateTime, key: 'starts_at', sortable: false },
    { title: MESSAGES.boardUi.reservationIndex.customer, key: 'customer_name', sortable: false },
    { title: MESSAGES.boardUi.reservationIndex.menu, key: 'service_name', sortable: false },
    { title: MESSAGES.boardUi.reservationIndex.staff, key: 'staff_name', sortable: false },
    { title: MESSAGES.boardUi.reservationIndex.status, key: 'status', sortable: false },
    { title: MESSAGES.boardUi.reservationIndex.source, key: 'source', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

const statusItems = [
    { title: MESSAGES.boardUi.reservationIndex.confirmed, value: 'confirmed' },
    { title: MESSAGES.boardUi.reservationIndex.completed, value: 'completed' },
    { title: MESSAGES.boardUi.reservationIndex.noShow, value: 'no_show' },
    { title: MESSAGES.boardUi.reservationIndex.canceled, value: 'canceled' },
    { title: MESSAGES.boardUi.reservationIndex.pendingPayment, value: 'pending_payment' },
    { title: MESSAGES.boardUi.reservationIndex.pendingExternalSync, value: 'pending_external_sync' },
    { title: MESSAGES.boardUi.reservationIndex.expired, value: 'expired' },
];

function applyFilters(): void {
    router.get('/admin/reservations', {
        date: date.value || undefined,
        staff_id: staffId.value ?? undefined,
        status: status.value ?? undefined,
        customer_id: props.filters.customer_id ?? undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

function clearFilters(): void {
    date.value = '';
    staffId.value = null;
    status.value = null;
    applyFilters();
}

function clearCustomerFilter(): void {
    router.get('/admin/reservations', {
        date: date.value || undefined,
        staff_id: staffId.value ?? undefined,
        status: status.value ?? undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

function goToPage(targetPage: number): void {
    router.get('/admin/reservations', {
        page: targetPage,
        date: date.value || undefined,
        staff_id: staffId.value ?? undefined,
        status: status.value ?? undefined,
        customer_id: props.filters.customer_id ?? undefined,
    }, { preserveState: true, preserveScroll: true });
}

function openReservation(reservationId: number): void {
    if (canManage.value) {
        router.visit(`/admin/reservations/${reservationId}/edit`);
    }
}
</script>

<template>
    <Head :title="MESSAGES.boardUi.reservationIndex.title" />

    <PageHeader :title="MESSAGES.boardUi.reservationIndex.title" :subtitle="fillMessage(MESSAGES.boardUi.reservationIndex.total, { count: String(reservations.total) })">
        <template #actions>
            <!-- 予約はブッキングボードから取る（専用の新規予約画面は廃止）。 -->
            <v-btn
                v-if="canManage"
                color="primary"
                prepend-icon="mdi-calendar-month-outline"
                href="/admin/schedule?panel=create"
            >
                {{ MESSAGES.boardUi.reservationIndex.bookOnBoard }}
            </v-btn>
        </template>
    </PageHeader>

    <v-alert
        v-if="filtered_customer"
        type="info"
        variant="tonal"
        closable
        class="mb-4"
        @click:close="clearCustomerFilter"
    >
        <strong>{{ filtered_customer.name }}</strong>{{ MESSAGES.boardUi.reservationIndex.customerFilterSuffix }}
    </v-alert>

    <SectionCard :title="MESSAGES.boardUi.reservationIndex.listTitle" class="ark-table-section">
        <div class="ark-table-section__filters">
            <v-form class="filter-grid" @submit.prevent="applyFilters">
                <div class="filter-grid__date">
                    <DateField
                        v-model="date"
                        :label="MESSAGES.boardUi.reservationIndex.date"
                        @update:model-value="applyFilters"
                    />
                </div>
                <v-select
                    v-model="staffId"
                    :items="staff"
                    item-title="display_name"
                    item-value="user_id"
                    :label="MESSAGES.boardUi.reservationIndex.staff"
                    clearable
                    hide-details
                    class="filter-grid__staff"
                    @update:model-value="applyFilters"
                />
                <v-select
                    v-model="status"
                    :items="statusItems"
                    :label="MESSAGES.boardUi.reservationIndex.status"
                    clearable
                    hide-details
                    class="filter-grid__status"
                    @update:model-value="applyFilters"
                />
                <v-btn variant="text" @click="clearFilters">{{ MESSAGES.boardUi.reservationIndex.clear }}</v-btn>
            </v-form>
        </div>

        <v-divider />

        <v-data-table
            :headers="headers"
            :items="reservations.data"
            item-value="id"
            hide-default-footer
            hover
            :no-data-text="MESSAGES.boardUi.reservationIndex.noData"
        >
            <template #item.starts_at="{ item }">
                {{ formatDateTime(item.starts_at, 'monthDayWeekday') }}
            </template>
            <template #item.staff_name="{ item }">
                {{ item.staff_name ?? MESSAGES.boardUi.reservationIndex.unassignedStaff }}
            </template>
            <template #item.status="{ item }">
                <v-chip :color="statusColor(item.status)" size="small">
                    {{ reservationStatusLabel(item.status) }}
                </v-chip>
            </template>
            <template #item.source="{ item }">
                <v-chip :color="reservationSourceColor(item.source)" size="small" variant="tonal">
                    {{ reservationSourceLabel(item.source) }}
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
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    @click="openReservation(item.id)"
                >
                    {{ MESSAGES.boardUi.reservationIndex.edit }}
                </v-btn>
            </template>
        </v-data-table>

        <template v-if="reservations.last_page > 1">
            <v-divider />
            <div class="d-flex justify-center pa-4">
                <v-pagination
                    :model-value="reservations.current_page"
                    :length="reservations.last_page"
                    :total-visible="7"
                    density="comfortable"
                    rounded="circle"
                    @update:model-value="goToPage"
                />
            </div>
        </template>
    </SectionCard>
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}

.ark-table-section__filters {
    padding: var(--ark-space-4);
}

.filter-grid {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
}

.filter-grid__date,
.filter-grid__staff,
.filter-grid__status {
    width: 240px;
    flex: 0 0 auto;
}

.customer-link {
    color: rgb(var(--v-theme-primary));
    text-decoration: underline;
}

@media (max-width: 900px) {
    .filter-grid__date,
    .filter-grid__staff,
    .filter-grid__status {
        width: 100%;
    }
}
</style>
