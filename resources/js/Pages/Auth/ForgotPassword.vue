<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';
import { MESSAGES } from '@/constants/messages';

defineProps<{
    status?: string | null;
}>();

const form = useForm({
    email: '',
});

const submit = (): void => {
    form.post('/forgot-password');
};
</script>

<template>
    <AuthCard :title="MESSAGES.customerUi.auth.forgotPassword.title" :subtitle="MESSAGES.customerUi.auth.forgotPassword.subtitle">
        <v-alert v-if="status" type="success" variant="tonal" density="comfortable" class="mb-4">
            {{ status }}
        </v-alert>
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.email"
                :label="MESSAGES.customerUi.auth.email"
                type="email"
                autocomplete="email"
                :error-messages="form.errors.email"
                autofocus
                required
            />
            <v-btn
                type="submit"
                color="primary"
                variant="flat"
                size="large"
                block
                :loading="form.processing"
            >
                {{ MESSAGES.customerUi.auth.forgotPassword.submit }}
            </v-btn>
        </v-form>

        <template #footer>
            <a href="/login" class="text-body-2">{{ MESSAGES.customerUi.auth.forgotPassword.backToLogin }}</a>
        </template>
    </AuthCard>
</template>
