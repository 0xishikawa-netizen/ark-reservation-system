<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, EmptyValue, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

interface PaymentRow {
    id: number;
    reservation_id: number | null;
    reservation_starts_at: string | null;
    reservation_status: string | null;
    customer_name: string | null;
    amount: number;
    currency: string;
    status: string;
    refunded_amount: number;
    needs_attention: boolean;
    created_at: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
}

interface FilteredCustomer {
    user_id: number;
    name: string;
}

const props = defineProps<{
    payments: Paginated<PaymentRow>;
    filters: { status: string; needs_attention: boolean; customer_id: number | null };
    filtered_customer: FilteredCustomer | null;
    statuses: string[];
    attention_count: number;
}>();

const status = ref(props.filters.status);
const needsAttention = ref(props.filters.needs_attention);

const M = MESSAGES.reportsUi.paymentIndex;
const statusLabels = M.statusLabels;

const applyFilters = (): void => {
    router.get(
        '/admin/payments',
        {
            status: status.value || undefined,
            needs_attention: needsAttention.value ? 1 : undefined,
            customer_id: props.filters.customer_id ?? undefined,
        },
        { preserveState: true, replace: true },
    );
};

const goToPage = (page: number): void => {
    router.get(
        '/admin/payments',
        {
            page,
            status: status.value || undefined,
            needs_attention: needsAttention.value ? 1 : undefined,
            customer_id: props.filters.customer_id ?? undefined,
        },
        { preserveState: true },
    );
};

const clearCustomerFilter = (): void => {
    router.get(
        '/admin/payments',
        {
            status: status.value || undefined,
            needs_attention: needsAttention.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head :title="M.title" />

    <v-container fluid class="ark-page py-4">
        <PageHeader :title="M.title" :subtitle="M.subtitle">
            <template #actions>
                <StatusChip
                v-if="attention_count > 0"
                    status="failed"
                    :label="fillMessage(M.attentionCount, { count: String(attention_count) })"
                />
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
            <strong>{{ filtered_customer.name }}</strong> {{ M.customerOnly }}
        </v-alert>

        <div class="ark-page__sections">
            <SectionCard :title="M.filter" variant="outlined">
                <div class="d-flex flex-wrap ga-4 align-center">
                    <v-select
                        v-model="status"
                        :items="[{ title: M.all, value: '' }, ...statuses.map((s) => ({ title: statusLabels[s] ?? s, value: s }))]"
                        :label="M.statusFilter"
                        density="compact"
                        hide-details
                        style="max-width: 240px"
                        @update:model-value="applyFilters"
                    />

                    <v-btn-toggle
                        :model-value="needsAttention ? 'attention' : 'all'"
                        density="compact"
                        variant="outlined"
                        divided
                        mandatory
                        @update:model-value="(v: string) => { needsAttention = v === 'attention'; applyFilters(); }"
                    >
                        <v-btn value="all" size="small">{{ M.showAll }}</v-btn>
                        <v-btn value="attention" size="small" color="error">
                            {{ M.attentionOnly }}
                            <v-chip
                                v-if="attention_count > 0"
                                size="x-small"
                                color="error"
                                variant="flat"
                                class="ml-2"
                            >
                                {{ attention_count }}
                            </v-chip>
                        </v-btn>
                    </v-btn-toggle>
                </div>
            </SectionCard>

            <SectionCard :title="M.listTitle" variant="outlined" class="ark-table-section">
                <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>{{ M.customer }}</th>
                        <th>{{ M.reservation }}</th>
                        <th class="text-right">{{ M.amount }}</th>
                        <th class="text-right">{{ M.refunded }}</th>
                        <th>{{ M.status }}</th>
                        <th>{{ M.createdAt }}</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="payment in payments.data" :key="payment.id">
                        <td>{{ payment.id }}</td>
                        <td><template v-if="payment.customer_name">{{ payment.customer_name }}</template><EmptyValue v-else :label="MESSAGES.common.notEntered" /></td>
                        <td>
                            <span v-if="payment.reservation_id">
                                #{{ payment.reservation_id }}
                                <span class="text-caption text-medium-emphasis">
                                    {{ payment.reservation_starts_at }}
                                </span>
                            </span>
                            <EmptyValue v-else :label="MESSAGES.common.notLinked" />
                        </td>
                        <td class="text-right">{{ payment.amount.toLocaleString() }}</td>
                        <td class="text-right">
                            {{ payment.refunded_amount.toLocaleString() }}
                        </td>
                        <td>
                            <StatusChip
                                :status="payment.status"
                                :label="statusLabels[payment.status] ?? payment.status"
                            />
                            <StatusChip
                                v-if="payment.needs_attention"
                                status="failed"
                                :label="M.needsAttention"
                                class="ml-1"
                            />
                        </td>
                        <td class="text-caption">{{ payment.created_at }}</td>
                        <td class="text-right">
                            <v-btn
                                size="small"
                                variant="tonal"
                                color="primary"
                                append-icon="mdi-chevron-right"
                                :href="`/admin/payments/${payment.id}`"
                                @click.prevent="router.get(`/admin/payments/${payment.id}`)"
                            >
                                {{ MESSAGES.customer.openDetail }}
                            </v-btn>
                        </td>
                    </tr>
                    <tr v-if="payments.data.length === 0">
                        <td colspan="8">
                            <EmptyState
                                icon="mdi-credit-card-search-outline"
                                :title="M.emptyTitle"
                                :description="M.emptyDescription"
                            />
                        </td>
                    </tr>
                </tbody>
                </v-table>
            </SectionCard>

            <v-pagination
                v-if="payments.last_page > 1"
                :model-value="payments.current_page"
                :length="payments.last_page"
                class="mt-4"
                @update:model-value="goToPage"
            />
        </div>
    </v-container>
</template>

<style scoped>
.ark-page__sections {
    display: grid;
    gap: var(--ark-space-4);
}

.ark-page__sections > * {
    margin-block: 0 !important;
}

.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
