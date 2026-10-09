<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, EmptyValue, PageHeader, SectionCard, StatusChip, MoneyField } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

interface RefundRow {
    id: number;
    amount: number;
    status: string;
    reason: string;
    created_by: string | null;
    created_at: string | null;
    stripe_refund_id: string | null;
}

interface PaymentDetail {
    id: number;
    reservation_id: number | null;
    reservation_starts_at: string | null;
    reservation_status: string | null;
    customer_name: string | null;
    amount: number;
    currency: string;
    status: string;
    refunded_amount: number;
    needs_attention: boolean;
    created_at: string | null;
    stripe_payment_intent_id: string | null;
    stripe_charge_id: string | null;
    authorized_at: string | null;
    paid_at: string | null;
    voided_at: string | null;
    last_synced_at: string | null;
    failure_code: string | null;
    failure_message: string | null;
    capture_method: string;
    refundable_amount: number;
    refunds: RefundRow[];
}

const props = defineProps<{
    payment: PaymentDetail;
    can: { refund: boolean };
}>();

const M = MESSAGES.reportsUi.paymentShow;
const statusLabels = M.statusLabels;
const captureMethodLabels = M.captureMethodLabels;
const reservationStatusLabels = M.reservationStatusLabels;

const refundDialog = ref(false);

const form = useForm({
    amount: props.payment.refundable_amount,
    reason: '',
});

const submitRefund = (): void => {
    form.post(`/admin/payments/${props.payment.id}/refund`, {
        preserveScroll: true,
        onSuccess: () => {
            refundDialog.value = false;
            form.reset('reason');
        },
    });
};

