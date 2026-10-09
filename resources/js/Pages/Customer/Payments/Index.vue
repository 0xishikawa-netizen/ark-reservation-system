<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatYenSign } from '@/utils/money';

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

</script>

<template>
    <Head :title="MESSAGES.customerUi.payments.title" />

    <PageHeader
        :title="MESSAGES.customerUi.payments.title"
        :subtitle="MESSAGES.customerUi.payments.subtitle"
    />

    <EmptyState
        v-if="props.payments.length === 0"
        icon="mdi-receipt-text-outline"
        :title="MESSAGES.customerUi.payments.emptyTitle"
        :description="MESSAGES.customerUi.payments.emptyDescription"
    />

    <SectionCard
        v-for="payment in props.payments"
        :key="payment.id"
        :title="formatYenSign(payment.amount)"
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
            {{ fillMessage(MESSAGES.customerUi.payments.refunded, { amount: formatYenSign(payment.refunded_amount) }) }}
        </div>

        <div class="text-body-2 text-medium-emphasis">
            {{ fillMessage(MESSAGES.customerUi.payments.paidAt, { date: payment.paid_at ?? payment.created_at }) }}
        </div>
        <div
            v-if="payment.reservation"
            class="text-body-2 mt-1"
        >
            {{ fillMessage(MESSAGES.customerUi.payments.reservation, { name: payment.reservation.service_name ?? '' }) }}
            <template v-if="payment.reservation.starts_at">
                {{ fillMessage(MESSAGES.customerUi.payments.reservationStartsAt, { date: payment.reservation.starts_at }) }}
            </template>
            <v-btn
                size="x-small"
                variant="text"
                color="primary"
                class="ml-1"
                @click="router.visit(`/mypage/reservations/${payment.reservation.id}`)"
            >
                {{ MESSAGES.customerUi.payments.detail }}
            </v-btn>
        </div>
    </SectionCard>
</template>
