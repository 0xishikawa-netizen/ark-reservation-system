<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

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
    authorized: '与信済み（未確定）',
    succeeded: '支払い済み',
    voided: '与信取消',
    failed: '失敗',
    partially_refunded: '一部返金',
    refunded: '返金済み',
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
    <Head :title="`決済 #${payment.id}`" />

    <v-container fluid class="py-4" style="max-width: 960px">
        <div class="d-flex align-center mb-4">
            <h1 class="text-h6">決済 #{{ payment.id }}</h1>
            <v-chip class="ml-3" size="small" variant="flat">
                {{ statusLabels[payment.status] ?? payment.status }}
            </v-chip>
            <v-spacer />
            <v-btn variant="text" size="small" @click="syncFromStripe">
                Stripeと同期
            </v-btn>
        </div>

        <v-alert
            v-if="payment.needs_attention"
            type="warning"
            variant="tonal"
            class="mb-4"
        >
            <div class="font-weight-medium">要対応</div>
            <div class="text-body-2">
                Stripe との結果が確定していない可能性があります（{{ payment.failure_code ?? '—' }}）。
                「Stripeと同期」または <code>payments:reconcile</code> で現在状態を確認してください。
                <strong>結果が不明な状態で二重に操作しないでください。</strong>
            </div>
        </v-alert>

        <v-row>
            <v-col cols="12" md="6">
                <v-card variant="outlined" class="mb-4">
                    <v-card-title class="text-subtitle-1">決済</v-card-title>
                    <v-table density="compact">
                        <tbody>
                            <tr><td>金額</td><td class="text-right">{{ payment.amount.toLocaleString() }} 円</td></tr>
                            <tr><td>返金済み</td><td class="text-right">{{ payment.refunded_amount.toLocaleString() }} 円</td></tr>
                            <tr><td>返金可能額</td><td class="text-right">{{ payment.refundable_amount.toLocaleString() }} 円</td></tr>
                            <tr><td>capture 方式</td><td class="text-right">{{ payment.capture_method }}</td></tr>
                            <tr><td>与信日時</td><td class="text-right">{{ payment.authorized_at ?? '—' }}</td></tr>
                            <tr><td>確定日時</td><td class="text-right">{{ payment.paid_at ?? '—' }}</td></tr>
                            <tr><td>取消日時</td><td class="text-right">{{ payment.voided_at ?? '—' }}</td></tr>
                            <tr><td>最終同期</td><td class="text-right">{{ payment.last_synced_at ?? '—' }}</td></tr>
                        </tbody>
                    </v-table>
                </v-card>
            </v-col>

            <v-col cols="12" md="6">
                <v-card variant="outlined" class="mb-4">
                    <v-card-title class="text-subtitle-1">予約・顧客</v-card-title>
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
                            <tr><td>予約状態</td><td class="text-right">{{ payment.reservation_status ?? '—' }}</td></tr>
                            <tr><td>PaymentIntent</td><td class="text-right text-caption">{{ payment.stripe_payment_intent_id ?? '—' }}</td></tr>
                            <tr><td>Charge</td><td class="text-right text-caption">{{ payment.stripe_charge_id ?? '—' }}</td></tr>
                        </tbody>
                    </v-table>
                </v-card>
            </v-col>
        </v-row>

        <v-card variant="outlined">
            <v-card-title class="text-subtitle-1 d-flex align-center">
                返金履歴
                <v-spacer />
                <v-btn
                    v-if="can.refund && payment.refundable_amount > 0"
                    color="error"
                    variant="tonal"
                    size="small"
                    @click="refundDialog = true"
                >
                    返金する
                </v-btn>
            </v-card-title>
            <v-table density="compact">
                <thead>
                    <tr>
                        <th>ID</th><th class="text-right">金額</th><th>状態</th>
                        <th>理由</th><th>実行者</th><th>日時</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="refund in payment.refunds" :key="refund.id">
                        <td>{{ refund.id }}</td>
                        <td class="text-right">{{ refund.amount.toLocaleString() }}</td>
                        <td>{{ refund.status }}</td>
                        <td>{{ refund.reason }}</td>
                        <td>{{ refund.created_by ?? '—' }}</td>
                        <td class="text-caption">{{ refund.created_at }}</td>
                    </tr>
                    <tr v-if="payment.refunds.length === 0">
                        <td colspan="6" class="text-center text-medium-emphasis py-4">返金はありません。</td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <v-dialog v-model="refundDialog" max-width="480">
            <v-card>
                <v-card-title>返金</v-card-title>
                <v-card-text>
                    <v-alert type="warning" variant="tonal" density="compact" class="mb-4">
                        返金は取り消せません。実行にはパスワードの再入力が必要です。
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
