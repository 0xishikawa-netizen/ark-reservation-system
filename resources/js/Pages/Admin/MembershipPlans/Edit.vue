<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface MembershipPlanFormData {
    id: number;
    name: string;
    price: number;
    usage_count_per_period: number;
    billing_interval: string;
    stripe_price_id: string;
    is_active: boolean;
    sort_order: number;
}

const props = defineProps<{ membershipPlan: MembershipPlanFormData }>();

const form = useForm({
    name: props.membershipPlan.name,
    price: props.membershipPlan.price,
    usage_count_per_period: props.membershipPlan.usage_count_per_period,
    billing_interval: props.membershipPlan.billing_interval,
    stripe_price_id: props.membershipPlan.stripe_price_id,
    sort_order: props.membershipPlan.sort_order,
    is_active: props.membershipPlan.is_active,
});

function submit(): void {
    form.put(`/admin/membership-plans/${props.membershipPlan.id}`);
}
</script>

<template>
    <Head :title="`${membershipPlan.name}を編集`" />

    <v-card max-width="760" title="会員プラン編集">
        <v-card-text>
            <v-alert type="info" variant="tonal" class="mb-4">
                Stripe の Test Mode で作成した price ID（price_ から始まる値）を入力してください。
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
                    <v-text-field
                        v-model.number="form.price"
                        label="月額（円）"
                        type="number"
                        min="0"
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
                    label="Stripe Price ID"
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
                    <v-btn type="submit" color="primary" :loading="form.processing">更新</v-btn>
                    <v-btn variant="text" href="/admin/membership-plans">キャンセル</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
