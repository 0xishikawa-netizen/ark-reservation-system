<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

defineProps<{
    status?: string | null;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = (): void => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthCard title="ログイン" subtitle="ご登録のメールアドレスとパスワードでログインしてください。">
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
            <v-text-field
                v-model="form.password"
                label="パスワード"
                type="password"
                autocomplete="current-password"
                :error-messages="form.errors.password"
                required
            />
            <div class="d-flex align-center justify-space-between mb-2">
                <v-checkbox
                    v-model="form.remember"
                    label="ログイン状態を保持する"
                    density="compact"
                    hide-details
                />
                <a href="/forgot-password" class="text-body-2">パスワードを忘れた方</a>
            </div>
            <v-btn
                type="submit"
                color="primary"
                variant="flat"
                size="large"
                block
                :loading="form.processing"
            >
                ログイン
            </v-btn>
        </v-form>

        <div class="ark-auth-divider my-5" role="separator" aria-label="または">
            <span>または</span>
        </div>

        <v-btn
            :href="'/auth/google/redirect'"
            variant="outlined"
            size="large"
            block
            class="ark-google-btn"
        >
            <span class="ark-google-btn__icon" aria-hidden="true">
                <svg viewBox="0 0 18 18" width="18" height="18">
                    <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z" />
                    <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z" />
                    <path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.05l3.01-2.33z" />
                    <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z" />
                </svg>
            </span>
            Google でログイン
        </v-btn>

        <template #footer>
            <span class="text-body-2 text-medium-emphasis">アカウントをお持ちでない方は</span>
            <a href="/register" class="text-body-2 font-weight-medium ml-1">新規登録</a>
        </template>
    </AuthCard>
</template>
