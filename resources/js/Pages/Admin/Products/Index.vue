<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EmptyValue, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface Product { id: number; code: string | null; name: string; price: number; tax_category_name: string | null; is_active: boolean; sort_order: number }
defineProps<{ products: Product[] }>();

const headers = [
    { title: '商品名', key: 'name' }, { title: 'コード', key: 'code' },
    { title: '価格', key: 'price' }, { title: '税区分', key: 'tax_category_name' },
    { title: '有効', key: 'is_active' }, { title: '', key: 'actions', sortable: false },
] as const;
const money = (value: number): string => new Intl.NumberFormat('ja-JP', { style: 'currency', currency: 'JPY', maximumFractionDigits: 0 }).format(value);
const toggle = (item: Product): void => router.patch(`/admin/products/${item.id}/active`, { active: !item.is_active }, { preserveScroll: true });
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
            <template #item.is_active="{ item }"><StatusChip :status="item.is_active ? 'active' : 'canceled'" :label="item.is_active ? '有効' : '無効'" /></template>
            <template #item.actions="{ item }">
                <v-btn size="small" variant="text" :href="`/admin/products/${item.id}/edit`">編集</v-btn>
                <v-btn size="small" variant="text" @click="toggle(item)">{{ item.is_active ? '無効化' : '有効化' }}</v-btn>
            </template>
        </v-data-table>
    </SectionCard>
</template>
