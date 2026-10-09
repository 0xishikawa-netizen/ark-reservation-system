<script setup lang="ts">
import { MoneyField } from '@/components/ark';
import { Head, useForm } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

interface TicketProductFormData {
    id: number;
    name: string;
    total_count: number;
    price: number;
    validity_days: number;
    is_active: boolean;
    sort_order: number;
}

const props = defineProps<{ ticketProduct: TicketProductFormData }>();

const form = useForm({
    name: props.ticketProduct.name,
    total_count: props.ticketProduct.total_count,
    price: props.ticketProduct.price,
    validity_days: props.ticketProduct.validity_days,
    sort_order: props.ticketProduct.sort_order,
    is_active: props.ticketProduct.is_active,
});

const submit = (): void => {
    form.put(`/admin/ticket-products/${props.ticketProduct.id}`);
};
</script>

<template>
    <Head :title="fillMessage(MESSAGES.mastersUi.ticketProducts.editHead, { name: ticketProduct.name })" />

    <div class="ark-form-page">
    <v-card :title="MESSAGES.mastersUi.ticketProducts.editTitle">
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
                        {{ MESSAGES.mastersUi.ticketProducts.update }}
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
