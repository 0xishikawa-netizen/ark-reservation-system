<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { EmptyValue, PageHeader, SectionCard, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Product { id: number; code: string | null; name: string; price: number; tax_category_name: string | null; is_active: boolean; sort_order: number }
defineProps<{ trashed?: Array<{ id: number; name: string; deleted_at: string | null }>; products: Product[] }>();

const headers = [
    { title: '商品名', key: 'name' }, { title: 'コード', key: 'code' },
    { title: '価格', key: 'price' }, { title: '税区分', key: 'tax_category_name' },
    { title: '有効', key: 'is_active', sortable: false }, { title: '', key: 'actions', sortable: false },
] as const;
const money = (value: number): string => new Intl.NumberFormat('ja-JP', { style: 'currency', currency: 'JPY', maximumFractionDigits: 0 }).format(value);
const pendingProductIds = reactive(new Set<number>());
const toggle = (item: Product, active: boolean | null): void => {
    if (active === null || active === item.is_active || pendingProductIds.has(item.id)) return;
    pendingProductIds.add(item.id);
    router.patch(`/admin/products/${item.id}/active`, { active }, {
        preserveScroll: true,
        onFinish: () => pendingProductIds.delete(item.id),
    });
};
</script>

<template>
    <Head title="商品" />
    <PageHeader title="商品" subtitle="物販商品の価格・税区分・有効状態を管理します。">
        <template #actions><v-btn color="primary" href="/admin/products/create">商品を追加</v-btn></template>
    </PageHeader>
    <SectionCard title="商品一覧">
        <v-data-table :headers="headers" :items="products" item-value="id" :no-data-text="MESSAGES.product.none">
            <template #item.code="{ item }"><template v-if="item.code">{{ item.code }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></template>
            <template #item.price="{ item }">{{ money(item.price) }}</template>
            <template #item.tax_category_name="{ item }"><template v-if="item.tax_category_name">{{ item.tax_category_name }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></template>
            <template #item.is_active="{ item }">
                <v-switch :model-value="item.is_active" color="primary" hide-details :aria-label="`${item.name}の有効状態`" :disabled="pendingProductIds.has(item.id)" @update:model-value="toggle(item, $event)" />
            </template>
            <template #item.actions="{ item }">
                <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" :href="`/admin/products/${item.id}/edit`">編集</v-btn>
                <MasterDeleteButton type="products" :id="item.id" :name="item.name" label="商品" />
            </template>
        </v-data-table>
    </SectionCard>
    <TrashedMasterList type="products" label="商品" :items="trashed ?? []" />
</template>
