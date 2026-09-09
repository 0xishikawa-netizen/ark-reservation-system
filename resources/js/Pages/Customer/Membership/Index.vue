<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface MembershipPlan {
    id: number;
    name: string;
    price: number;
    usage_count_per_period: number;
    billing_interval: string;
}

interface CurrentMembership {
    id: number;
    plan: MembershipPlan;
    status: string;
    status_label: string;
    current_period_start: string | null;
    current_period_end: string | null;
    next_renewal: string | null;
    cancel_at_period_end: boolean;
    available: number;
    held: number;
    total: number;
    grace_until: string | null;
}

interface MembershipHistory {
    id: number;
    type: string;
    delta: number;
    period_start: string;
    reservation_id: number | null;
    created_at: string;
}

interface PaymentMethodSummary {
    brand: string | null;
    last_four: string | null;
}

interface StripeError {
    type?: string;
}

interface StripeElement {
    mount: (selector: string) => void;
    unmount: () => void;
}

interface StripeElements {
    create: (type: string, options?: Record<string, unknown>) => StripeElement;
    submit: () => Promise<{ error?: StripeError }>;
}

interface StripeInstance {
    elements: (options: Record<string, unknown>) => StripeElements;
    createPaymentMethod: (options: {
        elements: StripeElements;
    }) => Promise<{ paymentMethod?: { id: string }; error?: StripeError }>;
}

const props = defineProps<{
    membership: CurrentMembership | null;
    history: MembershipHistory[];
    plans: MembershipPlan[];
    paymentMethod: PaymentMethodSummary | null;
    stripeKey: string;
}>();

const STRIPE_JS = 'https://js.stripe.com/v3';
const paymentDialog = ref(false);
const cancelDialog = ref(false);
const selectedPlan = ref<MembershipPlan | null>(null);
const paymentPurpose = ref<'subscribe' | 'update'>('subscribe');
const loadingPaymentElement = ref(false);
const paymentError = ref<string | null>(null);
const submittingPayment = ref(false);

let stripe: StripeInstance | null = null;
let elements: StripeElements | null = null;
let paymentElement: StripeElement | null = null;

const subscribeForm = useForm({
    membership_plan_id: null as number | null,
    payment_method_id: null as string | null,
});

const paymentMethodForm = useForm({
    payment_method_id: null as string | null,
});

const cancelForm = useForm({});
const resumeForm = useForm({});
const stripeWindow = window as unknown as {
    Stripe?: (key: string) => StripeInstance;
};
const subscribeBusinessError = computed(
    () => (subscribeForm.errors as Record<string, string>).membership,
);

const statusColor = computed(() => {
    const colors: Record<string, string> = {
        active: 'success',
        grace: 'warning',
        canceling: 'warning',
        paused: 'error',
        pending: 'info',
    };

    return props.membership ? (colors[props.membership.status] ?? 'default') : 'default';
});

const paymentMethodLabel = computed(() => {
    if (!props.paymentMethod?.last_four) return '未登録';

    const brand = props.paymentMethod.brand?.toUpperCase() || 'CARD';

    return `${brand} •••• ${props.paymentMethod.last_four}`;
});

function formatPrice(price: number): string {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY',
        maximumFractionDigits: 0,
    }).format(price);
}

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat('ja-JP', { dateStyle: 'medium' })
        .format(new Date(`${value}T00:00:00`));
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value.replace(' ', 'T')));
}

function intervalLabel(interval: string): string {
    return interval === 'month' ? '月ごと' : interval;
}

function signed(delta: number): string {
    return delta > 0 ? `+${delta}` : String(delta);
}

const loadStripeJs = (): Promise<void> => new Promise((resolve, reject) => {
    if (stripeWindow.Stripe) {
        resolve();

        return;
    }

    const existing = document.querySelector<HTMLScriptElement>(`script[src="${STRIPE_JS}"]`);
    if (existing) {
        existing.addEventListener('load', () => resolve(), { once: true });
        existing.addEventListener('error', () => reject(new Error('stripe-js-load-failed')), { once: true });

        return;
    }

    const script = document.createElement('script');
    script.src = STRIPE_JS;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('stripe-js-load-failed'));
    document.head.appendChild(script);
});

