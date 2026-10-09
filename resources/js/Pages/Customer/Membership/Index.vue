<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatDateOnly, formatDateTime } from '@/utils/dateFormat';
import { formatYenCurrency } from '@/utils/money';
import { signed } from '@/utils/numberFormat';
import { loadStripeJsReusingScript } from '@/composables/stripeJs';

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
    if (!props.paymentMethod?.last_four) return MESSAGES.customerUi.membership.notRegistered;

    const brand = props.paymentMethod.brand?.toUpperCase() || 'CARD';

    return `${brand} •••• ${props.paymentMethod.last_four}`;
});

function formatDate(value: string | null): string {
    if (!value) return MESSAGES.common.notSet;

    return formatDateOnly(value, 'dateMedium');
}

function intervalLabel(interval: string): string {
    return interval === 'month' ? MESSAGES.customerUi.membership.monthly : interval;
}

function reservationRef(reservationId: number | null): string {
    return reservationId === null ? MESSAGES.common.notLinked : `#${reservationId}`;
}

async function mountPaymentElement(): Promise<void> {
    paymentElement?.unmount();
    paymentElement = null;
    elements = null;
    stripe = null;
    loadingPaymentElement.value = true;
    paymentError.value = null;

    try {
        if (!props.stripeKey) throw new Error('stripe-key-missing');

        await loadStripeJsReusingScript();
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
        paymentError.value = MESSAGES.membership.cardFormLoadFailed;
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
            paymentError.value = MESSAGES.payment.checkCard;

            return;
        }

        const result = await stripe.createPaymentMethod({ elements });
        if (result.error || !result.paymentMethod) {
            paymentError.value = result.error?.type === 'validation_error'
                ? MESSAGES.payment.checkCard
                : MESSAGES.membership.cardRegisterFailed;

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
        paymentError.value = MESSAGES.membership.cardRegisterFailed;
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
    <Head :title="MESSAGES.customerUi.membership.title" />

    <header class="ark-page-header mb-6">
        <h1 class="text-h5 text-sm-h4">{{ MESSAGES.customerUi.membership.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1 mb-0">
            {{ MESSAGES.customerUi.membership.subtitle }}
        </p>
    </header>

    <template v-if="membership === null">
        <v-alert v-if="plans.length === 0" type="info" variant="tonal" class="membership-alert">
            {{ MESSAGES.membership.nonePlans }}
        </v-alert>
        <div v-else class="plan-grid">
            <v-card v-for="plan in plans" :key="plan.id" class="plan-card" variant="outlined">
                <v-card-item class="plan-card__header">
                    <div class="text-overline text-primary mb-1">{{ MESSAGES.customerUi.membership.planLabel }}</div>
                    <v-card-title class="pa-0 text-h6">{{ plan.name }}</v-card-title>
                </v-card-item>
                <v-card-text class="plan-card__body">
                    <div class="plan-card__usage">
                        <span class="text-body-1">{{ MESSAGES.customerUi.membership.everyMonth }}</span>
                        <strong>{{ plan.usage_count_per_period }}</strong>
                        <span class="text-body-1">{{ MESSAGES.customerUi.membership.timesUnit }}</span>
                    </div>
                    <div class="plan-card__price">
                        <span>{{ formatYenCurrency(plan.price) }}</span>
                        <small>{{ MESSAGES.customerUi.membership.perMonth }}</small>
                    </div>
                    <div class="plan-card__per-visit text-body-2 text-medium-emphasis">
                        {{ MESSAGES.customerUi.membership.perVisitPrefix }}
                        {{ plan.usage_count_per_period > 0
                            ? formatYenCurrency(Math.round(plan.price / plan.usage_count_per_period))
                            : MESSAGES.common.notCalculated }}
                    </div>
                    <v-divider class="my-4" />
                    <div class="text-body-2 text-medium-emphasis">
                        {{ fillMessage(MESSAGES.customerUi.membership.renewal, { interval: intervalLabel(plan.billing_interval) }) }}
                    </div>
                </v-card-text>
                <v-card-actions class="plan-card__actions">
                    <v-btn block color="primary" size="large" @click="openSubscribe(plan)">{{ MESSAGES.customerUi.membership.subscribe }}</v-btn>
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
        <v-alert v-if="membership.status === 'grace'" type="warning" variant="tonal" class="membership-alert mb-4">
            {{ fillMessage(MESSAGES.customerUi.membership.graceNotice, { date: formatDate(membership.grace_until) }) }}
        </v-alert>
        <v-alert v-else-if="membership.status === 'paused'" type="error" variant="tonal" class="membership-alert mb-4">
            {{ MESSAGES.membership.suspended }}
        </v-alert>
        <v-alert v-else-if="membership.status === 'pending'" type="info" variant="tonal" class="membership-alert mb-4">
            <div class="mb-2">{{ MESSAGES.membership.unpaidApplication }}</div>
            <v-btn size="small" color="primary" variant="flat" @click="router.visit('/mypage/membership/confirm')">
                {{ MESSAGES.customerUi.membership.completePayment }}
            </v-btn>
        </v-alert>

        <v-card class="membership-summary mb-6">
            <v-card-item class="membership-summary__header">
                <div class="d-flex align-center justify-space-between ga-3 flex-wrap">
                    <div>
                        <div class="text-caption text-medium-emphasis mb-1">{{ MESSAGES.customerUi.membership.currentPlan }}</div>
                        <v-card-title class="pa-0 text-h6">{{ membership.plan.name }}</v-card-title>
                    </div>
                    <v-chip :color="statusColor" size="small" variant="tonal">
                        {{ membership.status_label }}
                    </v-chip>
                </div>
            </v-card-item>
            <v-divider />
            <v-card-text class="membership-summary__body">
                <div class="membership-counts">
                    <div class="membership-count membership-count--available">
                        <div class="text-caption text-medium-emphasis">{{ MESSAGES.customerUi.membership.available }}</div>
                        <div class="membership-count__value text-primary">{{ membership.available }}<small>{{ MESSAGES.customerUi.membership.timesUnit }}</small></div>
                    </div>
                    <div class="membership-count">
                        <div class="text-caption text-medium-emphasis">{{ MESSAGES.customerUi.membership.held }}</div>
                        <div class="membership-count__value">{{ membership.held }}<small>{{ MESSAGES.customerUi.membership.timesUnit }}</small></div>
                    </div>
                    <div class="membership-count">
                        <div class="text-caption text-medium-emphasis">{{ MESSAGES.customerUi.membership.total }}</div>
                        <div class="membership-count__value">{{ membership.total }}<small>{{ MESSAGES.customerUi.membership.timesUnit }}</small></div>
                    </div>
                </div>
                <v-list lines="two" density="comfortable" class="membership-details">
                    <v-list-item
                        :title="MESSAGES.customerUi.membership.currentPeriod"
                        :subtitle="fillMessage(MESSAGES.customerUi.membership.periodRange, { start: formatDate(membership.current_period_start), end: formatDate(membership.current_period_end) })"
                    />
                    <v-list-item :title="MESSAGES.customerUi.membership.nextRenewal" :subtitle="formatDate(membership.next_renewal)" />
                    <v-list-item :title="MESSAGES.customerUi.membership.monthlyPrice" :subtitle="formatYenCurrency(membership.plan.price)" />
                    <v-list-item :title="MESSAGES.customerUi.membership.paymentMethod" :subtitle="paymentMethodLabel" />
                </v-list>

                <v-alert
                    v-if="membership.cancel_at_period_end"
                    type="warning"
                    variant="tonal"
                    class="membership-alert mt-4"
                >
                    {{ fillMessage(MESSAGES.customerUi.membership.endsAtPeriodEnd, { date: formatDate(membership.next_renewal) }) }}
                </v-alert>
            </v-card-text>
            <v-divider />
            <v-card-actions class="membership-actions flex-wrap ga-2">
                <v-btn variant="outlined" color="primary" @click="openPaymentUpdate">
                    {{ MESSAGES.customerUi.membership.updatePaymentMethod }}
                </v-btn>
                <v-spacer />
                <v-btn
                    v-if="membership.cancel_at_period_end"
                    color="primary"
                    :loading="resumeForm.processing"
                    @click="resume"
                >
                    {{ MESSAGES.customerUi.membership.resume }}
                </v-btn>
                <v-btn
                    v-else-if="membership.status !== 'pending'"
                    color="error"
                    variant="outlined"
                    @click="cancelDialog = true"
                >
                    {{ MESSAGES.customerUi.membership.cancelAtRenewal }}
                </v-btn>
            </v-card-actions>
        </v-card>

        <section aria-labelledby="membership-history-heading">
            <v-card class="history-card">
                <v-card-item class="history-card__header">
                    <v-card-title id="membership-history-heading" class="pa-0 text-h6">{{ MESSAGES.customerUi.membership.history }}</v-card-title>
                </v-card-item>
                <v-divider />
                <v-card-text v-if="history.length === 0" class="pa-4 pa-sm-5">
                    <v-alert type="info" variant="tonal" class="membership-alert">
                        {{ MESSAGES.membership.noUsageHistory }}
                    </v-alert>
                </v-card-text>
                <template v-else>
                    <v-table class="history-table d-none d-sm-block">
                        <thead>
                            <tr><th>{{ MESSAGES.customerUi.membership.colDateTime }}</th><th>{{ MESSAGES.customerUi.membership.colType }}</th><th>{{ MESSAGES.customerUi.membership.colDelta }}</th><th>{{ MESSAGES.customerUi.membership.colPeriod }}</th><th>{{ MESSAGES.customerUi.membership.colReservation }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in history" :key="item.id">
                                <td>{{ formatDateTime(item.created_at, 'short') }}</td>
                                <td>{{ item.type }}</td>
                                <td :class="item.delta > 0 ? 'text-success' : 'text-error'">{{ signed(item.delta) }}</td>
                                <td>{{ formatDate(item.period_start) }}</td>
                                <td>{{ reservationRef(item.reservation_id) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-list class="history-list d-sm-none" lines="three">
                        <v-list-item v-for="item in history" :key="item.id">
                            <template #title>
                                <span class="font-weight-medium">{{ item.type }}</span>
                                <span :class="item.delta > 0 ? 'text-success' : 'text-error'">{{ signed(item.delta) }}</span>
                            </template>
                            <template #subtitle>
                                {{ formatDateTime(item.created_at, 'short') }}<br>
                                {{ fillMessage(MESSAGES.customerUi.membership.historyMeta, { period: formatDate(item.period_start), reservation: reservationRef(item.reservation_id) }) }}
                            </template>
                        </v-list-item>
                    </v-list>
                </template>
            </v-card>
        </section>
    </template>

    <v-dialog v-model="paymentDialog" max-width="600" persistent>
        <v-card
            class="membership-dialog"
            :title="paymentPurpose === 'subscribe' ? fillMessage(MESSAGES.customerUi.membership.subscribeTo, { name: selectedPlan?.name ?? '' }) : MESSAGES.customerUi.membership.updatePaymentMethod"
        >
            <v-card-text class="membership-dialog__body">
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
                    {{ MESSAGES.payment.stripeHandlesCardShort }}
                </p>
            </v-card-text>
            <v-card-actions class="membership-dialog__actions">
                <v-btn
                    variant="text"
                    :disabled="submittingPayment || subscribeForm.processing || paymentMethodForm.processing"
                    @click="closePaymentDialog"
                >
                    {{ MESSAGES.customerUi.membership.close }}
                </v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    :loading="submittingPayment || subscribeForm.processing || paymentMethodForm.processing"
                    :disabled="loadingPaymentElement || !stripe"
                    @click="submitPaymentMethod"
                >
                    {{ paymentPurpose === 'subscribe' ? MESSAGES.customerUi.membership.subscribe : MESSAGES.customerUi.membership.update }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="cancelDialog" max-width="500">
        <v-card class="membership-dialog" :title="MESSAGES.customerUi.membership.cancelTitle">
            <v-card-text class="membership-dialog__body">
                {{ MESSAGES.membership.cancelAtPeriodEndHint }}
            </v-card-text>
            <v-card-actions class="membership-dialog__actions">
                <v-btn variant="text" @click="cancelDialog = false">{{ MESSAGES.customerUi.membership.back }}</v-btn>
                <v-spacer />
                <v-btn color="error" :loading="cancelForm.processing" @click="requestCancel">
                    {{ MESSAGES.customerUi.membership.cancelSubmit }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.ark-page-header h1 {
    margin: 0;
}

.membership-alert {
    border-radius: var(--ark-radius);
}

.plan-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr));
    gap: var(--ark-space-4);
}

.plan-card {
    display: flex;
    overflow: hidden;
    flex-direction: column;
    border-color: rgba(var(--v-theme-primary), 0.2);
    border-radius: var(--ark-radius-lg);
    background:
        linear-gradient(180deg, rgba(var(--v-theme-primary), 0.055), transparent 9rem),
        rgb(var(--v-theme-surface));
}

.plan-card__header {
    padding: var(--ark-space-5) var(--ark-space-5) var(--ark-space-3);
}

.plan-card__body {
    padding: var(--ark-space-3) var(--ark-space-5) var(--ark-space-4);
}

.plan-card__usage {
    display: flex;
    align-items: baseline;
    gap: var(--ark-space-2);
    color: rgb(var(--v-theme-on-surface));
}

.plan-card__usage strong {
    color: rgb(var(--v-theme-primary));
    font-size: clamp(2.5rem, 8vw, 3.25rem);
    font-weight: 700;
    letter-spacing: -0.04em;
    line-height: 1.1;
}

.plan-card__price {
    display: flex;
    align-items: baseline;
    gap: var(--ark-space-1);
    margin-top: var(--ark-space-4);
}

.plan-card__price span {
    font-size: 1.5rem;
    font-weight: 700;
}

.plan-card__price small {
    color: rgb(var(--v-theme-on-surface-variant));
    font-size: 0.875rem;
}

.plan-card__per-visit {
    margin-top: var(--ark-space-1);
}

.plan-card__actions {
    margin-top: auto;
    padding: 0 var(--ark-space-5) var(--ark-space-5);
}

.membership-summary,
.history-card {
    overflow: hidden;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.09);
    border-radius: var(--ark-radius-lg);
}

.membership-summary__header,
.history-card__header {
    padding: var(--ark-space-5);
}

.membership-summary__body {
    padding: var(--ark-space-5);
}

.membership-counts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--ark-space-3);
    margin-bottom: var(--ark-space-5);
}

.membership-count {
    min-width: 0;
    padding: var(--ark-space-4);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
    border-radius: var(--ark-radius);
    background: rgba(var(--v-theme-on-surface), 0.025);
}

.membership-count--available {
    border-color: rgba(var(--v-theme-primary), 0.18);
    background: rgba(var(--v-theme-primary), 0.065);
}

.membership-count__value {
    margin-top: var(--ark-space-1);
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.2;
}

.membership-count__value small {
    margin-left: 0.15em;
    font-size: 0.8rem;
    font-weight: 500;
}

.membership-details {
    overflow: hidden;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.07);
    border-radius: var(--ark-radius);
}

.membership-details :deep(.v-list-item:not(:last-child)) {
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}

.membership-actions,
.membership-dialog__actions {
    padding: var(--ark-space-4) var(--ark-space-5);
}

.history-table :deep(th) {
    color: rgb(var(--v-theme-on-surface-variant));
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.04em;
}

.history-table :deep(td) {
    white-space: nowrap;
}

.history-list :deep(.v-list-item-title) {
    display: flex;
    justify-content: space-between;
    gap: var(--ark-space-3);
}

.history-list :deep(.v-list-item:not(:last-child)) {
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.07);
}

.membership-dialog {
    border-radius: var(--ark-radius-lg);
}

.membership-dialog__body {
    padding: var(--ark-space-4) var(--ark-space-5) var(--ark-space-5);
}

@media (max-width: 400px) {
    .membership-counts {
        gap: var(--ark-space-2);
    }

    .membership-count {
        padding: var(--ark-space-3) var(--ark-space-2);
    }

    .membership-count__value {
        font-size: 1.5rem;
    }
}

@media (max-width: 599px) {
    .plan-card__header,
    .membership-summary__header,
    .history-card__header {
        padding: var(--ark-space-4);
    }

    .plan-card__body,
    .membership-summary__body {
        padding-right: var(--ark-space-4);
        padding-left: var(--ark-space-4);
    }

    .plan-card__actions {
        padding: 0 var(--ark-space-4) var(--ark-space-4);
    }

    .membership-actions,
    .membership-dialog__actions {
        padding: var(--ark-space-4);
    }

    .membership-actions .v-btn:first-child {
        width: 100%;
    }

    .membership-dialog__body {
        padding: var(--ark-space-3) var(--ark-space-4) var(--ark-space-4);
    }
}
</style>
