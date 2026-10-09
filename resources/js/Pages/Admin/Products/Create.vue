<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { PageHeader, SectionCard, MoneyField } from '@/components/ark';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
defineOptions({ layout: AdminLayout });
interface TaxCategory { id: number; name: string; is_active: boolean }
defineProps<{ taxCategories: TaxCategory[] }>();
const form = useForm({ code: null as string | null, name: '', price: 0, tax_category_id: null as number | null, is_active: true, sort_order: 0 });
const submit = (): void => { form.post('/admin/products'); };
</script>
<template>
    <Head :title="MESSAGES.mastersUi.products.createTitle" /><PageHeader :title="MESSAGES.mastersUi.products.createTitle" :subtitle="MESSAGES.mastersUi.products.createSubtitle" />
    <SectionCard><v-form class="form" @submit.prevent="submit">
        <v-text-field v-model="form.name" :label="MESSAGES.mastersUi.products.name" :error-messages="form.errors.name" required />
        <v-text-field v-model="form.code" :label="MESSAGES.mastersUi.products.productCodeOptional" :error-messages="form.errors.code" />
        <MoneyField v-model="form.price" :label="MESSAGES.mastersUi.products.priceTaxIncluded" :error-messages="form.errors.price" required />
        <v-select v-model="form.tax_category_id" :label="MESSAGES.mastersUi.products.taxCategory" :items="taxCategories" item-title="name" item-value="id" clearable :error-messages="form.errors.tax_category_id" />
        <v-text-field v-model.number="form.sort_order" :label="MESSAGES.mastersUi.products.sortOrder" type="number" :error-messages="form.errors.sort_order" />
        <v-switch v-model="form.is_active" :label="MESSAGES.mastersUi.products.active" color="primary" />
        <div class="d-flex ga-3"><v-btn type="submit" color="primary" :loading="form.processing">{{ MESSAGES.mastersUi.products.create }}</v-btn><v-btn href="/admin/products" variant="text">{{ MESSAGES.mastersUi.products.cancel }}</v-btn></div>
    </v-form></SectionCard>
</template>
<style scoped>.form{max-width:720px}</style>
