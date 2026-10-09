<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { formatYenCurrency } from '@/utils/money';
import { useMasterActiveToggle } from '@/composables/masterActive';

defineOptions({ layout: AdminLayout });

interface TicketProductListItem {
    id: number;
    name: string;
    total_count: number;
    price: number;
    validity_days: number;
    is_active: boolean;
    sort_order: number;
}

defineProps<{ trashed?: Array<{ id: number; name: string; deleted_at: string | null }>; ticketProducts: TicketProductListItem[] }>();

const headers = [
    { title: MESSAGES.mastersUi.ticketProducts.productName, key: 'name' },
    { title: MESSAGES.mastersUi.ticketProducts.count, key: 'total_count' },
    { title: MESSAGES.mastersUi.ticketProducts.price, key: 'price' },
    { title: MESSAGES.mastersUi.ticketProducts.validity, key: 'validity_days' },
    { title: MESSAGES.mastersUi.ticketProducts.sortOrder, key: 'sort_order' },
    { title: MESSAGES.mastersUi.ticketProducts.active, key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
] as const;

// 有効/無効の切替（送信中は同じ行を押せない。M-6）
const { isPending: isActivePending, toggle: toggleActive } = useMasterActiveToggle('/admin/ticket-products');

</script>

<template>
    <Head :title="MESSAGES.mastersUi.ticketProducts.title" />

    <PageHeader :title="MESSAGES.mastersUi.ticketProducts.title" :subtitle="MESSAGES.mastersUi.ticketProducts.subtitle">
        <template #actions>
            <v-btn color="primary" href="/admin/ticket-products/create">
                {{ MESSAGES.mastersUi.ticketProducts.add }}
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard :title="MESSAGES.mastersUi.ticketProducts.list" class="ark-table-section">
        <v-data-table
            :headers="headers"
            :items="ticketProducts"
            item-value="id"
            :no-data-text="MESSAGES.mastersUi.ticketProducts.noData"
        >
            <template #no-data>
                <EmptyState
                    icon="mdi-ticket-outline"
                    :title="MESSAGES.mastersUi.ticketProducts.emptyTitle"
                    :description="MESSAGES.mastersUi.ticketProducts.emptyDescription"
                />
            </template>
            <template #item.total_count="{ item }">
                {{ fillMessage(MESSAGES.mastersUi.ticketProducts.countValue, { count: String(item.total_count) }) }}
            </template>
            <template #item.price="{ item }">
                {{ formatYenCurrency(item.price) }}
            </template>
            <template #item.validity_days="{ item }">
                {{ fillMessage(MESSAGES.mastersUi.ticketProducts.daysValue, { days: String(item.validity_days) }) }}
            </template>
            <template #item.is_active="{ item }">
                <v-switch
                    :model-value="item.is_active"
                    color="primary"
                    hide-details
                    :aria-label="fillMessage(MESSAGES.mastersUi.ticketProducts.activeState, { name: item.name })"
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
                    :href="`/admin/ticket-products/${item.id}/edit`"
                >
                    {{ MESSAGES.mastersUi.ticketProducts.edit }}
                </v-btn>
                <MasterDeleteButton type="ticket-products" :id="item.id" :name="item.name" :label="MESSAGES.mastersUi.ticketProducts.masterLabel" />
            </template>
        </v-data-table>
    </SectionCard>
    <TrashedMasterList type="ticket-products" :label="MESSAGES.mastersUi.ticketProducts.masterLabel" :items="trashed ?? []" />
</template>

<style scoped>
.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
