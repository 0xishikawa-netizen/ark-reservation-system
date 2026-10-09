<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { PageHeader } from '@/components/ark';
import GuestBookingLayout from '@/layouts/GuestBookingLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { loadStripeJs } from '@/composables/stripeJs';

defineOptions({ layout: GuestBookingLayout });

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
    sync_url: string;
    confirmation_url: string;
}

const props = defineProps<CheckoutProps>();

/** 残り時間の表示を更新する間隔（ミリ秒）。 */
const COUNTDOWN_INTERVAL_MS = 1000;
/** ミリ秒を秒へ換算する係数。 */
const MS_PER_SECOND = 1000;
/** 1分あたりの秒数。 */
const SECONDS_PER_MINUTE = 60;

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

const amountLabel = computed(() => fillMessage(MESSAGES.customerUi.checkout.amount, { amount: props.payment.amount.toLocaleString() }));
const expired = computed(
    () => remainingSeconds.value !== null && remainingSeconds.value <= 0,
);
const remainingLabel = computed(() => {
    if (remainingSeconds.value === null) {
        return null;
    }
    const total = Math.max(0, remainingSeconds.value);
    const minutes = Math.floor(total / SECONDS_PER_MINUTE);
    const seconds = total % SECONDS_PER_MINUTE;

    return fillMessage(MESSAGES.customerUi.checkout.remaining, { minutes: String(minutes), seconds: String(seconds).padStart(2, '0') });
});

const startCountdown = (): void => {
    if (!props.reservation.payment_expires_at) {
        return;
    }
    const deadline = new Date(props.reservation.payment_expires_at).getTime();
    const tick = (): void => {
        remainingSeconds.value = Math.floor((deadline - Date.now()) / MS_PER_SECOND);
    };
    tick();
    timer = setInterval(tick, COUNTDOWN_INTERVAL_MS);
};

onMounted(async () => {
    startCountdown();

    if (!props.stripe.client_secret) {
        errorMessage.value = MESSAGES.payment.startFailed;
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
        errorMessage.value = MESSAGES.payment.formLoadFailed;
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
        errorMessage.value =
            result.error.type === 'card_error' || result.error.type === 'validation_error'
                ? MESSAGES.payment.checkCard
                : MESSAGES.payment.completeFailed;
        submitting.value = false;

        return;
    }

    // 認証結果はサーバー側で Stripe に問い合わせて確定する（client の申告を信用しない）。
    router.post(props.sync_url, {}, {
        onFinish: () => {
            submitting.value = false;
        },
    });
};
</script>

<template>
    <Head :title="MESSAGES.customerUi.checkout.title" />

    <PageHeader :title="MESSAGES.customerUi.checkout.title" :subtitle="MESSAGES.customerUi.checkout.subtitle" />

    <v-card class="mb-4" variant="outlined">
        <v-card-text>
            <div class="d-flex justify-space-between mb-1 ga-4">
                <span class="text-medium-emphasis">{{ MESSAGES.customerUi.checkout.service }}</span>
                <span class="text-right">{{ reservation.service_name }}</span>
            </div>
            <div class="d-flex justify-space-between mb-1 ga-4">
                <span class="text-medium-emphasis">{{ MESSAGES.customerUi.checkout.dateTime }}</span>
                <span class="text-right">{{ reservation.starts_at }}</span>
            </div>
            <div class="d-flex justify-space-between ga-4">
                <span class="text-medium-emphasis">{{ MESSAGES.customerUi.checkout.amountLabel }}</span>
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
        {{ MESSAGES.customerUi.checkout.holdPrefix }} <strong>{{ remainingLabel }}</strong> {{ MESSAGES.customerUi.checkout.holdSuffix }}
    </v-alert>

    <v-alert v-if="expired" type="warning" variant="tonal" class="mb-4">
        {{ MESSAGES.payment.expired }}
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
                {{ fillMessage(MESSAGES.customerUi.checkout.pay, { amount: amountLabel }) }}
            </v-btn>
        </v-card-actions>
    </v-card>

    <p class="text-caption text-medium-emphasis mt-4">
        {{ MESSAGES.payment.stripeHandlesCardConfirm }}
    </p>

    <v-btn :href="confirmation_url" variant="text" class="mt-2">
        {{ MESSAGES.customerUi.checkout.backToReservation }}
    </v-btn>
</template>
