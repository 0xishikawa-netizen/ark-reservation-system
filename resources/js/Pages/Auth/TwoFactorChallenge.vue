<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

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
    <v-app>
        <v-main class="d-flex align-center justify-center bg-grey-lighten-4 pa-4">
            <v-card width="100%" max-width="440" title="2段階認証">
                <v-card-text>
                    <p class="mb-4">
                        {{
                            useRecoveryCode
                                ? 'リカバリーコードを入力してください。'
                                : '認証アプリに表示されたコードを入力してください。'
                        }}
                    </p>
                    <v-form @submit.prevent="submit">
                        <v-text-field
                            v-if="!useRecoveryCode"
                            v-model="form.code"
                            label="認証コード"
                            inputmode="numeric"
                            autocomplete="one-time-code"
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
                        <v-btn type="submit" color="primary" block :loading="form.processing">
                            認証する
                        </v-btn>
                    </v-form>
                </v-card-text>
                <v-card-actions class="justify-end px-4 pb-4">
                    <v-btn variant="text" @click="useRecoveryCode = !useRecoveryCode">
                        {{ useRecoveryCode ? '認証コードを使う' : 'リカバリーコードを使う' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-main>
    </v-app>
</template>
