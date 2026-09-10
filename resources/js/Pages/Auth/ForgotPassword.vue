<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

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
    <AuthCard title="パスワード再設定" subtitle="再設定用リンクをメールでお送りします。">
        <v-alert v-if="status" type="success" variant="tonal" density="comfortable" class="mb-4">
            {{ status }}
        </v-alert>
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.email"
                label="メールアドレス"
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
                再設定リンクを送信
            </v-btn>
        </v-form>

        <template #footer>
            <a href="/login" class="text-body-2">ログインへ戻る</a>
        </template>
    </AuthCard>
</template>
