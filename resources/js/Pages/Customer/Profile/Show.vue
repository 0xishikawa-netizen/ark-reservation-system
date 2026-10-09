<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PageHeader, SectionCard } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: CustomerLayout });

interface CustomerProfile {
    user_id: number;
    name: string;
    kana: string;
    phone: string | null;
    birthday: string | null;
    gender: string | null;
    email: string;
    email_verified: boolean;
}

defineProps<{ customer: CustomerProfile }>();

const display = (value: string | null): string => value || MESSAGES.customerUi.profile.notRegistered;

const genderLabel = (value: string | null): string => {
    const labels: Record<string, string> = MESSAGES.customerUi.profile.genders;

    return value ? (labels[value] ?? value) : MESSAGES.customerUi.profile.notRegistered;
};
</script>

<template>
    <Head :title="MESSAGES.customerUi.profile.title" />

    <PageHeader
        :title="MESSAGES.customerUi.profile.title"
        :subtitle="MESSAGES.customerUi.profile.subtitle"
    />

    <SectionCard :title="MESSAGES.customerUi.profile.registered">
        <v-list lines="two" class="profile-list">
            <v-list-item :title="MESSAGES.customerUi.profile.name" :subtitle="customer.name" />
            <v-list-item :title="MESSAGES.customerUi.profile.kana" :subtitle="customer.kana" />
            <v-list-item :title="MESSAGES.customerUi.profile.phone" :subtitle="display(customer.phone)" />
            <v-list-item :title="MESSAGES.customerUi.profile.birthday" :subtitle="display(customer.birthday)" />
            <v-list-item :title="MESSAGES.customerUi.profile.gender" :subtitle="genderLabel(customer.gender)" />
            <v-list-item :title="MESSAGES.customerUi.profile.email" :subtitle="customer.email" />
            <v-list-item
                :title="MESSAGES.customerUi.profile.emailVerification"
                :subtitle="customer.email_verified ? MESSAGES.customerUi.profile.verified : MESSAGES.customerUi.profile.unverified"
            />
        </v-list>

        <div class="d-flex ga-3 flex-wrap mt-4">
            <v-btn color="primary" variant="flat" href="/mypage/profile/edit">
                {{ MESSAGES.customerUi.profile.edit }}
            </v-btn>
            <v-btn variant="outlined" href="/mypage/security" prepend-icon="mdi-shield-account-outline">
                {{ MESSAGES.customerUi.security.title }}
            </v-btn>
        </div>
    </SectionCard>
</template>

<style scoped>
.profile-list {
    margin: calc(var(--ark-space-2) * -1) calc(var(--ark-space-4) * -1) 0;
    background: transparent;
}
</style>
