<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface MembershipPlanListItem {
    id: number;
    name: string;
    price: number;
    usage_count_per_period: number;
    billing_interval: string;
    stripe_price_id: string;
    is_active: boolean;
    sort_order: number;
    active_memberships_count: number;
}

defineProps<{ membershipPlans: MembershipPlanListItem[] }>();

const headers = [
    { title: 'プラン名', key: 'name' },
    { title: '月額', key: 'price' },
    { title: '回数', key: 'usage_count_per_period' },
    { title: '間隔', key: 'billing_interval' },
    { title: 'Stripe Price', key: 'stripe_price_id' },
    { title: '稼働契約', key: 'active_memberships_count' },
    { title: '表示順', key: 'sort_order' },
    { title: '有効', key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false },
] as const;

function toggleActive(plan: MembershipPlanListItem): void {
    router.patch(
        `/admin/membership-plans/${plan.id}/active`,
        { active: !plan.is_active },
        { preserveScroll: true },
    );
}

function formatPrice(price: number): string {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(price);
}
</script>

<template>
    <Head title="会員プラン" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">会員プラン</h1>
        <v-btn color="primary" href="/admin/membership-plans/create">会員プランを追加</v-btn>
    </div>

    <v-card>
        <v-data-table
            :headers="headers"
            :items="membershipPlans"
            item-value="id"
            no-data-text="会員プランはありません。"
        >
            <template #item.price="{ item }">{{ formatPrice(item.price) }}</template>
            <template #item.usage_count_per_period="{ item }">{{ item.usage_count_per_period }}回</template>
            <template #item.billing_interval="{ item }">{{ item.billing_interval === 'month' ? '月ごと' : item.billing_interval }}</template>
            <template #item.active_memberships_count="{ item }">{{ item.active_memberships_count }}件</template>
            <template #item.is_active="{ item }">
                <v-switch
                    :model-value="item.is_active"
                    color="primary"
                    hide-details
                    :aria-label="`${item.name}の有効状態`"
                    @click.stop="toggleActive(item)"
                />
            </template>
            <template #item.actions="{ item }">
                <v-btn size="small" variant="text" :href="`/admin/membership-plans/${item.id}/edit`">
                    編集
                </v-btn>
            </template>
        </v-data-table>
    </v-card>
</template>
