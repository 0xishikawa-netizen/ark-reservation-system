<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthCard from "@/components/auth/AuthCard.vue";

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
        title="新しいパスワード"
        subtitle="新しいパスワードを設定してください。"
    >
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.email"
                label="メールアドレス"
                type="email"
                autocomplete="email"
                :error-messages="form.errors.email"
                required
            />
            <v-text-field
                v-model="form.password"
                label="新しいパスワード"
                type="password"
                autocomplete="new-password"
                :error-messages="form.errors.password"
                required
            />
            <v-text-field
                v-model="form.password_confirmation"
                label="新しいパスワード（確認）"
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
                パスワードを再設定
            </v-btn>
        </v-form>
    </AuthCard>
</template>
