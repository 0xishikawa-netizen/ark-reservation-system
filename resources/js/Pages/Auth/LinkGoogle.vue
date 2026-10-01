<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

const props = defineProps<{
    email: string;
}>();

const form = useForm({
    email: props.email,
    password: '',
});

const submit = (): void => {
    form.post('/auth/google/link-existing', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthCard
        title="既存アカウントとの連携"
        subtitle="この Google アカウントのメールアドレスで登録済みの ARK アカウントが見つかりました。パスワードを入力すると Google 連携が有効になります。"
    >
        <v-form @submit.prevent="submit">
            <v-text-field
                :model-value="form.email"
                label="メールアドレス"
                type="email"
                readonly
                :error-messages="form.errors.email"
            />
            <v-text-field
                v-model="form.password"
                label="ARK のパスワード"
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
                パスワードを確認して連携
            </v-btn>
        </v-form>

        <template #footer>
            <a href="/login" class="text-body-2">パスワードでログインする</a>
        </template>
    </AuthCard>
</template>
