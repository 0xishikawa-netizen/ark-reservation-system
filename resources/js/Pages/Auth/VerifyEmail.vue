<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';
import { MESSAGES } from '@/constants/messages';

defineProps<{
    status?: string | null;
}>();

const resendForm = useForm({});
const logoutForm = useForm({});

const resend = (): void => {
    resendForm.post('/email/verification-notification');
};

const logout = (): void => {
    logoutForm.post('/logout');
};
</script>

<template>
    <AuthCard
        :title="MESSAGES.customerUi.auth.verifyEmail.title"
        :subtitle="MESSAGES.customerUi.auth.verifyEmail.subtitle"
    >
        <v-alert v-if="status" type="success" variant="tonal" density="comfortable" class="mb-4">
            {{ status }}
        </v-alert>
        <v-btn
            color="primary"
            variant="flat"
            size="large"
            block
            :loading="resendForm.processing"
            @click="resend"
        >
            {{ MESSAGES.customerUi.auth.verifyEmail.resend }}
        </v-btn>

        <template #footer>
            <v-btn variant="text" size="small" :loading="logoutForm.processing" @click="logout">
                {{ MESSAGES.customerUi.auth.verifyEmail.logout }}
            </v-btn>
        </template>
    </AuthCard>
</template>
