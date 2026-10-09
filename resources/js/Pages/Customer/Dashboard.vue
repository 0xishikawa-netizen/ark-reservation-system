<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { formatYenSign } from '@/utils/money';

defineOptions({ layout: CustomerLayout });

interface NextReservation {
    id: number;
    service_name: string;
    staff_name: string | null;
    starts_at: string;
    status: string;
    status_label: string;
}

interface MembershipSummary {
    status: string;
    status_label: string;
    current_period_end: string | null;
    available: number;
    cancel_at_period_end: boolean;
}

interface TicketSummary {
    total_available: number;
    nearest_expires_at: string | null;
    wallet_count: number;
}

interface RecentPayment {
    amount: number;
    status_label: string;
    kind_label: string;
    created_at: string;
}

const props = defineProps<{
    next_reservation: NextReservation | null;
    upcoming_count: number;
    membership: MembershipSummary | null;
    tickets: TicketSummary;
    recent_payment: RecentPayment | null;
    attention: string[];
}>();

const go = (href: string): void => {
    router.visit(href);
};
</script>

<template>
    <Head :title="MESSAGES.customerUi.dashboard.title" />

    <v-alert
        v-if="props.attention.length > 0"
        type="warning"
        variant="tonal"
        class="mb-5"
        density="comfortable"
    >
        <ul class="pl-4 mb-0">
            <li v-for="(message, index) in props.attention" :key="index">{{ message }}</li>
        </ul>
    </v-alert>

    <PageHeader
        :title="MESSAGES.customerUi.dashboard.title"
        :subtitle="MESSAGES.customerUi.dashboard.subtitle"
    />

    <SectionCard :title="MESSAGES.customerUi.dashboard.nextReservation" class="next-reservation-card mb-6">
        <template v-if="props.next_reservation">
            <div class="text-h5 font-weight-bold text-primary">
                {{ props.next_reservation.starts_at }}
            </div>
            <div class="text-body-1 mt-2">
                {{ props.next_reservation.service_name }}
                <template v-if="props.next_reservation.staff_name">
                    <span class="text-medium-emphasis">／</span>
                    {{ props.next_reservation.staff_name }}
                </template>
            </div>
            <StatusChip
                :status="props.next_reservation.status"
                :label="props.next_reservation.status_label"
                class="mt-3"
            />

            <div class="next-reservation-actions mt-5">
                <v-btn color="primary" @click="go('/reserve')">{{ MESSAGES.customerUi.dashboard.reserve }}</v-btn>
                <v-btn
                    variant="outlined"
                    color="primary"
                    @click="go(`/mypage/reservations/${props.next_reservation.id}`)"
                >
                    {{ MESSAGES.customerUi.dashboard.reservationDetail }}
                </v-btn>
                <v-btn variant="text" @click="go('/mypage/reservations')">
                    {{ fillMessage(MESSAGES.customerUi.dashboard.reservationList, { count: String(props.upcoming_count) }) }}
                </v-btn>
            </div>
        </template>
        <EmptyState
            v-else
            icon="mdi-calendar-blank-outline"
            :title="MESSAGES.customerUi.dashboard.noReservationTitle"
            :description="MESSAGES.customerUi.dashboard.noReservationDescription"
        >
            <template #action>
                <v-btn color="primary" @click="go('/reserve')">{{ MESSAGES.customerUi.dashboard.reserve }}</v-btn>
            </template>
        </EmptyState>
    </SectionCard>

    <div class="dashboard-sections">
        <SectionCard :title="MESSAGES.customerUi.dashboard.membership">
            <template #append>
                <StatusChip
                    v-if="props.membership"
                    :status="props.membership.status"
                    :label="props.membership.status_label"
                />
            </template>

            <template v-if="props.membership">
                <div class="text-h6">{{ fillMessage(MESSAGES.customerUi.dashboard.membershipRemaining, { count: String(props.membership.available) }) }}</div>
                <div
                    v-if="props.membership.current_period_end"
                    class="text-body-2 text-medium-emphasis mt-1"
                >
                    {{ fillMessage(MESSAGES.customerUi.dashboard.nextRenewal, { date: props.membership.current_period_end }) }}
                </div>
                <div
                    v-if="props.membership.cancel_at_period_end"
                    class="text-body-2 text-warning mt-1"
                >
                    {{ MESSAGES.membership.cancelScheduled }}
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-0">{{ MESSAGES.membership.notSubscribed }}</p>
            </template>
            <v-btn variant="text" color="primary" class="mt-2" @click="go('/mypage/membership')">
                {{ MESSAGES.customerUi.dashboard.toMembership }}
            </v-btn>
        </SectionCard>

        <SectionCard :title="MESSAGES.customerUi.dashboard.tickets">
            <template v-if="props.tickets.total_available > 0">
                <div class="text-h6">{{ fillMessage(MESSAGES.customerUi.dashboard.ticketsRemaining, { count: String(props.tickets.total_available) }) }}</div>
                <div
                    v-if="props.tickets.nearest_expires_at"
                    class="text-body-2 text-medium-emphasis mt-1"
                >
                    {{ fillMessage(MESSAGES.customerUi.dashboard.nearestExpiry, { date: props.tickets.nearest_expires_at }) }}
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-0">{{ MESSAGES.ticket.noneUsable }}</p>
            </template>
            <v-btn variant="text" color="primary" class="mt-2" @click="go('/mypage/tickets')">
                {{ MESSAGES.customerUi.dashboard.toTickets }}
            </v-btn>
        </SectionCard>

        <SectionCard :title="MESSAGES.customerUi.dashboard.recentPayment">
            <template v-if="props.recent_payment">
                <div class="text-h6">{{ formatYenSign(props.recent_payment.amount) }}</div>
                <div class="text-body-2 text-medium-emphasis mt-1">
                    {{ props.recent_payment.kind_label }} ／ {{ props.recent_payment.status_label }}
                </div>
                <div class="text-body-2 text-medium-emphasis">
                    {{ props.recent_payment.created_at }}
                </div>
            </template>
            <template v-else>
                <p class="text-body-2 text-medium-emphasis mb-0">{{ MESSAGES.payment.noPaymentHistory }}</p>
            </template>
            <v-btn variant="text" color="primary" class="mt-2" @click="go('/mypage/payments')">
                {{ MESSAGES.customerUi.dashboard.toPayments }}
            </v-btn>
        </SectionCard>
    </div>
</template>

<style scoped>
.next-reservation-card {
    border-top: 3px solid rgb(var(--v-theme-primary));
}

.next-reservation-actions {
    display: flex;
    flex-direction: column;
    gap: var(--ark-space-2);
}

.dashboard-sections {
    display: grid;
    gap: var(--ark-space-4);
}

@media (min-width: 600px) {
    .next-reservation-actions {
        flex-direction: row;
        align-items: center;
        flex-wrap: wrap;
    }
}
</style>
