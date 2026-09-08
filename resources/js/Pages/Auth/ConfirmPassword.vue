<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { getPasskeyAssertion, isPasskeySupported } from '@/lib/webauthn';

const form = useForm({
    password: '',
});

const submit = (): void => {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset('password'),
    });
};

// Passkey での再認証。パスワード確認は fallback として残す。
const passkeySupported = isPasskeySupported();
const passkeyLoading = ref(false);
const passkeyError = ref<string | null>(null);

const confirmWithPasskey = async (): Promise<void> => {
    if (!passkeySupported || passkeyLoading.value) {
        return;
    }
    passkeyLoading.value = true;
    passkeyError.value = null;

    try {
        const response = await fetch('/passkeys/confirm/options', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('options-failed');
        }

        const options = await response.json();
        const assertion = await getPasskeyAssertion(options.publicKey ?? options);

        router.post('/passkeys/confirm', { credential: assertion }, {
            onError: () => {
                passkeyError.value = 'Passkey で確認できませんでした。パスワードをご利用ください。';
            },
            onFinish: () => {
                passkeyLoading.value = false;
            },
        });
    } catch (error) {
        const cancelled =
            error instanceof Error && error.message === 'passkey-assertion-cancelled';
        passkeyError.value = cancelled
            ? '確認がキャンセルされました。'
            : 'Passkey を利用できませんでした。パスワードをご利用ください。';
        passkeyLoading.value = false;
    }
};
</script>

<template>
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="パスワードの確認">
                <v-card-text>
                    <p class="mb-4">この操作を続行するには本人確認が必要です。</p>
                    <v-alert v-if="passkeyError" type="error" variant="tonal" density="compact" class="mb-4">
                        {{ passkeyError }}
                    </v-alert>

                    <template v-if="passkeySupported">
                        <v-btn
                            block
                            color="primary"
                            variant="flat"
                            class="mb-3"
                            :loading="passkeyLoading"
                            @click="confirmWithPasskey"
                        >
                            Passkey で確認
                        </v-btn>
                        <v-divider class="mb-4" />
                        <p class="text-caption text-medium-emphasis mb-2">
                            または、パスワードを入力してください。
                        </p>
                    </template>

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