const syncFromStripe = (): void => {
    router.post(`/admin/payments/${props.payment.id}/sync`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="fillMessage(M.title, { id: String(payment.id) })" />

    <v-container fluid class="ark-page py-4" style="max-width: 960px">
        <PageHeader
            :title="fillMessage(M.title, { id: String(payment.id) })"
            :subtitle="M.subtitle"
        >
            <template #actions>
                <StatusChip
                    :status="payment.status"
                    :label="statusLabels[payment.status] ?? payment.status"
                />
                <v-btn variant="text" size="small" prepend-icon="mdi-sync" @click="syncFromStripe">
                    {{ M.sync }}
                </v-btn>
            </template>
        </PageHeader>

        <div class="ark-page__sections">
            <v-alert
                v-if="payment.needs_attention"
                type="warning"
                variant="tonal"
                class="mb-4"
            >
                <div class="font-weight-medium">{{ M.needsAttention }}</div>
                <div class="text-body-2">
                    {{ fillMessage(M.needsAttentionBody, { code: payment.failure_code ?? MESSAGES.common.notRecorded }) }}
                    <strong>{{ MESSAGES.payment.doNotRetryUnknown }}</strong>
                </div>
            </v-alert>

            <v-row>
            <v-col cols="12" md="6">
                <SectionCard :title="M.paymentInfo" variant="outlined" height="100%" class="ark-table-section">
                    <v-table density="compact">
                        <tbody>
                            <tr><td>{{ M.amount }}</td><td class="text-right">{{ fillMessage(M.yen, { amount: payment.amount.toLocaleString() }) }}</td></tr>
                            <tr><td>{{ M.refundedAmount }}</td><td class="text-right">{{ fillMessage(M.yen, { amount: payment.refunded_amount.toLocaleString() }) }}</td></tr>
                            <tr><td>{{ M.refundableAmount }}</td><td class="text-right">{{ fillMessage(M.yen, { amount: payment.refundable_amount.toLocaleString() }) }}</td></tr>
                            <tr><td>{{ M.captureMethod }}</td><td class="text-right">{{ captureMethodLabels[payment.capture_method] ?? payment.capture_method }}</td></tr>
                            <tr><td>{{ M.authorizedAt }}</td><td class="text-right">{{ payment.authorized_at ?? MESSAGES.common.notRecorded }}</td></tr>
                            <tr><td>{{ M.paidAt }}</td><td class="text-right">{{ payment.paid_at ?? MESSAGES.common.notRecorded }}</td></tr>
                            <tr><td>{{ M.voidedAt }}</td><td class="text-right">{{ payment.voided_at ?? MESSAGES.common.notRecorded }}</td></tr>
                            <tr><td>{{ M.lastSyncedAt }}</td><td class="text-right">{{ payment.last_synced_at ?? MESSAGES.common.notSynced }}</td></tr>
                        </tbody>
                    </v-table>
                </SectionCard>
            </v-col>

            <v-col cols="12" md="6">
                <SectionCard :title="M.reservationInfo" variant="outlined" height="100%" class="ark-table-section">
                    <v-table density="compact">
                        <tbody>
                            <tr><td>{{ M.customer }}</td><td class="text-right"><template v-if="payment.customer_name">{{ payment.customer_name }}</template><EmptyValue v-else :label="MESSAGES.common.notEntered" /></td></tr>
                            <tr>
                                <td>{{ M.reservation }}</td>
                                <td class="text-right">
                                    <span v-if="payment.reservation_id">
                                        #{{ payment.reservation_id }} / {{ payment.reservation_starts_at }}
                                    </span>
                                    <EmptyValue v-else :label="MESSAGES.common.notLinked" />
                                </td>
                            </tr>
                            <tr><td>{{ M.reservationStatus }}</td><td class="text-right">{{ reservationStatusLabels[payment.reservation_status ?? ''] ?? payment.reservation_status ?? MESSAGES.common.notLinked }}</td></tr>
                            <tr>
                                <td>{{ M.paymentId }}<br><span class="text-caption text-medium-emphasis">{{ M.forInquiry }}</span></td>
                                <td class="text-right text-caption">{{ payment.stripe_payment_intent_id ?? MESSAGES.common.notRecorded }}</td>
                            </tr>
                            <tr>
                                <td>{{ M.chargeId }}<br><span class="text-caption text-medium-emphasis">{{ M.refundTarget }}</span></td>
                                <td class="text-right text-caption">{{ payment.stripe_charge_id ?? MESSAGES.common.notRecorded }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </SectionCard>
            </v-col>
            </v-row>

            <SectionCard :title="M.refundHistory" variant="outlined" class="ark-table-section">
                <template #append>
                <v-btn
                    v-if="can.refund && payment.refundable_amount > 0"
                    color="error"
                    variant="tonal"
                    size="small"
                    @click="refundDialog = true"
                >
                    {{ M.refundAction }}
                </v-btn>
                </template>
                <v-table density="compact">
                <thead>
                    <tr>
                        <th>ID</th><th class="text-right">{{ M.refundAmount }}</th><th>{{ M.status }}</th>
                        <th>{{ M.reason }}</th><th>{{ M.operator }}</th><th>{{ M.datetime }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="refund in payment.refunds" :key="refund.id">
                        <td>{{ refund.id }}</td>
                        <td class="text-right">{{ refund.amount.toLocaleString() }}</td>
                        <td><StatusChip :status="refund.status" :label="refund.status" /></td>
                        <td>{{ refund.reason }}</td>
                        <td>{{ refund.created_by ?? MESSAGES.common.notRecorded }}</td>
                        <td class="text-caption">{{ refund.created_at }}</td>
                    </tr>
                    <tr v-if="payment.refunds.length === 0">
                        <td colspan="6">
                            <EmptyState
                                icon="mdi-cash-refund"
                                :title="M.noRefunds"
                            />
                        </td>
                    </tr>
                </tbody>
                </v-table>
            </SectionCard>
        </div>

        <v-dialog v-model="refundDialog" max-width="480">
            <v-card>
                <v-card-title>{{ M.refundDialogTitle }}</v-card-title>
                <v-card-text>
                    <v-alert type="warning" variant="tonal" density="compact" class="mb-4">
                        {{ MESSAGES.payment.refundIrreversible }}
                    </v-alert>
                    <MoneyField
                        v-model="form.amount"
                        :label="M.refundAmount"
                        :max="payment.refundable_amount"
                        :error-messages="form.errors.amount"
                    />
                    <v-textarea
                        v-model="form.reason"
                        :label="M.refundReason"
                        rows="3"
                        :error-messages="form.errors.reason"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="refundDialog = false">{{ M.cancel }}</v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="form.processing"
                        :disabled="!form.reason || form.amount < 1"
                        @click="submitRefund"
                    >
                        {{ M.executeRefund }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<style scoped>
.ark-page__sections {
    display: grid;
    gap: var(--ark-space-4);
}

.ark-page__sections > * {
    margin-block: 0 !important;
}

.ark-table-section :deep(.v-card-text) {
    padding: 0;
}
</style>
