<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';
import { MESSAGES } from '@/constants/messages';

const props = defineProps<{
    email: string;
}>();

const form = useForm({
    email: props.email,
    password: '',
});

const submit = (): void => {
    form.post('/auth/google/link-existing', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthCard
        :title="MESSAGES.customerUi.auth.linkGoogle.title"
        :subtitle="MESSAGES.customerUi.auth.linkGoogle.subtitle"
    >
        <v-form @submit.prevent="submit">
            <v-text-field
                :model-value="form.email"
                :label="MESSAGES.customerUi.auth.email"
                type="email"
                readonly
                :error-messages="form.errors.email"
            />
            <v-text-field
                v-model="form.password"
                :label="MESSAGES.customerUi.auth.linkGoogle.password"
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
                {{ MESSAGES.customerUi.auth.linkGoogle.submit }}
            </v-btn>
        </v-form>

        <template #footer>
            <a href="/login" class="text-body-2">{{ MESSAGES.customerUi.auth.linkGoogle.loginWithPassword }}</a>
        </template>
    </AuthCard>
</template>
