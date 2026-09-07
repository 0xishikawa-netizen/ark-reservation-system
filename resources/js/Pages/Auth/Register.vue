<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    kana: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = (): void => {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="520" title="新規登録">
                <v-card-text>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-model="form.name"
                            label="お名前"
                            autocomplete="name"
                            :error-messages="form.errors.name"
                            required
                        />
                        <v-text-field
                            v-model="form.kana"
                            label="フリガナ"
                            autocomplete="off"
                            :error-messages="form.errors.kana"
                            required
                        />
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
                            autocomplete="new-password"
                            :error-messages="form.errors.password"
                            required
                        />
                        <v-text-field
                            v-model="form.password_confirmation"
                            label="パスワード（確認）"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            登録する
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
