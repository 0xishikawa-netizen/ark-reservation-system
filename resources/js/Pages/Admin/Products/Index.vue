<script setup lang="ts">
import { useMasterActiveToggle } from '@/composables/masterActive';
import { Head } from '@inertiajs/vue3';
import { EmptyValue, PageHeader, SectionCard, MasterDeleteButton, TrashedMasterList } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';
import { formatYenCurrency } from '@/utils/money';

defineOptions({ layout: AdminLayout });

interface Product { id: number; code: string | null; name: string; price: number; tax_category_name: string | null; is_active: boolean; sort_order: number }
defineProps<{ trashed?: Array<{ id: number; name: string; deleted_at: string | null }>; products: Product[] }>();

const headers = [
    { title: MESSAGES.mastersUi.products.name, key: 'name' }, { title: MESSAGES.mastersUi.products.code, key: 'code' },
    { title: MESSAGES.mastersUi.products.price, key: 'price' }, { title: MESSAGES.mastersUi.products.taxCategory, key: 'tax_category_name' },
    { title: MESSAGES.mastersUi.products.active, key: 'is_active', sortable: false }, { title: '', key: 'actions', sortable: false },
] as const;
// 有効/無効の切替（送信中は同じ行を押せない。M-6）
const { isPending: isActivePending, toggle } = useMasterActiveToggle('/admin/products');
</script>

<template>
    <Head :title="MESSAGES.mastersUi.products.title" />
    <PageHeader :title="MESSAGES.mastersUi.products.title" :subtitle="MESSAGES.mastersUi.products.subtitle">
        <template #actions><v-btn color="primary" href="/admin/products/create">{{ MESSAGES.mastersUi.products.add }}</v-btn></template>
    </PageHeader>
    <SectionCard :title="MESSAGES.mastersUi.products.list">
        <v-data-table :headers="headers" :items="products" item-value="id" :no-data-text="MESSAGES.product.none">
            <template #item.code="{ item }"><template v-if="item.code">{{ item.code }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></template>
            <template #item.price="{ item }">{{ formatYenCurrency(item.price) }}</template>
            <template #item.tax_category_name="{ item }"><template v-if="item.tax_category_name">{{ item.tax_category_name }}</template><EmptyValue :label="MESSAGES.common.notSet" v-else /></template>
            <template #item.is_active="{ item }">
                <v-switch :model-value="item.is_active" color="primary" hide-details :aria-label="fillMessage(MESSAGES.mastersUi.products.activeState, { name: item.name })" :disabled="isActivePending(item.id)" @update:model-value="toggle(item, $event)" />
            </template>
            <template #item.actions="{ item }">
                <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" :href="`/admin/products/${item.id}/edit`">{{ MESSAGES.mastersUi.products.edit }}</v-btn>
                <MasterDeleteButton type="products" :id="item.id" :name="item.name" :label="MESSAGES.mastersUi.products.masterLabel" />
            </template>
        </v-data-table>
    </SectionCard>
    <TrashedMasterList type="products" :label="MESSAGES.mastersUi.products.masterLabel" :items="trashed ?? []" />
</template>
