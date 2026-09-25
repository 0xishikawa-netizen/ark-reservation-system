<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
defineOptions({ layout: AdminLayout });
interface TaxCategory { id: number; name: string; is_active: boolean }
interface Product { id: number; code: string | null; name: string; price: number; tax_category_id: number | null; is_active: boolean; sort_order: number }
const props = defineProps<{ product: Product; taxCategories: TaxCategory[] }>();
const form = useForm({ code: props.product.code, name: props.product.name, price: props.product.price, tax_category_id: props.product.tax_category_id, is_active: props.product.is_active, sort_order: props.product.sort_order });
const submit = (): void => { form.put(`/admin/products/${props.product.id}`); };
</script>
<template>
    <Head :title="`${product.name}を編集`" /><PageHeader title="商品編集" :subtitle="`${product.name}のマスタ情報を更新します。`" />
    <SectionCard><v-form class="form" @submit.prevent="submit">
        <v-text-field v-model="form.name" label="商品名" :error-messages="form.errors.name" required />
        <v-text-field v-model="form.code" label="商品コード（任意）" :error-messages="form.errors.code" />
        <v-text-field v-model.number="form.price" label="価格（税込・円）" type="number" min="0" :error-messages="form.errors.price" required />
        <v-select v-model="form.tax_category_id" label="税区分" :items="taxCategories" item-title="name" item-value="id" clearable :error-messages="form.errors.tax_category_id" />
        <v-text-field v-model.number="form.sort_order" label="表示順" type="number" :error-messages="form.errors.sort_order" />
        <v-switch v-model="form.is_active" label="有効" color="primary" />
        <div class="d-flex ga-3"><v-btn type="submit" color="primary" :loading="form.processing">更新</v-btn><v-btn href="/admin/products" variant="text">キャンセル</v-btn></div>
    </v-form></SectionCard>
</template>
<style scoped>.form{max-width:720px}</style>
