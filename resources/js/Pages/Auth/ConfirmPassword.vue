<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';
import { MESSAGES } from '@/constants/messages';

const form = useForm({
    password: '',
});

const submit = (): void => {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthCard :title="MESSAGES.customerUi.auth.confirmPassword.title" :subtitle="MESSAGES.customerUi.auth.confirmPassword.subtitle">
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.password"
                :label="MESSAGES.customerUi.auth.password"
                type="password"
                autocomplete="current-password"
                :error-messages="form.errors.password"
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
                {{ MESSAGES.customerUi.auth.confirm }}
            </v-btn>
        </v-form>
    </AuthCard>
</template>
