<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthCard from "@/components/auth/AuthCard.vue";
import { MESSAGES } from "@/constants/messages";

const props = defineProps<{
    token: string;
    email: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: "",
    password_confirmation: "",
});

const submit = (): void => {
    form.post("/reset-password", {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
};
</script>

<template>
    <AuthCard
        :title="MESSAGES.customerUi.auth.resetPassword.title"
        :subtitle="MESSAGES.customerUi.auth.resetPassword.subtitle"
    >
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.email"
                :label="MESSAGES.customerUi.auth.email"
                type="email"
                autocomplete="email"
                :error-messages="form.errors.email"
                required
            />
            <v-text-field
                v-model="form.password"
                :label="MESSAGES.customerUi.auth.resetPassword.title"
                type="password"
                autocomplete="new-password"
                :error-messages="form.errors.password"
                required
            />
            <v-text-field
                v-model="form.password_confirmation"
                :label="MESSAGES.customerUi.auth.resetPassword.confirmation"
                type="password"
                autocomplete="new-password"
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
                {{ MESSAGES.customerUi.auth.resetPassword.submit }}
            </v-btn>
        </v-form>
    </AuthCard>
</template>
