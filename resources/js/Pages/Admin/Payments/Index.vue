<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
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

const props = defineProps<{
    payments: Paginated<PaymentRow>;
    filters: { status: string; needs_attention: boolean };
    statuses: string[];
    attention_count: number;
}>();

const status = ref(props.filters.status);
const needsAttention = ref(props.filters.needs_attention);

const statusLabels: Record<string, string> = {
    pending: '手続き中',
    authorized: '与信済み（未確定）',
    succeeded: '支払い済み',
    voided: '与信取消',
    failed: '失敗',
    partially_refunded: '一部返金',
    refunded: '返金済み',
};

const statusColors: Record<string, string> = {
    pending: 'grey',
    authorized: 'orange',
    succeeded: 'green',
    voided: 'blue-grey',
    failed: 'red',
    partially_refunded: 'amber',
    refunded: 'purple',
};

const applyFilters = (): void => {
    router.get(
        '/admin/payments',
        {
            status: status.value || undefined,
            needs_attention: needsAttention.value ? 1 : undefined,
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
        },
        { preserveState: true },
    );
};
</script>

<template>
    <Head title="決済管理" />

    <v-container fluid class="py-4">
        <div class="d-flex align-center mb-4">
            <h1 class="text-h6">決済管理</h1>
            <v-chip
                v-if="attention_count > 0"
                color="red"
                variant="flat"
                size="small"
                class="ml-3"
            >
                要対応 {{ attention_count }} 件
            </v-chip>
        </div>

        <v-card variant="outlined" class="mb-4">
            <v-card-text class="d-flex flex-wrap ga-4 align-center">
                <v-select
                    v-model="status"
                    :items="[{ title: 'すべて', value: '' }, ...statuses.map((s) => ({ title: statusLabels[s] ?? s, value: s }))]"
                    label="ステータス"
                    density="compact"
                    hide-details
                    style="max-width: 220px"
                    @update:model-value="applyFilters"
                />
                <v-checkbox
                    v-model="needsAttention"
                    label="要対応のみ"
                    density="compact"
                    hide-details
                    @update:model-value="applyFilters"
                />
            </v-card-text>
        </v-card>

        <v-card variant="outlined">
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
                        <td>{{ payment.customer_name ?? '—' }}</td>
                        <td>
                            <span v-if="payment.reservation_id">
                                #{{ payment.reservation_id }}
                                <span class="text-caption text-medium-emphasis">
                                    {{ payment.reservation_starts_at }}
                                </span>
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td class="text-right">{{ payment.amount.toLocaleString() }}</td>
                        <td class="text-right">
                            {{ payment.refunded_amount > 0 ? payment.refunded_amount.toLocaleString() : '—' }}
                        </td>
                        <td>
                            <v-chip
                                :color="statusColors[payment.status] ?? 'grey'"
                                size="small"
                                variant="flat"
                            >
                                {{ statusLabels[payment.status] ?? payment.status }}
                            </v-chip>
                            <v-chip
                                v-if="payment.needs_attention"
                                color="red"
                                size="small"
                                variant="outlined"
                                class="ml-1"
                            >
                                要対応
                            </v-chip>
                        </td>
                        <td class="text-caption">{{ payment.created_at }}</td>
                        <td class="text-right">
                            <v-btn
                                size="small"
                                variant="text"
                                :href="`/admin/payments/${payment.id}`"
                                @click.prevent="router.get(`/admin/payments/${payment.id}`)"
                            >
                                詳細
                            </v-btn>
                        </td>
                    </tr>
                    <tr v-if="payments.data.length === 0">
                        <td colspan="8" class="text-center text-medium-emphasis py-6">
                            該当する決済はありません。
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <v-pagination
            v-if="payments.last_page > 1"
            :model-value="payments.current_page"
            :length="payments.last_page"
            class="mt-4"
            @update:model-value="goToPage"
        />
    </v-container>
</template>
