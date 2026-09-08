<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { createPasskey, isPasskeySupported } from '@/lib/webauthn';

defineOptions({ layout: AdminLayout });

interface PasskeyRow {
    id: number;
    name: string;
    authenticator: string | null;
    created_at: string | null;
    last_used_at: string | null;
}

defineProps<{
    mfa: {
        required: boolean;
        satisfied: boolean;
        passkey_count: number;
        totp_confirmed: boolean;
        phone_verified: boolean;
        should_promote_passkey: boolean;
    };
    passkeys: PasskeyRow[];
    totp: { pending: boolean; enabled: boolean };
    phone: { verified: boolean; masked: string | null; pending_verification: boolean };
    can_delete_passkey: boolean;
}>();

const supported = isPasskeySupported();
const registering = ref(false);
const passkeyError = ref<string | null>(null);
const passkeyName = ref('');

const registerPasskey = async (): Promise<void> => {
    if (!supported || registering.value) {
        return;
    }
    registering.value = true;
    passkeyError.value = null;

    try {
        const optionsResponse = await fetch('/user/passkeys/options', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!optionsResponse.ok) {
            throw new Error('options-failed');
        }

        const options = await optionsResponse.json();
        const credential = await createPasskey(options.publicKey ?? options);

        router.post(
            '/user/passkeys',
            {
                name: passkeyName.value || 'このデバイス',
                // サーバー（laravel/passkeys）は credential 配下を期待する
                credential,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    passkeyName.value = '';
                },
                onError: () => {
                    passkeyError.value = 'Passkey を登録できませんでした。もう一度お試しください。';
                },
                onFinish: () => {
                    registering.value = false;
                },
            },
        );
    } catch (error) {
        // 生のエラーは表示しない（キャンセルも含めて同じ扱い）。
        const cancelled =
            error instanceof Error && error.message === 'passkey-creation-cancelled';
        passkeyError.value = cancelled
            ? '登録がキャンセルされました。'
            : 'このデバイスでは Passkey を登録できませんでした。';
        registering.value = false;
    }
};

const deleteForm = useForm({});
const destroyPasskey = (id: number): void => {
    deleteForm.delete(`/user/passkeys/${id}`, { preserveScroll: true });
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
    <Head title="多要素認証の設定" />

    <v-container class="py-4" style="max-width: 820px">
        <h1 class="text-h6 mb-4">多要素認証（MFA）の設定</h1>

        <v-alert
            v-if="!mfa.satisfied"
            type="warning"
            variant="tonal"
            class="mb-4"
        >
            <div class="font-weight-medium">認証手段の登録が必要です</div>
            <div class="text-body-2">
                管理画面を利用するには、Passkey か認証アプリ（TOTP）のいずれかを登録してください。
                <strong>Passkey を推奨します。</strong>
            </div>
        </v-alert>

        <v-alert
            v-else-if="mfa.should_promote_passkey"
            type="info"
            variant="tonal"
            class="mb-4"
        >
            <div class="font-weight-medium">Passkey への移行をおすすめします</div>
            <div class="text-body-2">
                Passkey は Touch ID / Face ID / Windows Hello で認証でき、
                フィッシングに強く、毎回コードを入力する必要がありません。
                認証アプリ（TOTP）は予備として残せます。
            </div>
        </v-alert>

        <!-- Passkey -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="text-subtitle-1">
                Passkey（推奨）
                <v-chip v-if="mfa.passkey_count > 0" color="green" size="small" variant="flat" class="ml-2">
                    {{ mfa.passkey_count }} 件
                </v-chip>
            </v-card-title>
            <v-card-text>
                <v-alert v-if="!supported" type="info" variant="tonal" density="compact" class="mb-3">
                    このブラウザは Passkey に対応していません。認証アプリ（TOTP）をご利用ください。
                </v-alert>
                <v-alert v-if="passkeyError" type="error" variant="tonal" density="compact" class="mb-3">
                    {{ passkeyError }}
                </v-alert>

                <v-table v-if="passkeys.length > 0" density="compact" class="mb-4">
                    <thead>
                        <tr>
                            <th>名前</th><th>認証器</th><th>登録日</th><th>最終利用</th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="passkey in passkeys" :key="passkey.id">
                            <td>{{ passkey.name }}</td>
                            <td class="text-caption">{{ passkey.authenticator ?? '—' }}</td>
                            <td class="text-caption">{{ passkey.created_at ?? '—' }}</td>
                            <td class="text-caption">{{ passkey.last_used_at ?? '未使用' }}</td>
                            <td class="text-right">
                                <v-btn
                                    size="small"
                                    variant="text"
                                    color="error"
                                    :disabled="!can_delete_passkey && passkeys.length === 1"
                                    @click="destroyPasskey(passkey.id)"
                                >
                                    削除
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <v-alert
                    v-if="!can_delete_passkey && passkeys.length > 0"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-3"
                >
                    これが最後の認証手段のため削除できません。
                    別の Passkey か認証アプリを登録すると削除できるようになります。
                </v-alert>

                <div class="d-flex ga-3 align-center flex-wrap">
                    <v-text-field
                        v-model="passkeyName"
                        label="デバイス名（任意）"
                        placeholder="例: 自分の MacBook"
                        density="compact"
                        hide-details
                        style="max-width: 280px"
                    />
                    <v-btn
                        color="primary"
                        :loading="registering"
                        :disabled="!supported"
                        @click="registerPasskey"
                    >
                        Passkey を追加
                    </v-btn>
                </div>
            </v-card-text>
        </v-card>

        <!-- TOTP -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="text-subtitle-1">
                認証アプリ（TOTP）
                <v-chip
                    :color="totp.enabled ? 'green' : 'grey'"
                    size="small"
                    variant="flat"
                    class="ml-2"
                >
                    {{ totp.enabled ? '有効' : '未設定' }}
                </v-chip>
            </v-card-title>
            <v-card-text>
                <p class="text-body-2 mb-3">
                    Passkey が使えない環境向けの代替手段です。
                    既に設定済みの場合はそのままログインに利用できます。
                </p>
                <v-btn variant="tonal" href="/admin/two-factor-setup">
                    認証アプリの設定へ
                </v-btn>
            </v-card-text>
        </v-card>

        <!-- SMS -->
        <v-card variant="outlined">
            <v-card-title class="text-subtitle-1">
                SMS（予備の連絡先）
                <v-chip
                    :color="phone.verified ? 'green' : 'grey'"
                    size="small"
                    variant="flat"
                    class="ml-2"
                >
                    {{ phone.verified ? '確認済み' : '未確認' }}
                </v-chip>
            </v-card-title>
            <v-card-text>
                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    SMS は<strong>予備の手段</strong>です。これだけでは認証手段の要件を満たしません
                    （Passkey か認証アプリのいずれかが必要です）。
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
    </v-container>
</template>
