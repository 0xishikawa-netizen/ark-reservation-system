<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

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
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="パスワードの確認">
                <v-card-text>
                    <p class="mb-4">続行するにはパスワードを入力してください。</p>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.password"
                            label="パスワード"
                            type="password"
                            autocomplete="current-password"
                            :error-messages="form.errors.password"
                            required
                        />
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            確認する
                        </v-btn>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-main>
    </v-app>
</template>
