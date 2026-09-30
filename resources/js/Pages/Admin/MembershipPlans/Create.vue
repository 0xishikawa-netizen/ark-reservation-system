<script setup lang="ts">
import { MoneyField } from '@/components/ark';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

const form = useForm({
    name: '',
    price: 0,
    usage_count_per_period: 1,
    billing_interval: 'month',
    stripe_price_id: '',
    sort_order: 0,
    is_active: true,
});

function submit(): void {
    form.post('/admin/membership-plans');
}
</script>

<template>
    <Head title="月額プラン作成" />

    <v-card max-width="760" title="月額プラン作成">
        <v-card-text>
            <v-alert type="info" variant="tonal" class="mb-4">
                {{ MESSAGES.membership.priceIdHint }}
            </v-alert>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="プラン名"
                    maxlength="100"
                    :error-messages="form.errors.name"
                    required
                />
                <div class="d-flex ga-4 flex-wrap">
                    <MoneyField
                        v-model="form.price"
                        label="月額"
                        :error-messages="form.errors.price"
                        required
                    />
                    <v-text-field
                        v-model.number="form.usage_count_per_period"
                        label="月あたり回数"
                        type="number"
                        min="1"
                        max="999"
                        :error-messages="form.errors.usage_count_per_period"
                        required
                    />
                </div>
                <v-select
                    v-model="form.billing_interval"
                    label="請求間隔"
                    :items="[{ title: '月ごと', value: 'month' }]"
                    :error-messages="form.errors.billing_interval"
                    required
                />
                <v-text-field
                    v-model="form.stripe_price_id"
                    label="Stripe 価格ID"
                    maxlength="40"
                    placeholder="price_..."
                    :error-messages="form.errors.stripe_price_id"
                    required
                />
                <v-text-field
                    v-model.number="form.sort_order"
                    label="表示順"
                    type="number"
                    :error-messages="form.errors.sort_order"
                    required
                />
                <v-switch
                    v-model="form.is_active"
                    label="有効"
                    color="primary"
                    :error-messages="form.errors.is_active"
                />
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">作成</v-btn>
                    <v-btn variant="text" href="/admin/membership-plans">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
