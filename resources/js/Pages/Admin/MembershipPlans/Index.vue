<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { formatYenCurrency } from '@/utils/money';
import { useMasterActiveToggle } from '@/composables/masterActive';

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

defineProps<{ trashed?: Array<{ id: number; name: string; deleted_at: string | null }>; membershipPlans: MembershipPlanListItem[] }>();

const headers = [
    { title: MESSAGES.mastersUi.membershipPlans.name, key: 'name' },
    { title: MESSAGES.mastersUi.membershipPlans.monthlyPrice, key: 'price' },
    { title: MESSAGES.mastersUi.membershipPlans.count, key: 'usage_count_per_period' },
    { title: MESSAGES.mastersUi.membershipPlans.interval, key: 'billing_interval' },
    { title: MESSAGES.mastersUi.membershipPlans.stripePriceId, key: 'stripe_price_id' },
    { title: MESSAGES.mastersUi.membershipPlans.activeContracts, key: 'active_memberships_count' },
    { title: MESSAGES.mastersUi.membershipPlans.sortOrder, key: 'sort_order' },
    { title: MESSAGES.mastersUi.membershipPlans.active, key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

// 有効/無効の切替（送信中は同じ行を押せない。M-6）
const { isPending: isActivePending, toggle: toggleActive } = useMasterActiveToggle('/admin/membership-plans');

</script>

<template>
    <Head :title="MESSAGES.mastersUi.membershipPlans.title" />

    <PageHeader :title="MESSAGES.mastersUi.membershipPlans.title" :subtitle="MESSAGES.mastersUi.membershipPlans.subtitle">
        <template #actions>
            <v-btn color="primary" href="/admin/membership-plans/create">{{ MESSAGES.mastersUi.membershipPlans.add }}</v-btn>
        </template>
    </PageHeader>

    <SectionCard :title="MESSAGES.mastersUi.membershipPlans.list" class="ark-table-section">
        <v-data-table
            :headers="headers"
            :items="membershipPlans"
            item-value="id"
            :no-data-text="MESSAGES.mastersUi.membershipPlans.noData"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-card-account-details-outline"
                    :title="MESSAGES.mastersUi.membershipPlans.emptyTitle"
                    :description="MESSAGES.mastersUi.membershipPlans.emptyDescription"
                />
            </template>
            <template #item.price="{ item }">{{ formatYenCurrency(item.price) }}</template>
            <template #item.usage_count_per_period="{ item }">{{ fillMessage(MESSAGES.mastersUi.membershipPlans.countValue, { count: String(item.usage_count_per_period) }) }}</template>
            <template #item.billing_interval="{ item }">{{ item.billing_interval === 'month' ? MESSAGES.mastersUi.membershipPlans.monthlyInterval : item.billing_interval }}</template>
            <template #item.active_memberships_count="{ item }">{{ fillMessage(MESSAGES.mastersUi.membershipPlans.contractsValue, { count: String(item.active_memberships_count) }) }}</template>
            <template #item.is_active="{ item }">
                <v-switch
                    :model-value="item.is_active"
                    color="primary"
                    hide-details
                    :aria-label="fillMessage(MESSAGES.mastersUi.membershipPlans.activeState, { name: item.name })"
                    :disabled="isActivePending(item.id)"
                    @update:model-value="toggleActive(item, $event)"
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
                    {{ MESSAGES.mastersUi.membershipPlans.edit }}
                </v-btn>
                <MasterDeleteButton type="membership-plans" :id="item.id" :name="item.name" :label="MESSAGES.mastersUi.membershipPlans.masterLabel" />
            </template>
        </v-data-table>
    </SectionCard>
    <TrashedMasterList type="membership-plans" :label="MESSAGES.mastersUi.membershipPlans.masterLabel" :items="trashed ?? []" />
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
