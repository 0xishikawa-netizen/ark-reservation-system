<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface CheckoutProps {
    reservation: {
        id: number;
        service_name: string;
        starts_at: string;
        payment_expires_at: string | null;
    };
    payment: {
        id: number;
        amount: number;
        currency: string;
        status: string;
    };
    stripe: {
        publishable_key: string;
        client_secret: string | null;
    };
}

const props = defineProps<CheckoutProps>();

/** Stripe.js は CSP で許可した公式ドメインからのみ読み込む。 */
const STRIPE_JS = 'https://js.stripe.com/v3';

interface StripeElement {
    mount: (selector: string) => void;
    unmount: () => void;
}
interface StripeElements {
    create: (type: string, options?: Record<string, unknown>) => StripeElement;
}
interface StripeConfirmResult {
    error?: { type?: string; message?: string };
}
interface StripeInstance {
    elements: (options: Record<string, unknown>) => StripeElements;
    confirmPayment: (options: Record<string, unknown>) => Promise<StripeConfirmResult>;
}
declare global {
    interface Window {
        Stripe?: (key: string) => StripeInstance;
    }
}

const loading = ref(true);
const submitting = ref(false);
const errorMessage = ref<string | null>(null);
const remainingSeconds = ref<number | null>(null);

let stripe: StripeInstance | null = null;
let elements: StripeElements | null = null;
let paymentElement: StripeElement | null = null;
let timer: ReturnType<typeof setInterval> | null = null;

const amountLabel = computed(() => `${props.payment.amount.toLocaleString()} 円`);

const expired = computed(
    () => remainingSeconds.value !== null && remainingSeconds.value <= 0,
);

const remainingLabel = computed(() => {
    if (remainingSeconds.value === null) {
        return null;
    }
    const total = Math.max(0, remainingSeconds.value);
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${minutes}分${String(seconds).padStart(2, '0')}秒`;
});

const loadStripeJs = (): Promise<void> =>
    new Promise((resolve, reject) => {
        if (window.Stripe) {
            resolve();

            return;
        }
        const script = document.createElement('script');
        script.src = STRIPE_JS;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('stripe-js-load-failed'));
        document.head.appendChild(script);
    });

const startCountdown = (): void => {
    if (!props.reservation.payment_expires_at) {
        return;
    }
    const deadline = new Date(props.reservation.payment_expires_at).getTime();
    const tick = (): void => {
        remainingSeconds.value = Math.floor((deadline - Date.now()) / 1000);
    };
    tick();
    timer = setInterval(tick, 1000);
};

onMounted(async () => {
    startCountdown();

    if (!props.stripe.client_secret) {
        errorMessage.value = '決済を開始できませんでした。もう一度お試しください。';
        loading.value = false;

        return;
    }

    try {
        await loadStripeJs();
        stripe = window.Stripe ? window.Stripe(props.stripe.publishable_key) : null;

        if (!stripe) {
            throw new Error('stripe-init-failed');
        }

        elements = stripe.elements({
            clientSecret: props.stripe.client_secret,
            appearance: { theme: 'stripe' },
        });
        paymentElement = elements.create('payment');
        paymentElement.mount('#payment-element');
    } catch {
        // 生のエラー内容は表示しない。
        errorMessage.value = '決済フォームを読み込めませんでした。通信環境をご確認ください。';
    } finally {
        loading.value = false;
    }
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
    paymentElement?.unmount();
});

const submit = async (): Promise<void> => {
    if (!stripe || !elements || submitting.value || expired.value) {
        return;
    }

    submitting.value = true;
    errorMessage.value = null;

    const result = await stripe.confirmPayment({
        elements,
        redirect: 'if_required',
    });

    if (result.error) {
        // Stripe の生メッセージはそのまま出さず、種別に応じた案内に置き換える。
        errorMessage.value =
            result.error.type === 'card_error' || result.error.type === 'validation_error'
                ? 'カード情報をご確認のうえ、もう一度お試しください。'
                : '決済を完了できませんでした。時間をおいて再度お試しください。';
        submitting.value = false;

        return;
    }

    // 認証結果はサーバー側で Stripe に問い合わせて確定する（client の申告を信用しない）。
    router.post(
        `/mypage/reservations/${props.reservation.id}/payment/sync`,
        {},
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="お支払い" />

    <v-container class="py-6" style="max-width: 640px">
        <h1 class="text-h6 mb-4">お支払い</h1>

        <v-card class="mb-4" variant="outlined">
            <v-card-text>
                <div class="d-flex justify-space-between mb-1">
                    <span class="text-medium-emphasis">メニュー</span>
                    <span>{{ reservation.service_name }}</span>
                </div>
                <div class="d-flex justify-space-between mb-1">
                    <span class="text-medium-emphasis">日時</span>
                    <span>{{ reservation.starts_at }}</span>
                </div>
                <div class="d-flex justify-space-between">
                    <span class="text-medium-emphasis">お支払い金額</span>
                    <span class="font-weight-bold">{{ amountLabel }}</span>
                </div>
            </v-card-text>
        </v-card>

        <v-alert
            v-if="remainingLabel && !expired"
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-4"
        >
            この予約枠はあと <strong>{{ remainingLabel }}</strong> 確保されています。
            期限を過ぎると枠は解放されます。
        </v-alert>

        <v-alert v-if="expired" type="warning" variant="tonal" class="mb-4">
            お支払い期限が切れました。お手数ですが、もう一度ご予約をお取りください。
        </v-alert>

        <v-alert v-if="errorMessage" type="error" variant="tonal" class="mb-4">
            {{ errorMessage }}
        </v-alert>

        <v-card variant="outlined">
            <v-card-text>
                <div v-if="loading" class="text-center py-6">
                    <v-progress-circular indeterminate color="primary" />
                </div>
                <!-- Stripe Payment Element。カード番号・CVC を自前で受け取らない。 -->
                <div id="payment-element" />
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-btn
                    block
                    color="primary"
                    size="large"
                    :loading="submitting"
                    :disabled="loading || expired || !stripe"
                    @click="submit"
                >
                    {{ amountLabel }} を支払う
                </v-btn>
            </v-card-actions>
        </v-card>

        <p class="text-caption text-medium-emphasis mt-4">
            カード情報は Stripe が直接処理します。当店のサーバーには保存されません。
            お支払いが確定した時点でご予約が完了します。
        </p>
    </v-container>
</template>
