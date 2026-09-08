<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { getPasskeyAssertion, isPasskeySupported } from '@/lib/webauthn';

defineProps<{
    status?: string | null;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = (): void => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};

// Passkey ログイン（スタッフの第一選択）。パスワード入力なしで完結する。
const passkeySupported = isPasskeySupported();
const passkeyLoading = ref(false);
const passkeyError = ref<string | null>(null);

const loginWithPasskey = async (): Promise<void> => {
    if (!passkeySupported || passkeyLoading.value) {
        return;
    }
    passkeyLoading.value = true;
    passkeyError.value = null;

    try {
        const response = await fetch('/passkeys/login/options', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('options-failed');
        }

        const options = await response.json();
        const assertion = await getPasskeyAssertion(options.publicKey ?? options);

        router.post('/passkeys/login', { credential: assertion }, {
            onError: () => {
                passkeyError.value = 'Passkey でログインできませんでした。';
            },
            onFinish: () => {
                passkeyLoading.value = false;
            },
        });
    } catch (error) {
        const cancelled =
            error instanceof Error && error.message === 'passkey-assertion-cancelled';
        passkeyError.value = cancelled
            ? 'ログインがキャンセルされました。'
            : 'Passkey を利用できませんでした。パスワードでログインしてください。';
        passkeyLoading.value = false;
    }
};
</script>

<template>
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="ログイン">
                <v-card-text>
                    <v-alert v-if="status" type="success" class="mb-4">{{ status }}</v-alert>
                    <v-alert v-if="passkeyError" type="error" variant="tonal" density="compact" class="mb-4">
                        {{ passkeyError }}
                    </v-alert>

                    <template v-if="passkeySupported">
                        <v-btn
                            block
                            color="primary"
                            variant="flat"
                            class="mb-2"
                            :loading="passkeyLoading"
                            @click="loginWithPasskey"
                        >
                            Passkey でログイン
                        </v-btn>
                        <p class="text-caption text-medium-emphasis text-center mb-3">
                            Touch ID / Face ID / Windows Hello
                        </p>
                        <v-divider class="mb-4" />
                    </template>

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
                            label="パスワード"
                            type="password"
                            autocomplete="current-password"
                            :error-messages="form.errors.password"
                            required
                        />
                        <v-checkbox v-model="form.remember" label="ログイン状態を保持する" />
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            ログイン
                        </v-btn>
                    </v-form>
                </v-card-text>
                <v-card-actions class="justify-space-between px-4 pb-4">
                    <a href="/forgot-password">パスワードを忘れた方</a>
                    <a href="/register">新規登録</a>
                </v-card-actions>
            </v-card>
        </v-main>
    </v-app>
</template>
