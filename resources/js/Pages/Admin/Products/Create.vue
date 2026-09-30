<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { PageHeader, SectionCard, MoneyField } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
defineOptions({ layout: AdminLayout });
interface TaxCategory { id: number; name: string; is_active: boolean }
defineProps<{ taxCategories: TaxCategory[] }>();
const form = useForm({ code: null as string | null, name: '', price: 0, tax_category_id: null as number | null, is_active: true, sort_order: 0 });
const submit = (): void => { form.post('/admin/products'); };
</script>
<template>
    <Head title="商品作成" /><PageHeader title="商品作成" subtitle="物販商品のマスタ情報を登録します。" />
    <SectionCard><v-form class="form" @submit.prevent="submit">
        <v-text-field v-model="form.name" label="商品名" :error-messages="form.errors.name" required />
        <v-text-field v-model="form.code" label="商品コード（任意）" :error-messages="form.errors.code" />
        <MoneyField v-model="form.price" label="価格（税込）" :error-messages="form.errors.price" required />
        <v-select v-model="form.tax_category_id" label="税区分" :items="taxCategories" item-title="name" item-value="id" clearable :error-messages="form.errors.tax_category_id" />
        <v-text-field v-model.number="form.sort_order" label="表示順" type="number" :error-messages="form.errors.sort_order" />
        <v-switch v-model="form.is_active" label="有効" color="primary" />
        <div class="d-flex ga-3"><v-btn type="submit" color="primary" :loading="form.processing">作成</v-btn><v-btn href="/admin/products" variant="text">キャンセル</v-btn></div>
    </v-form></SectionCard>
</template>
<style scoped>.form{max-width:720px}</style>
