<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
import CustomerPeekDrawer from '@/components/admin/CustomerPeekDrawer.vue';
import { MESSAGES } from '@/constants/messages';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

interface NextArrival {
    id: number;
    starts_at: string;
    customer_id: number;
    customer_name: string;
    service_name: string;
    staff_name: string | null;
}

interface MembershipAttention {
    grace: number;
    paused: number;
    canceling: number;
}

interface DashboardProps {
    today_reservation_count: number | null;
    next_arrivals: NextArrival[] | null;
    needs_attention_payment_count: number | null;
    stale_pending_reservation_count: number | null;
    membership_attention: MembershipAttention | null;
    ticket_warning_count: number | null;
    failed_jobs_count: number | null;
    dashboardDate: string;
    failedJobsCount: number;
}

interface MetricCard {
    title: string;
    value: number;
    href: string | null;
    color: 'primary' | 'warning' | 'error';
}

interface NullableMetricCard extends Omit<MetricCard, 'value'> {
    value: number | null;
}

const props = defineProps<DashboardProps>();

const M = MESSAGES.reportsUi.dashboard;

const [todayYear, todayMonth, todayDay] = props.dashboardDate.split('-');
const today = fillMessage(M.todayDate, { year: String(todayYear), month: String(Number(todayMonth)), day: String(Number(todayDay)) });

const membershipAttentionCount = computed<number | null>(() => {
    if (props.membership_attention === null) {
        return null;
    }

    return (
        props.membership_attention.grace +
        props.membership_attention.paused +
        props.membership_attention.canceling
    );
});

const metrics = computed<MetricCard[]>(() => {
    const candidates: NullableMetricCard[] = [
        {
            title: M.todayReservations,
            value: props.today_reservation_count,
            href: `/admin/reservations?date=${props.dashboardDate}`,
            color: 'primary',
        },
        {
            title: M.attentionPayments,
            value: props.needs_attention_payment_count,
            href: '/admin/payments?needs_attention=1',
            color: 'error',
        },
        {
            title: M.membershipAttention,
            value: membershipAttentionCount.value,
            href: '/admin/customers',
            color: 'warning',
        },
        {
            title: M.ticketWarning,
            value: props.ticket_warning_count,
            href: '/admin/customers',
            color: 'warning',
        },
        {
            title: M.stalePending,
            value: props.stale_pending_reservation_count,
            href: '/admin/reservations?status=pending_payment',
            color: 'error',
        },
        {
            title: M.failedJobs,
            value: props.failed_jobs_count,
            href: '/admin/system/failed-jobs',
            color: 'error',
        },
    ];

    return candidates.filter((metric): metric is MetricCard => metric.value !== null);
});

const formatArrival = (startsAt: string): string =>
    new Intl.DateTimeFormat('ja-JP', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(startsAt.replace(' ', 'T')));

const peekOpen = ref(false);
const peekCustomerId = ref<number | null>(null);

function openCustomerPeek(customerId: number): void {
    peekCustomerId.value = customerId;
    peekOpen.value = true;
}
</script>

<template>
    <Head :title="M.title" />

    <PageHeader :title="M.title" :subtitle="today" />

    <v-row v-if="metrics.length > 0" class="mb-2">
        <v-col
            v-for="metric in metrics"
            :key="metric.title"
            cols="12"
            sm="6"
            lg="4"
            xl="2"
        >
            <SectionCard
                variant="outlined"
                color="surface"
                rounded="lg"
                height="100%"
                :href="metric.value > 0 && metric.href ? metric.href : undefined"
                :class="[
                    'ark-kpi-card',
                    { 'metric-card-link': metric.value > 0 && metric.href },
                ]"
                :style="{
                    borderTopColor: `rgb(var(--v-theme-${metric.color}))`,
                }"
            >
                <div class="ark-kpi-card__title text-body-2 text-medium-emphasis mb-2">
                    {{ metric.title }}
                </div>
                <div class="d-flex align-end ga-2">
                    <span class="text-h4 font-weight-bold" :class="`text-${metric.color}`">
                        {{ metric.value }}
                    </span>
                    <span class="text-body-2 text-medium-emphasis mb-1">{{ M.countUnit }}</span>
                </div>
            </SectionCard>
        </v-col>
    </v-row>

    <SectionCard
        v-if="next_arrivals !== null"
        :title="M.arrivalsTitle"
        :subtitle="M.arrivalsSubtitle"
        variant="outlined"
    >
        <v-list v-if="next_arrivals.length > 0" lines="two">
            <template v-for="(arrival, index) in next_arrivals" :key="arrival.id">
                <v-list-item
                    link
                    class="arrival-row"
                    @click="openCustomerPeek(arrival.customer_id)"
                >
                    <template #prepend>
                        <div class="arrival-time text-primary font-weight-medium mr-5">
                            {{ formatArrival(arrival.starts_at) }}
                        </div>
                    </template>
                    <v-list-item-title>
                        {{ arrival.customer_name }}
                    </v-list-item-title>
                    <v-list-item-subtitle>
                        {{ arrival.service_name }}
                        <span class="mx-1">・</span>
                        {{ arrival.staff_name ?? MESSAGES.monthlyHub.unknownStaff }}
                    </v-list-item-subtitle>
                    <template #append>
                        <v-icon icon="mdi-chevron-right" class="text-medium-emphasis" />
                    </template>
                </v-list-item>
                <v-divider v-if="index < next_arrivals.length - 1" />
            </template>
        </v-list>

        <EmptyState
            v-else
            icon="mdi-calendar-clock-outline"
            :title="M.arrivalsEmptyTitle"
            :description="M.arrivalsEmptyDescription"
        />
    </SectionCard>

    <CustomerPeekDrawer v-model="peekOpen" :customer-id="peekCustomerId" />
</template>

<style scoped>
.v-row {
    row-gap: var(--ark-space-2);
}

.ark-kpi-card {
    border: 1px solid #D9DEE5;
    border-top-width: 3px;
    border-top-style: solid;
    border-radius: var(--ark-radius-lg);
    box-shadow: var(--ark-shadow-1);
}

.ark-kpi-card__title {
    letter-spacing: 0.02em;
}

.metric-card-link {
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}

.metric-card-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgb(18 25 60 / 12%);
}

.arrival-time {
    min-width: 3.5rem;
}

@media (max-width: 600px) {
    .arrival-time {
        min-width: 3rem;
        font-size: 0.875rem;
    }
}

</style>
