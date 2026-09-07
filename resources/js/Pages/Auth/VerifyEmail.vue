<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

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
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="520" title="メールアドレスの確認">
                <v-card-text>
                    <p class="mb-4">
                        登録したメールアドレスへ確認リンクを送信しました。リンクを開いて登録を完了してください。
                    </p>
                    <v-alert v-if="status" type="success" class="mb-4">{{ status }}</v-alert>
                    <v-btn color="primary" block :loading="resendForm.processing" @click="resend">
                        確認メールを再送信
                    </v-btn>
                </v-card-text>
                <v-card-actions class="justify-end px-4 pb-4">
                    <v-btn variant="text" :loading="logoutForm.processing" @click="logout">
                        ログアウト
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-main>
    </v-app>
</template>
