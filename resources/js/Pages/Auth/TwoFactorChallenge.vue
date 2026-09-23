<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';

const props = defineProps<{
    trustedDeviceTtlDays: number;
}>();

const useRecoveryCode = ref(false);
const form = useForm({
    code: '',
    recovery_code: '',
    trust_device: true,
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
            <v-checkbox
                v-model="form.trust_device"
                :label="`この端末を${props.trustedDeviceTtlDays}日間信頼する（次回から2段階認証を省略）`"
                density="compact"
                hide-details
                class="mb-2"
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
