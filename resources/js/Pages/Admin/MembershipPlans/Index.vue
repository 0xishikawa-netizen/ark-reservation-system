<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
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
    { title: 'Stripe 価格ID', key: 'stripe_price_id' },
    { title: '稼働契約', key: 'active_memberships_count' },
    { title: '表示順', key: 'sort_order' },
    { title: '有効', key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
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
    <Head title="月額プラン" />

    <PageHeader title="月額プラン" subtitle="月額利用権の料金と利用回数を管理します。">
        <template #actions>
            <v-btn color="primary" href="/admin/membership-plans/create">月額プランを追加</v-btn>
        </template>
    </PageHeader>

    <SectionCard title="月額プラン一覧" class="ark-table-section">
        <v-data-table
            :headers="headers"
            :items="membershipPlans"
            item-value="id"
            no-data-text="月額プランはありません。"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-card-account-details-outline"
                    title="月額プランはありません"
                    description="月額プランを追加すると、こちらで料金と利用回数を管理できます。"
                />
            </template>
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
                <v-btn
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    :href="`/admin/membership-plans/${item.id}/edit`"
                >
                    編集
                </v-btn>
            </template>
        </v-data-table>
    </SectionCard>
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
