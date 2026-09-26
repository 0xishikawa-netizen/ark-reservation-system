<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

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

const statusLabels: Record<string, string> = {
    pending: '手続き中',
    authorized: 'カード仮押さえ（未請求）',
    succeeded: '支払い済み',
    voided: '仮押さえ取消',
    failed: '失敗',
    partially_refunded: '一部返金済み',
    refunded: '全額返金済み',
};

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
    <Head title="決済管理" />

    <v-container fluid class="ark-page py-4">
        <PageHeader title="決済管理" subtitle="決済状況と要対応項目を確認します。">
            <template #actions>
                <StatusChip
                v-if="attention_count > 0"
                    status="failed"
                    :label="`要対応 ${attention_count} 件`"
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
            <strong>{{ filtered_customer.name }}</strong> 様の決済のみ表示しています。
        </v-alert>

        <div class="ark-page__sections">
            <SectionCard title="絞り込み" variant="outlined">
                <div class="d-flex flex-wrap ga-4 align-center">
                    <v-select
                        v-model="status"
                        :items="[{ title: 'すべて', value: '' }, ...statuses.map((s) => ({ title: statusLabels[s] ?? s, value: s }))]"
                        label="ステータス"
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
                        <v-btn value="all" size="small">すべて表示</v-btn>
                        <v-btn value="attention" size="small" color="error">
                            要対応のみ
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

            <SectionCard title="決済一覧" variant="outlined" class="ark-table-section">
                <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>顧客</th>
                        <th>予約</th>
                        <th class="text-right">金額</th>
                        <th class="text-right">返金済</th>
                        <th>状態</th>
                        <th>作成</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="payment in payments.data" :key="payment.id">
                        <td>{{ payment.id }}</td>
                        <td>{{ payment.customer_name ?? MESSAGES.common.notEntered }}</td>
                        <td>
                            <span v-if="payment.reservation_id">
                                #{{ payment.reservation_id }}
                                <span class="text-caption text-medium-emphasis">
                                    {{ payment.reservation_starts_at }}
                                </span>
                            </span>
                            <span v-else class="text-medium-emphasis">{{ MESSAGES.common.notLinked }}</span>
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
                                label="要対応"
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
                                詳細
                            </v-btn>
                        </td>
                    </tr>
                    <tr v-if="payments.data.length === 0">
                        <td colspan="8">
                            <EmptyState
                                icon="mdi-credit-card-search-outline"
                                title="該当する決済はありません"
                                description="条件を変更すると、ほかの決済を確認できます。"
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
