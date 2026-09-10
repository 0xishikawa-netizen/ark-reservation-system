<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

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
    <AuthCard title="パスワードの確認" subtitle="この操作を続けるには、本人確認のためパスワードを入力してください。">
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.password"
                label="パスワード"
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
                確認する
            </v-btn>
        </v-form>
    </AuthCard>
</template>