async function mountPaymentElement(): Promise<void> {
    paymentElement?.unmount();
    paymentElement = null;
    elements = null;
    stripe = null;
    loadingPaymentElement.value = true;
    paymentError.value = null;

    try {
        if (!props.stripeKey) throw new Error('stripe-key-missing');

        await loadStripeJs();
        stripe = stripeWindow.Stripe ? stripeWindow.Stripe(props.stripeKey) : null;
        if (!stripe) throw new Error('stripe-init-failed');

        // Deferred setup mode。client_secret を Inertia props に載せず PaymentMethod を収集する。
        elements = stripe.elements({
            mode: 'setup',
            currency: 'jpy',
            paymentMethodCreation: 'manual',
            appearance: { theme: 'stripe' },
        });
        paymentElement = elements.create('payment');
        paymentElement.mount('#membership-payment-element');
    } catch {
        paymentError.value = 'カード入力フォームを読み込めませんでした。時間をおいて再度お試しください。';
    } finally {
        loadingPaymentElement.value = false;
    }
}

async function openSubscribe(plan: MembershipPlan): Promise<void> {
    selectedPlan.value = plan;
    paymentPurpose.value = 'subscribe';
    subscribeForm.clearErrors();
    paymentDialog.value = true;
    await nextTick();
    await mountPaymentElement();
}

async function openPaymentUpdate(): Promise<void> {
    paymentPurpose.value = 'update';
    paymentMethodForm.clearErrors();
    paymentDialog.value = true;
    await nextTick();
    await mountPaymentElement();
}

function closePaymentDialog(): void {
    paymentElement?.unmount();
    paymentElement = null;
    elements = null;
    stripe = null;
    paymentDialog.value = false;
}

async function submitPaymentMethod(): Promise<void> {
    if (!stripe || !elements || submittingPayment.value
        || subscribeForm.processing || paymentMethodForm.processing) return;

    submittingPayment.value = true;
    paymentError.value = null;

    try {
        const submission = await elements.submit();
        if (submission.error) {
            paymentError.value = 'カード情報をご確認のうえ、もう一度お試しください。';

            return;
        }

        const result = await stripe.createPaymentMethod({ elements });
        if (result.error || !result.paymentMethod) {
            paymentError.value = result.error?.type === 'validation_error'
                ? 'カード情報をご確認のうえ、もう一度お試しください。'
                : 'カードを登録できませんでした。時間をおいて再度お試しください。';

            return;
        }

        if (paymentPurpose.value === 'subscribe' && selectedPlan.value) {
            subscribeForm.membership_plan_id = selectedPlan.value.id;
            subscribeForm.payment_method_id = result.paymentMethod.id;
            subscribeForm.post('/mypage/membership/subscribe', {
                preserveScroll: true,
                onSuccess: closePaymentDialog,
            });
        } else {
            paymentMethodForm.payment_method_id = result.paymentMethod.id;
            paymentMethodForm.put('/mypage/membership/payment-method', {
                preserveScroll: true,
                onSuccess: closePaymentDialog,
            });
        }
    } catch {
        paymentError.value = 'カードを登録できませんでした。時間をおいて再度お試しください。';
    } finally {
        submittingPayment.value = false;
    }
}

function requestCancel(): void {
    cancelForm.post('/mypage/membership/cancel', {
        preserveScroll: true,
        onSuccess: () => { cancelDialog.value = false; },
    });
}

function resume(): void {
    resumeForm.post('/mypage/membership/resume', { preserveScroll: true });
}

onBeforeUnmount(() => paymentElement?.unmount());
</script>

