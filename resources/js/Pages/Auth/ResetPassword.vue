<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    token: string;
    email: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = (): void => {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="新しいパスワード">
                <v-card-text>
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
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            パスワードを再設定
                        </v-btn>
                    </v-form>
                </v-card-text>
            </v-card>
        </v-main>
    </v-app>
</template>
