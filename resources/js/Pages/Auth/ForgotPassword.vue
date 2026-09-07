<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

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
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="パスワード再設定">
                <v-card-text>
                    <p class="mb-4">再設定用リンクをメールでお送りします。</p>
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
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            再設定リンクを送信
                        </v-btn>
                    </v-form>
                </v-card-text>
                <v-card-actions class="justify-end px-4 pb-4">
                    <a href="/login">ログインへ戻る</a>
                </v-card-actions>
            </v-card>
        </v-main>
    </v-app>
</template>