<template>
    <Head title="会員" />

    <div class="mb-5">
        <h1 class="text-h5 mb-2">会員</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
            月ごとの利用権とご利用状況を確認できます。
        </p>
    </div>

    <template v-if="membership === null">
        <v-alert v-if="plans.length === 0" type="info" variant="tonal">
            現在申し込める会員プランはありません。
        </v-alert>
        <div v-else class="plan-grid">
            <v-card v-for="plan in plans" :key="plan.id" variant="outlined">
                <v-card-title>{{ plan.name }}</v-card-title>
                <v-card-text>
                    <div class="text-h5 text-primary mb-2">{{ formatPrice(plan.price) }} / 月</div>
                    <div class="text-body-1">毎月 {{ plan.usage_count_per_period }} 回</div>
                    <div class="text-body-2 text-medium-emphasis">{{ intervalLabel(plan.billing_interval) }}更新</div>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-btn block color="primary" @click="openSubscribe(plan)">申し込む</v-btn>
                </v-card-actions>
            </v-card>
        </div>
        <v-alert
            v-if="subscribeBusinessError || subscribeForm.errors.membership_plan_id"
            type="error"
            variant="tonal"
            class="mt-4"
        >
            {{ subscribeBusinessError || subscribeForm.errors.membership_plan_id }}
        </v-alert>
    </template>

    <template v-else>
        <v-alert v-if="membership.status === 'grace'" type="warning" variant="tonal" class="mb-4">
            お支払いの確認中です。ご利用は継続できます（{{ formatDate(membership.grace_until) }} まで）。
        </v-alert>
        <v-alert v-else-if="membership.status === 'paused'" type="error" variant="tonal" class="mb-4">
            お支払いが確認できず一時停止中です。
        </v-alert>
        <v-alert v-else-if="membership.status === 'pending'" type="info" variant="tonal" class="mb-4">
            <div class="mb-2">お申し込みのお支払いが未完了です。</div>
            <v-btn size="small" color="primary" variant="flat" @click="router.visit('/mypage/membership/confirm')">
                お支払いを完了する
            </v-btn>
        </v-alert>

        <v-card variant="outlined" class="mb-6">
            <v-card-title class="d-flex align-center justify-space-between ga-2 flex-wrap">
                <span>{{ membership.plan.name }}</span>
                <v-chip :color="statusColor" size="small">{{ membership.status_label }}</v-chip>
            </v-card-title>
            <v-card-text>
                <div class="membership-counts mb-5">
                    <div>
                        <div class="text-caption text-medium-emphasis">利用可能</div>
                        <div class="text-h5 text-primary">{{ membership.available }}回</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">予約中</div>
                        <div class="text-h6">{{ membership.held }}回</div>
                    </div>
                    <div>
                        <div class="text-caption text-medium-emphasis">合計</div>
                        <div class="text-h6">{{ membership.total }}回</div>
                    </div>
                </div>
                <v-list lines="two" density="comfortable">
                    <v-list-item
                        title="当期"
                        :subtitle="`${formatDate(membership.current_period_start)} 〜 ${formatDate(membership.current_period_end)}`"
                    />
                    <v-list-item title="次回更新日" :subtitle="formatDate(membership.next_renewal)" />
                    <v-list-item title="月額" :subtitle="formatPrice(membership.plan.price)" />
                    <v-list-item title="支払い方法" :subtitle="paymentMethodLabel" />
                </v-list>

                <v-alert
                    v-if="membership.cancel_at_period_end"
                    type="warning"
                    variant="tonal"
                    class="mt-4"
                >
                    当期末（{{ formatDate(membership.next_renewal) }}）で終了予定です。期末までは利用できます。
                </v-alert>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0 flex-wrap ga-2">
                <v-btn variant="outlined" color="primary" @click="openPaymentUpdate">
                    支払い方法を更新
                </v-btn>
                <v-spacer />
                <v-btn
                    v-if="membership.cancel_at_period_end"
                    color="primary"
                    :loading="resumeForm.processing"
                    @click="resume"
                >
                    解約を取り消す
                </v-btn>
                <v-btn
                    v-else-if="membership.status !== 'pending'"
                    color="error"
                    variant="outlined"
                    @click="cancelDialog = true"
                >
                    次回更新で解約する
                </v-btn>
            </v-card-actions>
        </v-card>

        <section aria-labelledby="membership-history-heading">
            <h2 id="membership-history-heading" class="text-h6 mb-3">利用履歴</h2>
            <v-alert v-if="history.length === 0" type="info" variant="tonal">
                利用履歴はありません。
            </v-alert>
            <v-card v-else variant="outlined">
                <v-table class="d-none d-sm-block">
                    <thead>
                        <tr><th>日時</th><th>種別</th><th>増減</th><th>期</th><th>予約</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in history" :key="item.id">
                            <td>{{ formatDateTime(item.created_at) }}</td>
                            <td>{{ item.type }}</td>
                            <td :class="item.delta > 0 ? 'text-success' : 'text-error'">{{ signed(item.delta) }}</td>
                            <td>{{ formatDate(item.period_start) }}</td>
                            <td>{{ item.reservation_id === null ? '—' : `#${item.reservation_id}` }}</td>
                        </tr>
                    </tbody>
                </v-table>
                <v-list class="d-sm-none" lines="three">
                    <v-list-item v-for="item in history" :key="item.id">
                        <template #title>{{ item.type }} <span :class="item.delta > 0 ? 'text-success' : 'text-error'">{{ signed(item.delta) }}</span></template>
                        <template #subtitle>
                            {{ formatDateTime(item.created_at) }}<br>
                            期：{{ formatDate(item.period_start) }} / 予約：{{ item.reservation_id === null ? '—' : `#${item.reservation_id}` }}
                        </template>
                    </v-list-item>
                </v-list>
            </v-card>
        </section>
    </template>

    <v-dialog v-model="paymentDialog" max-width="600" persistent>
        <v-card :title="paymentPurpose === 'subscribe' ? `${selectedPlan?.name ?? ''}に申し込む` : '支払い方法を更新'">
            <v-card-text>
                <v-alert v-if="paymentError" type="error" variant="tonal" class="mb-4">
                    {{ paymentError }}
                </v-alert>
                <v-alert
                    v-if="subscribeForm.errors.payment_method_id || paymentMethodForm.errors.payment_method_id"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                >
                    {{ subscribeForm.errors.payment_method_id || paymentMethodForm.errors.payment_method_id }}
                </v-alert>
                <div v-if="loadingPaymentElement" class="text-center py-6">
                    <v-progress-circular indeterminate color="primary" />
                </div>
                <div id="membership-payment-element" />
                <p class="text-caption text-medium-emphasis mt-4 mb-0">
                    カード情報は Stripe が直接処理し、当店のサーバーには保存されません。
                </p>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn
                    variant="text"
                    :disabled="submittingPayment || subscribeForm.processing || paymentMethodForm.processing"
                    @click="closePaymentDialog"
                >
                    閉じる
                </v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    :loading="submittingPayment || subscribeForm.processing || paymentMethodForm.processing"
                    :disabled="loadingPaymentElement || !stripe"
                    @click="submitPaymentMethod"
                >
                    {{ paymentPurpose === 'subscribe' ? '申し込む' : '更新する' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="cancelDialog" max-width="500">
        <v-card title="次回更新で解約しますか？">
            <v-card-text>
                当期末まではご利用いただけます。次回以降の更新を停止します。
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelDialog = false">戻る</v-btn>
                <v-spacer />
                <v-btn color="error" :loading="cancelForm.processing" @click="requestCancel">
                    解約を予約する
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.plan-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));
    gap: 1rem;
}

.membership-counts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

@media (max-width: 400px) {
    .membership-counts {
        gap: 0.5rem;
    }
}
</style>
