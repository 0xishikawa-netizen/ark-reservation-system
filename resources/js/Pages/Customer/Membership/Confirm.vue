<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';

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
const message = ref<string>(MESSAGES.membership.threeDsInProgress);

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
    message.value = MESSAGES.membership.confirmingPayment;
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
                    ? MESSAGES.membership.cardDeclined
                    : MESSAGES.membership.threeDsFailed,
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

    <header class="ark-page-header mb-6">
        <h1 class="text-h5 text-sm-h4">お支払いの確認</h1>
        <p class="text-body-2 text-medium-emphasis mt-1 mb-0">
            {{ props.plan.name }}（{{ yen(props.plan.price) }} / 月）のお申し込み
        </p>
    </header>

    <v-card class="payment-status-card text-center">
        <v-card-text class="payment-status-card__content">
            <template v-if="phase !== 'error'">
                <div class="payment-status-card__indicator mb-5" aria-hidden="true">
                    <v-progress-circular indeterminate color="primary" :size="44" :width="3" />
                </div>
                <div class="text-overline text-primary mb-2">本人認証中</div>
                <p class="text-body-1 font-weight-medium mb-0">{{ message }}</p>
                <p class="text-caption text-medium-emphasis mt-3 mb-0">
                    {{ MESSAGES.membership.waitOnThisScreen }}
                </p>
            </template>
            <template v-else>
                <div class="payment-status-card__error-icon mb-5" aria-hidden="true">
                    <v-icon icon="mdi-alert-circle-outline" color="error" size="40" />
                </div>
                <p class="text-body-1 font-weight-medium mb-5">{{ message }}</p>
                <v-btn color="primary" variant="flat" size="large" @click="router.visit('/mypage/membership')">
                    会員ページへ戻る
                </v-btn>
            </template>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.ark-page-header h1 {
    margin: 0;
}

.payment-status-card {
    overflow: hidden;
    border: 1px solid rgba(var(--v-theme-primary), 0.14);
    border-radius: var(--ark-radius-lg);
    background:
        radial-gradient(circle at 50% 0, rgba(var(--v-theme-primary), 0.07), transparent 15rem),
        rgb(var(--v-theme-surface));
}

.payment-status-card__content {
    display: flex;
    min-height: 21rem;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--ark-space-7) var(--ark-space-5);
}

.payment-status-card__indicator,
.payment-status-card__error-icon {
    display: grid;
    width: 5rem;
    height: 5rem;
    place-items: center;
    border-radius: 50%;
}

.payment-status-card__indicator {
    background: rgba(var(--v-theme-primary), 0.075);
}

.payment-status-card__error-icon {
    background: rgba(var(--v-theme-error), 0.08);
}

.payment-status-card p {
    max-width: 30rem;
    line-height: 1.8;
}

@media (max-width: 599px) {
    .payment-status-card__content {
        min-height: 19rem;
        padding: var(--ark-space-6) var(--ark-space-4);
    }
}
</style>
