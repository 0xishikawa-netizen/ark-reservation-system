<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { EmptyState, PageHeader, SectionCard } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface NextArrival {
    id: number;
    starts_at: string;
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
    color: string;
}

interface NullableMetricCard extends Omit<MetricCard, 'value'> {
    value: number | null;
}

const props = defineProps<DashboardProps>();

const [todayYear, todayMonth, todayDay] = props.dashboardDate.split('-');
const today = `${todayYear}年${Number(todayMonth)}月${Number(todayDay)}日`;

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
            title: '今日の予約',
            value: props.today_reservation_count,
            href: `/admin/reservations?date=${props.dashboardDate}`,
            color: 'primary',
        },
        {
            title: '要対応決済',
            value: props.needs_attention_payment_count,
            href: '/admin/payments?needs_attention=1',
            color: 'error',
        },
        {
            title: '利用権 注意',
            value: membershipAttentionCount.value,
            href: '/admin/customers',
            color: 'warning',
        },
        {
            title: '回数券 警告',
            value: props.ticket_warning_count,
            href: '/admin/customers',
            color: 'warning',
        },
        {
            title: '仮予約 滞留',
            value: props.stale_pending_reservation_count,
            href: '/admin/reservations?status=pending_payment',
            color: 'error',
        },
        {
            title: '失敗ジョブ',
            value: props.failed_jobs_count,
            href: '/admin/system/failed-jobs',
            color: 'error',
        },
    ];

    return candidates.filter((metric): metric is MetricCard => metric.value !== null);
});

const formatArrival = (startsAt: string): string =>
    new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(startsAt.replace(' ', 'T')));
</script>

<template>
    <Head title="管理ダッシュボード" />

    <PageHeader title="管理ダッシュボード" :subtitle="today" />

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
                height="100%"
                :href="metric.value > 0 && metric.href ? metric.href : undefined"
                :class="{ 'metric-card-link': metric.value > 0 && metric.href }"
            >
                <div class="text-body-2 text-medium-emphasis mb-2">
                    {{ metric.title }}
                </div>
                <div class="d-flex align-end ga-2">
                    <span class="text-h4 font-weight-bold" :class="`text-${metric.color}`">
                        {{ metric.value }}
                    </span>
                    <span class="text-body-2 text-medium-emphasis mb-1">件</span>
                </div>
            </SectionCard>
        </v-col>
    </v-row>

    <SectionCard v-if="next_arrivals !== null" title="次の来店" variant="outlined">
        <v-list v-if="next_arrivals.length > 0" lines="two">
            <template v-for="(arrival, index) in next_arrivals" :key="arrival.id">
                <v-list-item>
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
                        {{ arrival.staff_name ?? '担当なし' }}
                    </v-list-item-subtitle>
                </v-list-item>
                <v-divider v-if="index < next_arrivals.length - 1" />
            </template>
        </v-list>

        <EmptyState
            v-else
            icon="mdi-calendar-clock-outline"
            title="今後の来店予定はありません"
            description="新しい来店予定が入ると、こちらに次の予約が表示されます。"
        />
    </SectionCard>
</template>

<style scoped>
.v-row {
    row-gap: var(--ark-space-2);
}

.metric-card-link {
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}

.metric-card-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgb(0 0 0 / 12%);
}

.arrival-time {
    min-width: 9rem;
}

@media (max-width: 600px) {
    .arrival-time {
        min-width: 7rem;
        font-size: 0.875rem;
    }
}
</style>
