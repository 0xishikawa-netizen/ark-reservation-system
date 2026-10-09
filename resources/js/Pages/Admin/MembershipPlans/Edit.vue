<script setup lang="ts">
import { MoneyField } from '@/components/ark';
import { Head, useForm } from '@inertiajs/vue3';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

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
    <Head :title="fillMessage(MESSAGES.mastersUi.membershipPlans.editHead, { name: membershipPlan.name })" />

    <v-card max-width="760" :title="MESSAGES.mastersUi.membershipPlans.editTitle">
        <v-card-text>
            <v-alert type="info" variant="tonal" class="mb-4">
                {{ MESSAGES.membership.priceIdHint }}
            </v-alert>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    :label="MESSAGES.mastersUi.membershipPlans.name"
                    maxlength="100"
                    :error-messages="form.errors.name"
                    required
                />
                <div class="d-flex ga-4 flex-wrap">
                    <MoneyField
                        v-model="form.price"
                        :label="MESSAGES.mastersUi.membershipPlans.monthlyPrice"
                        :error-messages="form.errors.price"
                        required
                    />
                    <v-text-field
                        v-model.number="form.usage_count_per_period"
                        :label="MESSAGES.mastersUi.membershipPlans.countPerMonth"
                        type="number"
                        min="1"
                        max="999"
                        :error-messages="form.errors.usage_count_per_period"
                        required
                    />
                </div>
                <v-select
                    v-model="form.billing_interval"
                    :label="MESSAGES.mastersUi.membershipPlans.billingInterval"
                    :items="[{ title: MESSAGES.mastersUi.membershipPlans.monthlyInterval, value: 'month' }]"
                    :error-messages="form.errors.billing_interval"
                    required
                />
                <v-text-field
                    v-model="form.stripe_price_id"
                    :label="MESSAGES.mastersUi.membershipPlans.stripePriceId"
                    maxlength="40"
                    placeholder="price_..."
                    :error-messages="form.errors.stripe_price_id"
                    required
                />
                <v-text-field
                    v-model.number="form.sort_order"
                    :label="MESSAGES.mastersUi.membershipPlans.sortOrder"
                    type="number"
                    :error-messages="form.errors.sort_order"
                    required
                />
                <v-switch
                    v-model="form.is_active"
                    :label="MESSAGES.mastersUi.membershipPlans.active"
                    color="primary"
                    :error-messages="form.errors.is_active"
                />
                <div class="d-flex ga-3">
                    <v-btn type="submit" color="primary" :loading="form.processing">{{ MESSAGES.mastersUi.membershipPlans.update }}</v-btn>
                    <v-btn variant="text" href="/admin/membership-plans">{{ MESSAGES.mastersUi.membershipPlans.cancel }}</v-btn>
                </div>
            </v-form>
        </v-card-text>
    </v-card>
</template>
