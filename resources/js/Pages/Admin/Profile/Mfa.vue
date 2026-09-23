<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

defineProps<{
    mfa: {
        required: boolean;
        satisfied: boolean;
        totp_confirmed: boolean;
        phone_verified: boolean;
    };
    totp: { pending: boolean; enabled: boolean };
    phone: { verified: boolean; masked: string | null; pending_verification: boolean };
    google: { linked: boolean };
}>();

const linkGoogle = (): void => {
    window.location.href = '/auth/google/link?return=/admin/mfa';
};
const unlinkGoogle = (): void => {
    router.delete('/auth/google/unlink', { preserveScroll: true });
};

const phoneForm = useForm({ phone: '' });
const codeForm = useForm({ code: '' });

const sendCode = (): void => {
    phoneForm.post('/admin/mfa/phone', { preserveScroll: true });
};
const verifyCode = (): void => {
    codeForm.post('/admin/mfa/phone/verify', {
        preserveScroll: true,
        onSuccess: () => codeForm.reset('code'),
    });
};
</script>

<template>
    <Head title="二段階認証の設定" />

    <v-container class="py-4" style="max-width: 820px">
        <h1 class="text-h6 mb-4">二段階認証（MFA）の設定</h1>

        <v-alert v-if="!mfa.satisfied" type="warning" variant="tonal" class="mb-4">
            <div class="font-weight-medium">認証アプリの登録が必要です</div>
            <div class="text-body-2">
                {{ MESSAGES.auth.twoFactorRequired }}
            </div>
        </v-alert>
        <v-alert v-else type="success" variant="tonal" class="mb-4">
            <div class="font-weight-medium">二段階認証は有効です</div>
            <div class="text-body-2">ログイン時に認証アプリの6桁コードが求められます。</div>
        </v-alert>

        <!-- TOTP -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="text-subtitle-1">
                認証アプリ（TOTP）
                <v-chip
                    :color="totp.enabled ? 'success' : 'grey'"
                    size="small"
                    variant="flat"
                    class="ml-2"
                >
                    {{ totp.enabled ? '有効' : '未設定' }}
                </v-chip>
            </v-card-title>
            <v-card-text>
                <p class="text-body-2 mb-3">
                    Google Authenticator / 1Password / Authy などの認証アプリに
                    QRコードを読み込み、表示される6桁コードでログインします。
                </p>
                <v-btn color="primary" variant="flat" href="/admin/two-factor-setup">
                    {{ totp.enabled ? '認証アプリの再設定' : '認証アプリを設定する' }}
                </v-btn>
            </v-card-text>
        </v-card>

        <!-- SMS -->
        <v-card variant="outlined">
            <v-card-title class="text-subtitle-1">
                SMS（予備の連絡先）
                <v-chip
                    :color="phone.verified ? 'success' : 'grey'"
                    size="small"
                    variant="flat"
                    class="ml-2"
                >
                    {{ phone.verified ? '確認済み' : '未確認' }}
                </v-chip>
            </v-card-title>
            <v-card-text>
                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    SMS は<strong>予備の連絡手段</strong>です。これだけでは二段階認証の要件を満たしません
                    （認証アプリの設定が必要です）。
                </v-alert>

                <p v-if="phone.masked" class="text-body-2 mb-3">
                    登録番号: <code>{{ phone.masked }}</code>
                </p>

                <div class="d-flex ga-3 align-center flex-wrap mb-3">
                    <v-text-field
                        v-model="phoneForm.phone"
                        label="携帯電話番号"
                        density="compact"
                        hide-details="auto"
                        :error-messages="phoneForm.errors.phone"
                        style="max-width: 280px"
                    />
                    <v-btn
                        variant="tonal"
                        :loading="phoneForm.processing"
                        :disabled="!phoneForm.phone"
                        @click="sendCode"
                    >
                        認証コードを送信
                    </v-btn>
                </div>

                <div v-if="phone.pending_verification" class="d-flex ga-3 align-center flex-wrap">
                    <v-text-field
                        v-model="codeForm.code"
                        label="認証コード"
                        density="compact"
                        hide-details="auto"
                        :error-messages="codeForm.errors.code"
                        style="max-width: 200px"
                    />
                    <v-btn
                        color="primary"
                        :loading="codeForm.processing"
                        :disabled="!codeForm.code"
                        @click="verifyCode"
                    >
                        確認する
                    </v-btn>
                </div>
            </v-card-text>
        </v-card>

        <!-- Google 連携（二段階認証を代替しない） -->
        <v-card variant="outlined" class="mt-4">
            <v-card-title class="text-subtitle-1">
                Google アカウント連携
                <v-chip
                    :color="google.linked ? 'success' : 'grey'"
                    size="small"
                    variant="flat"
                    class="ml-2"
                >
                    {{ google.linked ? '連携済み' : '未連携' }}
                </v-chip>
            </v-card-title>
            <v-card-text>
                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    Google 連携はログインを簡単にするための手段です。
                    <strong>二段階認証（TOTP）は引き続き必須</strong>で、Google ログインでも省略されません。
                </v-alert>
                <v-btn
                    v-if="!google.linked"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-google"
                    @click="linkGoogle"
                >
                    Google アカウントを連携
                </v-btn>
                <v-btn v-else color="error" variant="outlined" @click="unlinkGoogle">
                    連携を解除
                </v-btn>
            </v-card-text>
        </v-card>
    </v-container>
</template>
