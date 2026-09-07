<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

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
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="ログイン">
                <v-card-text>
                    <v-alert v-if="status" type="success" class="mb-4">{{ status }}</v-alert>
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
                            label="パスワード"
                            type="password"
                            autocomplete="current-password"
                            :error-messages="form.errors.password"
                            required
                        />
                        <v-checkbox v-model="form.remember" label="ログイン状態を保持する" />
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            ログイン
                        </v-btn>
                    </v-form>
                </v-card-text>
                <v-card-actions class="justify-space-between px-4 pb-4">
                    <a href="/forgot-password">パスワードを忘れた方</a>
                    <a href="/register">新規登録</a>
                </v-card-actions>
            </v-card>
        </v-main>
    </v-app>
</template>
