<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface ConfirmProps {
    plan: { name: string; price: number };
    stripe: { publishable_key: string; client_secret: string };
}

const props = defineProps<ConfirmProps>();

const STRIPE_JS = 'https://js.stripe.com/v3';

type PaymentIntentStatus =
    | 'succeeded'
    | 'processing'
    | 'requires_payment_method'
    | 'requires_action'
    | 'requires_confirmation'
    | 'canceled';

interface StripeConfirmResult {
    error?: { type?: string; code?: string; message?: string };
    paymentIntent?: { status: PaymentIntentStatus };
}

interface StripeInstance {
    confirmCardPayment: (clientSecret: string) => Promise<StripeConfirmResult>;
}

const stripeWindow = window as unknown as { Stripe?: (key: string) => StripeInstance };

const phase = ref<'authenticating' | 'syncing' | 'error'>('authenticating');
const message = ref('カードの本人認証を行っています。しばらくお待ちください。');

function yen(value: number): string {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(value);
}

const loadStripeJs = (): Promise<void> =>
    new Promise((resolve, reject) => {
        if (stripeWindow.Stripe) {
            resolve();

            return;
        }
        const existing = document.querySelector<HTMLScriptElement>(`script[src="${STRIPE_JS}"]`);
        if (existing) {
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener('error', () => reject(new Error('stripe-js')), { once: true });

            return;
        }
        const script = document.createElement('script');
        script.src = STRIPE_JS;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('stripe-js'));
        document.head.appendChild(script);
    });

function goToSync(): void {
    phase.value = 'syncing';
    message.value = 'お支払いの確定を確認しています。';
    // client の申告ではなくサーバーが Stripe から取り込んで最終判断する。
    router.post('/mypage/membership/payment/sync', {}, { preserveScroll: true });
}

function fail(text: string): void {
    phase.value = 'error';
    message.value = text;
}

onMounted(async () => {
    try {
        await loadStripeJs();
        const stripe = stripeWindow.Stripe
            ? stripeWindow.Stripe(props.stripe.publishable_key)
            : null;
        if (!stripe || !props.stripe.client_secret) {
            fail('お支払い画面を読み込めませんでした。時間をおいて再度お試しください。');

            return;
        }

        const result = await stripe.confirmCardPayment(props.stripe.client_secret);

        if (result.error) {
            fail(
                result.error.type === 'card_error' || result.error.code === 'card_declined'
                    ? 'カードの承認が得られませんでした。別のお支払い方法をお試しください。'
                    : '本人認証を完了できませんでした。もう一度お試しください。',
            );

            return;
        }

        const status = result.paymentIntent?.status;
        if (status === 'succeeded' || status === 'processing') {
            goToSync();

            return;
        }

        // requires_payment_method / requires_action 未解決など
        fail('お支払いを完了できませんでした。お支払い方法をご確認のうえ、もう一度お試しください。');
    } catch {
        fail('お支払い画面でエラーが発生しました。時間をおいて再度お試しください。');
    }
});
</script>

<template>
    <Head title="お支払いの確認" />

    <div class="mb-5">
        <h1 class="text-h5 mb-2">お支払いの確認</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
            {{ props.plan.name }}（{{ yen(props.plan.price) }} / 月）のお申し込み
        </p>
    </div>

    <v-card variant="outlined" class="pa-6 text-center">
        <template v-if="phase !== 'error'">
            <v-progress-circular indeterminate color="primary" class="mb-4" />
            <p class="text-body-1 mb-0">{{ message }}</p>
            <p class="text-caption text-medium-emphasis mt-2 mb-0">
                この画面を閉じずにお待ちください。
            </p>
        </template>
        <template v-else>
            <v-icon icon="mdi-alert-circle-outline" color="error" size="40" class="mb-3" />
            <p class="text-body-1 mb-4">{{ message }}</p>
            <v-btn color="primary" variant="flat" @click="router.visit('/mypage/membership')">
                会員ページへ戻る
            </v-btn>
        </template>
    </v-card>
</template>
