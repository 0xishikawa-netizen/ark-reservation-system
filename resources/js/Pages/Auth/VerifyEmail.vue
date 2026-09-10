<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

defineProps<{
    status?: string | null;
}>();

const resendForm = useForm({});
const logoutForm = useForm({});

const resend = (): void => {
    resendForm.post('/email/verification-notification');
};

const logout = (): void => {
    logoutForm.post('/logout');
};
</script>

<template>
    <AuthCard
        title="メールアドレスの確認"
        subtitle="ご登録のメールアドレスへ確認リンクを送信しました。リンクを開いて登録を完了してください。"
    >
        <v-alert v-if="status" type="success" variant="tonal" density="comfortable" class="mb-4">
            {{ status }}
        </v-alert>
        <v-btn
            color="primary"
            variant="flat"
            size="large"
            block
            :loading="resendForm.processing"
            @click="resend"
        >
            確認メールを再送信
        </v-btn>

        <template #footer>
            <v-btn variant="text" size="small" :loading="logoutForm.processing" @click="logout">
                ログアウト
            </v-btn>
        </template>
    </AuthCard>
</template>
