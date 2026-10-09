<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthCard from '@/components/auth/AuthCard.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

/** 認証アプリの確認コードの桁数。 */
const TOTP_CODE_LENGTH = 6;

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
        :title="MESSAGES.customerUi.auth.twoFactor.title"
        :subtitle="
            useRecoveryCode
                ? MESSAGES.customerUi.auth.twoFactor.recoverySubtitle
                : MESSAGES.customerUi.auth.twoFactor.codeSubtitle
        "
    >
        <v-form @submit.prevent="submit">
            <v-text-field
                v-if="!useRecoveryCode"
                v-model="form.code"
                :label="MESSAGES.customerUi.auth.twoFactor.code"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="TOTP_CODE_LENGTH"
                :error-messages="form.errors.code"
                autofocus
            />
            <v-text-field
                v-else
                v-model="form.recovery_code"
                :label="MESSAGES.customerUi.auth.twoFactor.recoveryCode"
                autocomplete="one-time-code"
                :error-messages="form.errors.recovery_code"
                autofocus
            />
            <v-checkbox
                v-model="form.trust_device"
                :label="fillMessage(MESSAGES.customerUi.auth.twoFactor.trustDevice, { days: String(props.trustedDeviceTtlDays) })"
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
                {{ MESSAGES.customerUi.auth.confirm }}
            </v-btn>
        </v-form>

        <template #footer>
            <v-btn variant="text" size="small" @click="useRecoveryCode = !useRecoveryCode">
                {{ useRecoveryCode ? MESSAGES.customerUi.auth.twoFactor.useCode : MESSAGES.customerUi.auth.twoFactor.useRecovery }}
            </v-btn>
        </template>
    </AuthCard>
</template>
