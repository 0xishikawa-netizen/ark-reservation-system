<script setup lang="ts">
import { MoneyField } from '@/components/ark';
import { Head, useForm } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const form = useForm({
    name: '',
    total_count: 1,
    price: 0,
    validity_days: 90,
    sort_order: 0,
    is_active: true,
});

const submit = (): void => {
    form.post('/admin/ticket-products');
};
</script>

<template>
    <Head :title="MESSAGES.mastersUi.ticketProducts.createTitle" />

    <div class="ark-form-page">
    <v-card :title="MESSAGES.mastersUi.ticketProducts.createTitle">
        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    :label="MESSAGES.mastersUi.ticketProducts.productName"
                    :error-messages="form.errors.name"
                    maxlength="100"
                    required
                />
                <div class="d-flex ga-4 flex-wrap">
                    <v-text-field
                        v-model.number="form.total_count"
                        :label="MESSAGES.mastersUi.ticketProducts.count"
                        type="number"
                        min="1"
                        max="999"
                        :error-messages="form.errors.total_count"
                        required
                    />
                    <MoneyField
                        v-model="form.price"
                        :label="MESSAGES.mastersUi.ticketProducts.price"
                        :error-messages="form.errors.price"
                        required
                    />
                </div>
                <div class="d-flex ga-4 flex-wrap">
                    <v-text-field
                        v-model.number="form.validity_days"
                        :label="MESSAGES.mastersUi.ticketProducts.validityDays"
                        type="number"
                        min="1"
                        max="3650"
                        :error-messages="form.errors.validity_days"
                        required
                    />
                    <v-text-field
                        v-model.number="form.sort_order"
                        :label="MESSAGES.mastersUi.ticketProducts.sortOrder"
                        type="number"
                        :error-messages="form.errors.sort_order"
                        required
                    />
                </div>
                <v-switch
                    v-model="form.is_active"
                    :label="MESSAGES.mastersUi.ticketProducts.active"
                    color="primary"
                    :error-messages="form.errors.is_active"
                />
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        {{ MESSAGES.mastersUi.ticketProducts.create }}
                    </v-btn>
                    <v-btn variant="text" href="/admin/ticket-products">{{ MESSAGES.mastersUi.ticketProducts.cancel }}</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
    </div>
</template>

<style scoped>
.ark-form-page {
    max-width: 720px;
    margin-inline: auto;
}
</style>
