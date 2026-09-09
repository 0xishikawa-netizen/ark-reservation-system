<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';

defineOptions({ layout: CustomerLayout });

interface RelatedReservation {
    id: number;
    service_name: string | null;
    starts_at: string | null;
}

interface PaymentRow {
    id: number;
    amount: number;
    currency: string;
    refunded_amount: number;
    status_label: string;
    kind_label: string;
    created_at: string;
    paid_at: string | null;
    reservation: RelatedReservation | null;
}

const props = defineProps<{
    payments: PaymentRow[];
}>();

const yen = (value: number): string => `¥${value.toLocaleString('ja-JP')}`;
</script>

<template>
    <Head title="支払い履歴" />

    <PageHeader
        title="支払い履歴"
        subtitle="カード決済と利用権のお支払いの記録です。"
    />

    <EmptyState
        v-if="props.payments.length === 0"
        icon="mdi-receipt-text-outline"
        title="お支払い履歴はまだありません"
        description="お支払いが完了すると、こちらで内容をご確認いただけます。"
    />

    <SectionCard
        v-for="payment in props.payments"
        :key="payment.id"
        :title="yen(payment.amount)"
        :subtitle="payment.kind_label"
        class="mb-3"
    >
        <template #append>
            <v-chip size="small" variant="tonal" color="primary">
                {{ payment.status_label }}
            </v-chip>
        </template>

        <div
            v-if="payment.refunded_amount > 0"
            class="text-body-2 text-medium-emphasis mb-2"
        >
            返金額：{{ yen(payment.refunded_amount) }}
        </div>

        <div class="text-body-2 text-medium-emphasis">
            お支払い日時：{{ payment.paid_at ?? payment.created_at }}
        </div>
        <div
            v-if="payment.reservation"
            class="text-body-2 mt-1"
        >
            対象のご予約：{{ payment.reservation.service_name }}
            <template v-if="payment.reservation.starts_at">
                （{{ payment.reservation.starts_at }}）
            </template>
            <v-btn
                size="x-small"
                variant="text"
                color="primary"
                class="ml-1"
                @click="router.visit(`/mypage/reservations/${payment.reservation.id}`)"
            >
                詳細
            </v-btn>
        </div>
    </SectionCard>
</template>
