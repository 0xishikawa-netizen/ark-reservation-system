<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

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

defineProps<{ ticketProducts: TicketProductListItem[] }>();

const headers = [
    { title: '商品名', key: 'name' },
    { title: '回数', key: 'total_count' },
    { title: '価格', key: 'price' },
    { title: '有効期間', key: 'validity_days' },
    { title: '表示順', key: 'sort_order' },
    { title: '有効', key: 'is_active', sortable: false },
    { title: '', key: 'actions', sortable: false },
] as const;

const toggleActive = (product: TicketProductListItem): void => {
    router.patch(
        `/admin/ticket-products/${product.id}/active`,
        { active: !product.is_active },
        { preserveScroll: true },
    );
};

const formatPrice = (price: number): string =>
    new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(price);
</script>

<template>
    <Head title="回数券商品" />

    <div class="d-flex align-center justify-space-between mb-6">
        <h1 class="text-h4">回数券商品</h1>
        <v-btn color="primary" href="/admin/ticket-products/create">
            回数券商品を追加
        </v-btn>
    </div>

    <v-card>
        <v-data-table
            :headers="headers"
            :items="ticketProducts"
            item-value="id"
            no-data-text="回数券商品はありません。"
        >
            <template #item.total_count="{ item }">
                {{ item.total_count }}回
            </template>
            <template #item.price="{ item }">
                {{ formatPrice(item.price) }}
            </template>
            <template #item.validity_days="{ item }">
                {{ item.validity_days }}日
            </template>
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
                    variant="text"
                    :href="`/admin/ticket-products/${item.id}/edit`"
                >
                    編集
                </v-btn>
            </template>
        </v-data-table>
    </v-card>
</template>
