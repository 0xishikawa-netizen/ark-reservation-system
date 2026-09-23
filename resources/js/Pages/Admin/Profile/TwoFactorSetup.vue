<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    twoFactorPending: boolean;
    twoFactorEnabled: boolean;
}>();

const passwordConfirmed = ref(false);
const qrCodeUrl = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);
const loadingConfiguration = ref(false);
const errorMessage = ref('');

const enableForm = useForm({});
const confirmForm = useForm({
    code: '',
});

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

const isStringArray = (value: unknown): value is string[] =>
    Array.isArray(value) && value.every((entry: unknown) => typeof entry === 'string');

const fetchJson = async (url: string): Promise<unknown> => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (response.status === 423) {
        passwordConfirmed.value = false;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}.`);
    }

    return response.json() as Promise<unknown>;
};

const replaceQrCode = (svg: string): void => {
    if (qrCodeUrl.value !== null) {
        URL.revokeObjectURL(qrCodeUrl.value);
    }

    qrCodeUrl.value = URL.createObjectURL(
        new Blob([svg], { type: 'image/svg+xml' }),
    );
};

const loadPasswordStatus = async (): Promise<void> => {
    try {
        const data = await fetchJson('/user/confirmed-password-status');
        passwordConfirmed.value = isRecord(data) && data.confirmed === true;
    } catch {
        errorMessage.value = MESSAGES.auth.passwordStatusFailed;
    }
};

const loadConfiguration = async (): Promise<void> => {
    loadingConfiguration.value = true;
    errorMessage.value = '';

    try {
        const [qrData, recoveryData] = await Promise.all([
            fetchJson('/user/two-factor-qr-code'),
            fetchJson('/user/two-factor-recovery-codes'),
        ]);

        if (!isRecord(qrData) || typeof qrData.svg !== 'string') {
            throw new Error('The QR response is invalid.');
        }

        if (!isStringArray(recoveryData)) {
            throw new Error('The recovery code response is invalid.');
        }

        replaceQrCode(qrData.svg);
        recoveryCodes.value = recoveryData;
    } catch {
        errorMessage.value = MESSAGES.auth.twoFactorSetupFailed;
    } finally {
        loadingConfiguration.value = false;
    }
};

const enableTwoFactor = async (): Promise<void> => {
    errorMessage.value = '';
    await loadPasswordStatus();

    if (!passwordConfirmed.value) {
        return;
    }

    enableForm.post('/user/two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => loadConfiguration(),
    });
};

const confirmTwoFactor = (): void => {
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => router.visit('/admin'),
        onFinish: () => confirmForm.reset('code'),
    });
};

onMounted(async () => {
    await loadPasswordStatus();

    if (props.twoFactorPending && passwordConfirmed.value) {
        await loadConfiguration();
    }
});

onBeforeUnmount(() => {
    if (qrCodeUrl.value !== null) {
        URL.revokeObjectURL(qrCodeUrl.value);
    }
});
</script>

<template>
    <div class="d-flex justify-center pa-4">
        <v-card width="100%" max-width="680" title="スタッフ2段階認証の設定">
                <v-card-text>
                    <v-alert v-if="errorMessage" type="error" class="mb-4">
                        {{ errorMessage }}
                    </v-alert>

                    <template v-if="twoFactorEnabled">
                        <v-alert type="success" class="mb-4">
                            {{ MESSAGES.auth.twoFactorConfigured }}
                        </v-alert>
                        <v-btn color="primary" href="/admin">管理画面へ</v-btn>
                    </template>

                    <template v-else-if="!passwordConfirmed">
                        <p class="mb-4">
                            設定を始める前に、現在のパスワードを確認します。
                        </p>
                        <v-btn color="primary" href="/user/confirm-password">
                            パスワードを確認する
                        </v-btn>
                    </template>

                    <template v-else-if="!twoFactorPending">
                        <p class="mb-4">
                            認証アプリで使用するQRコードとリカバリーコードを発行します。
                        </p>
                        <v-btn
                            color="primary"
                            :loading="enableForm.processing"
                            @click="enableTwoFactor"
                        >
                            2段階認証を有効化する
                        </v-btn>
                    </template>

                    <template v-else>
                        <v-progress-linear
                            v-if="loadingConfiguration"
                            indeterminate
                            color="primary"
                            class="mb-4"
                        />

                        <v-btn
                            v-else-if="qrCodeUrl === null"
                            variant="tonal"
                            class="mb-4"
                            @click="loadConfiguration"
                        >
                            設定情報を再取得
                        </v-btn>

                        <template v-if="qrCodeUrl !== null">
                            <p class="mb-3">
                                認証アプリでQRコードを読み取り、表示された6桁のコードを入力してください。
                            </p>
                            <v-img
                                :src="qrCodeUrl"
                                alt="2段階認証設定用QRコード"
                                width="240"
                                height="240"
                                class="mb-5 mx-auto"
                            />

                            <v-alert type="warning" variant="tonal" class="mb-5">
                                <p class="font-weight-bold mb-2">
                                    {{ MESSAGES.auth.saveRecoveryCodes }}
                                </p>
                                <ul class="pl-5">
                                    <li v-for="code in recoveryCodes" :key="code">
                                        <code>{{ code }}</code>
                                    </li>
                                </ul>
                            </v-alert>

                            <v-form @submit.prevent="confirmTwoFactor">
                                <v-text-field
                                    v-model="confirmForm.code"
                                    label="確認コード"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    :error-messages="confirmForm.errors.code"
                                    maxlength="6"
                                    required
                                />
                                <v-btn
                                    type="submit"
                                    color="primary"
                                    :loading="confirmForm.processing"
                                >
                                    確認して管理画面へ
                                </v-btn>
                            </v-form>
                        </template>
                    </template>
                </v-card-text>
        </v-card>
    </div>
</template>
