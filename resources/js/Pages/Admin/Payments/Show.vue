<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

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

const statusLabels: Record<string, string> = {
    pending: '手続き中',
    authorized: '仮押さえ済',
    succeeded: '支払い済み',
    voided: '仮押さえ取消',
    failed: '失敗',
    partially_refunded: '一部返金済み',
    refunded: '全額返金済み',
};

const captureMethodLabels: Record<string, string> = {
    automatic: '即時請求',
    manual: '後日請求',
};

const reservationStatusLabels: Record<string, string> = {
    pending_payment: '支払い待ち',
    pending_external_sync: '外部連携待ち',
    confirmed: '予約確定',
    completed: '完了（来店・施術済み）',
    no_show: '無断キャンセル',
    canceled: 'キャンセル',
    expired: '期限切れ',
};

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
    <Head :title="`決済詳細 #${payment.id}`" />

    <v-container fluid class="ark-page py-4" style="max-width: 960px">
        <PageHeader
            :title="`決済詳細 #${payment.id}`"
            subtitle="決済内容を確認できます。"
        >
            <template #actions>
                <StatusChip
                    :status="payment.status"
                    :label="statusLabels[payment.status] ?? payment.status"
                />
                <v-btn variant="text" size="small" prepend-icon="mdi-sync" @click="syncFromStripe">
                    同期
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
                <div class="font-weight-medium">要対応</div>
                <div class="text-body-2">
                    Stripe との結果が確定していない可能性があります（{{ payment.failure_code ?? '—' }}）。
                    「同期」ボタンで現在の状態を取り込んでから対応してください。
                    <strong>{{ MESSAGES.payment.doNotRetryUnknown }}</strong>
                </div>
            </v-alert>

            <v-row>
            <v-col cols="12" md="6">
                <SectionCard title="決済情報" variant="outlined" height="100%" class="ark-table-section">
                    <v-table density="compact">
                        <tbody>
                            <tr><td>決済額</td><td class="text-right">{{ payment.amount.toLocaleString() }} 円</td></tr>
                            <tr><td>返金済額</td><td class="text-right">{{ payment.refunded_amount.toLocaleString() }} 円</td></tr>
                            <tr><td>返金可能額</td><td class="text-right">{{ payment.refundable_amount.toLocaleString() }} 円</td></tr>
                            <tr><td>請求方法</td><td class="text-right">{{ captureMethodLabels[payment.capture_method] ?? payment.capture_method }}</td></tr>
                            <tr><td>仮押さえ日時</td><td class="text-right">{{ payment.authorized_at ?? '—' }}</td></tr>
                            <tr><td>請求日時</td><td class="text-right">{{ payment.paid_at ?? '—' }}</td></tr>
                            <tr><td>取消日時</td><td class="text-right">{{ payment.voided_at ?? '—' }}</td></tr>
                            <tr><td>最終同期日時</td><td class="text-right">{{ payment.last_synced_at ?? '—' }}</td></tr>
                        </tbody>
                    </v-table>
                </SectionCard>
            </v-col>

            <v-col cols="12" md="6">
                <SectionCard title="予約情報" variant="outlined" height="100%" class="ark-table-section">
                    <v-table density="compact">
                        <tbody>
                            <tr><td>顧客</td><td class="text-right">{{ payment.customer_name ?? '—' }}</td></tr>
                            <tr>
                                <td>予約</td>
                                <td class="text-right">
                                    <span v-if="payment.reservation_id">
                                        #{{ payment.reservation_id }} / {{ payment.reservation_starts_at }}
                                    </span>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                            <tr><td>予約状況</td><td class="text-right">{{ reservationStatusLabels[payment.reservation_status ?? ''] ?? payment.reservation_status ?? '—' }}</td></tr>
                            <tr>
                                <td>決済ID<br><span class="text-caption text-medium-emphasis">照会用</span></td>
                                <td class="text-right text-caption">{{ payment.stripe_payment_intent_id ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td>請求ID<br><span class="text-caption text-medium-emphasis">返金対象</span></td>
                                <td class="text-right text-caption">{{ payment.stripe_charge_id ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </SectionCard>
            </v-col>
            </v-row>

            <SectionCard title="返金履歴" variant="outlined" class="ark-table-section">
                <template #append>
                <v-btn
                    v-if="can.refund && payment.refundable_amount > 0"
                    color="error"
                    variant="tonal"
                    size="small"
                    @click="refundDialog = true"
                >
                    返金する
                </v-btn>
                </template>
                <v-table density="compact">
                <thead>
                    <tr>
                        <th>ID</th><th class="text-right">返金額</th><th>状態</th>
                        <th>理由</th><th>担当者</th><th>日時</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="refund in payment.refunds" :key="refund.id">
                        <td>{{ refund.id }}</td>
                        <td class="text-right">{{ refund.amount.toLocaleString() }}</td>
                        <td><StatusChip :status="refund.status" :label="refund.status" /></td>
                        <td>{{ refund.reason }}</td>
                        <td>{{ refund.created_by ?? '—' }}</td>
                        <td class="text-caption">{{ refund.created_at }}</td>
                    </tr>
                    <tr v-if="payment.refunds.length === 0">
                        <td colspan="6">
                            <EmptyState
                                icon="mdi-cash-refund"
                                title="返金履歴はありません。"
                            />
                        </td>
                    </tr>
                </tbody>
                </v-table>
            </SectionCard>
        </div>

        <v-dialog v-model="refundDialog" max-width="480">
            <v-card>
                <v-card-title>返金</v-card-title>
                <v-card-text>
                    <v-alert type="warning" variant="tonal" density="compact" class="mb-4">
                        {{ MESSAGES.payment.refundIrreversible }}
                    </v-alert>
                    <v-text-field
                        v-model.number="form.amount"
                        label="返金額（円）"
                        type="number"
                        :max="payment.refundable_amount"
                        min="1"
                        :error-messages="form.errors.amount"
                        density="comfortable"
                    />
                    <v-textarea
                        v-model="form.reason"
                        label="返金理由（必須・監査に記録されます）"
                        rows="3"
                        :error-messages="form.errors.reason"
                        density="comfortable"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="refundDialog = false">キャンセル</v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="form.processing"
                        :disabled="!form.reason || form.amount < 1"
                        @click="submitRefund"
                    >
                        返金を実行
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
