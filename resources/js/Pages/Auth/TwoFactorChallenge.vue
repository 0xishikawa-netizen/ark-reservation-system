<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

const useRecoveryCode = ref(false);
const form = useForm({
    code: '',
    recovery_code: '',
});

const submit = (): void => {
    form.post('/two-factor-challenge', {
        onFinish: () => form.reset('code', 'recovery_code'),
    });
};
</script>

<template>
    <AuthCard
        title="2段階認証"
        :subtitle="
            useRecoveryCode
                ? 'リカバリーコードを入力してください。'
                : '認証アプリに表示されている6桁の確認コードを入力してください。'
        "
    >
        <v-form @submit.prevent="submit">
            <v-text-field
                v-if="!useRecoveryCode"
                v-model="form.code"
                label="6桁コード"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                :error-messages="form.errors.code"
                autofocus
            />
            <v-text-field
                v-else
                v-model="form.recovery_code"
                label="リカバリーコード"
                autocomplete="one-time-code"
                :error-messages="form.errors.recovery_code"
                autofocus
            />
            <v-btn
                type="submit"
                color="primary"
                variant="flat"
                size="large"
                block
                :loading="form.processing"
            >
                確認する
            </v-btn>
        </v-form>

        <template #footer>
            <v-btn variant="text" size="small" @click="useRecoveryCode = !useRecoveryCode">
                {{ useRecoveryCode ? '認証コードを使う' : 'リカバリーコードを使う' }}
            </v-btn>
        </template>
    </AuthCard>
</template>
