<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

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
    <Head :title="`${ticketProduct.name}を編集`" />

    <div class="ark-form-page">
    <v-card title="回数券商品編集">
        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="商品名"
                    :error-messages="form.errors.name"
                    maxlength="100"
                    required
                />
                <div class="d-flex ga-4 flex-wrap">
                    <v-text-field
                        v-model.number="form.total_count"
                        label="回数"
                        type="number"
                        min="1"
                        max="999"
                        :error-messages="form.errors.total_count"
                        required
                    />
                    <v-text-field
                        v-model.number="form.price"
                        label="価格（円）"
                        type="number"
                        min="0"
                        :error-messages="form.errors.price"
                        required
                    />
                </div>
                <div class="d-flex ga-4 flex-wrap">
                    <v-text-field
                        v-model.number="form.validity_days"
                        label="有効期間（日）"
                        type="number"
                        min="1"
                        max="3650"
                        :error-messages="form.errors.validity_days"
                        required
                    />
                    <v-text-field
                        v-model.number="form.sort_order"
                        label="表示順"
                        type="number"
                        :error-messages="form.errors.sort_order"
                        required
                    />
                </div>
                <v-switch
                    v-model="form.is_active"
                    label="有効"
                    color="primary"
                    :error-messages="form.errors.is_active"
                />
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">
                        更新
                    </v-btn>
                    <v-btn variant="text" href="/admin/ticket-products">キャンセル</v-btn>
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
