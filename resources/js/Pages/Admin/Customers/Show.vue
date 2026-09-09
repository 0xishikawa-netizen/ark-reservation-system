<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface CustomerProfile {
    user_id: number;
    name: string;
    kana: string;
    phone: string | null;
    birthday: string | null;
    gender: string | null;
    note: string | null;
    email: string;
    email_verified: boolean;
    created_via: string;
    created_at: string | null;
}

interface RecentReservation {
    id: number;
    starts_at: string;
    service_name: string;
    staff_name: string | null;
    status: string;
    status_label: string;
}

interface RecentPayment {
    id: number;
    amount: number;
    currency: string;
    status: string;
    status_label: string;
    kind_label: string;
    needs_attention: boolean;
    created_at: string;
}

interface MembershipPlanSummary {
    name: string;
    price: number;
    usage_count_per_period: number;
    billing_interval: string;
    is_active: boolean;
}

interface MembershipSummary {
    plan: MembershipPlanSummary;
    status: string;
    status_label: string;
    current_period_start: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    available: number;
    grace_until?: string | null;
}

interface CustomerOverview {
    recent_reservations: RecentReservation[];
    reservation_totals: {
        total: number;
        upcoming: number;
    };
    // reservations.view 権限がない閲覧者には null（支払い一覧と同じ権限境界）。
    payments: {
        recent: RecentPayment[];
        total_count: number;
        needs_attention_count: number;
    } | null;
    tickets: {
        active_wallet_count: number;
        total_available: number;
    };
    membership: MembershipSummary | null;
}

defineProps<{
    customer: CustomerProfile;
    overview: CustomerOverview;
}>();

const page = usePage();

const display = (value: string | null): string => value || '—';

const genderLabel = (value: string | null): string => {
    const labels: Record<string, string> = {
        male: '男性',
        female: '女性',
        other: 'その他',
    };

    return value ? (labels[value] ?? value) : '—';
};

const createdViaLabel = (value: string): string => {
    const labels: Record<string, string> = {
        web: 'Web',
        admin: '管理画面',
        migration: '移行',
    };

    return labels[value] ?? value;
};

const reservationStatusColors: Record<string, string> = {
    pending_payment: 'orange',
    pending_external_sync: 'info',
    confirmed: 'primary',
    completed: 'success',
    no_show: 'warning',
    canceled: 'grey',
    expired: 'grey-darken-1',
};

const paymentStatusColors: Record<string, string> = {
    pending: 'grey',
    authorized: 'orange',
    succeeded: 'green',
    voided: 'blue-grey',
    failed: 'red',
    partially_refunded: 'amber',
    refunded: 'purple',
};

const statusColor = (colors: Record<string, string>, status: string): string =>
    colors[status] ?? 'grey';

const formatAmount = (amount: number, currency: string): string =>
    `${amount.toLocaleString('ja-JP')} ${currency.toUpperCase()}`;
</script>

<template>
    <Head :title="`${customer.name}の顧客情報`" />

    <div class="d-flex align-center justify-space-between mb-6 flex-wrap ga-3">
        <h1 class="text-h4">顧客詳細</h1>
        <div class="d-flex ga-3">
            <v-btn
                variant="tonal"
                :href="`/admin/customers/${customer.user_id}/tickets`"
            >
                回数券
            </v-btn>
            <v-btn
                variant="tonal"
                :href="`/admin/customers/${customer.user_id}/membership`"
            >
                会員
            </v-btn>
            <v-btn
                v-if="page.props.auth.can.customersManage"
                color="primary"
                :href="`/admin/customers/${customer.user_id}/edit`"
            >
                編集
            </v-btn>
        </div>
    </div>

    <v-card max-width="840" title="基本情報">
        <v-list lines="two">
            <v-list-item title="氏名" :subtitle="customer.name" />
            <v-list-item title="カナ" :subtitle="customer.kana" />
            <v-list-item title="電話番号" :subtitle="display(customer.phone)" />
            <v-list-item title="生年月日" :subtitle="display(customer.birthday)" />
            <v-list-item title="性別" :subtitle="genderLabel(customer.gender)" />
            <v-list-item title="メールアドレス" :subtitle="customer.email" />
            <v-list-item
                title="メール認証"
                :subtitle="customer.email_verified ? '認証済み' : '未認証'"
            />
            <v-list-item
                title="登録経路"
                :subtitle="createdViaLabel(customer.created_via)"
            />
            <v-list-item title="登録日" :subtitle="display(customer.created_at)" />
            <v-list-item title="メモ" :subtitle="display(customer.note)" />
        </v-list>
        <v-card-actions>
            <v-btn variant="text" href="/admin/customers">一覧へ戻る</v-btn>
        </v-card-actions>
    </v-card>

    <v-row class="mt-4">
        <v-col cols="12" lg="6">
            <v-card title="直近の予約" height="100%">
                <v-card-subtitle>
                    予約 {{ overview.reservation_totals.total }} 件 / 今後
                    {{ overview.reservation_totals.upcoming }} 件
                </v-card-subtitle>
                <v-list v-if="overview.recent_reservations.length > 0" lines="two">
                    <template
                        v-for="(reservation, index) in overview.recent_reservations"
                        :key="reservation.id"
                    >
                        <v-list-item>
                            <template #title>
                                {{ reservation.starts_at }} ・ {{ reservation.service_name }}
                            </template>
                            <template #subtitle>
                                担当: {{ reservation.staff_name ?? '未割当' }}
                            </template>
                            <template #append>
                                <v-chip
                                    :color="statusColor(reservationStatusColors, reservation.status)"
                                    size="small"
                                    variant="tonal"
                                >
                                    {{ reservation.status_label }}
                                </v-chip>
                            </template>
                        </v-list-item>
                        <v-divider
                            v-if="index < overview.recent_reservations.length - 1"
                        />
                    </template>
                </v-list>
                <v-card-text v-else class="text-medium-emphasis">
                    予約履歴はありません。
                </v-card-text>
                <v-card-actions>
                    <v-btn variant="text" href="/admin/reservations">予約一覧へ</v-btn>
                </v-card-actions>
            </v-card>
        </v-col>

        <v-col cols="12" lg="6">
            <v-card title="支払い" height="100%">
                <v-card-text v-if="overview.payments === null" class="text-medium-emphasis">
                    支払い情報の閲覧権限がありません。
                </v-card-text>
                <template v-else>
                <v-card-subtitle>
                    {{ overview.payments.total_count }} 件 / 要対応
                    {{ overview.payments.needs_attention_count }} 件
                </v-card-subtitle>
                <v-list v-if="overview.payments.recent.length > 0" lines="two">
                    <template
                        v-for="(payment, index) in overview.payments.recent"
                        :key="payment.id"
                    >
                        <v-list-item>
                            <template #title>
                                {{ payment.created_at }} ・ {{ payment.kind_label }}
                            </template>
                            <template #subtitle>
                                {{ formatAmount(payment.amount, payment.currency) }}
                            </template>
                            <template #append>
                                <div class="d-flex flex-column align-end ga-1">
                                    <v-chip
                                        :color="statusColor(paymentStatusColors, payment.status)"
                                        size="small"
                                        variant="tonal"
                                    >
                                        {{ payment.status_label }}
                                    </v-chip>
                                    <v-chip
                                        v-if="payment.needs_attention"
                                        color="red"
                                        size="x-small"
                                        variant="outlined"
                                    >
                                        要対応
                                    </v-chip>
                                </div>
                            </template>
                        </v-list-item>
                        <v-divider v-if="index < overview.payments.recent.length - 1" />
                    </template>
                </v-list>
                <v-card-text v-else class="text-medium-emphasis">
                    支払い履歴はありません。
                </v-card-text>
                <v-card-actions>
                    <v-btn variant="text" href="/admin/payments">支払い一覧へ</v-btn>
                </v-card-actions>
                </template>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card title="回数券" height="100%">
                <v-card-text class="text-h6">
                    {{ overview.tickets.active_wallet_count }} 冊 / 利用可能
                    {{ overview.tickets.total_available }}
                </v-card-text>
                <v-card-actions>
                    <v-btn
                        variant="text"
                        :href="`/admin/customers/${customer.user_id}/tickets`"
                    >
                        回数券を確認
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-col>

        <v-col cols="12" md="6">
            <v-card title="会員" height="100%">
                <template v-if="overview.membership !== null">
                    <v-card-text>
                        <div class="d-flex align-center ga-2 mb-3">
                            <span class="text-h6">{{ overview.membership.plan.name }}</span>
                            <v-chip size="small" color="primary" variant="tonal">
                                {{ overview.membership.status_label }}
                            </v-chip>
                            <v-chip
                                v-if="overview.membership.cancel_at_period_end"
                                size="small"
                                color="warning"
                                variant="outlined"
                            >
                                期間末で終了
                            </v-chip>
                        </div>
                        <div>当期残: {{ overview.membership.available }}</div>
                        <div class="text-medium-emphasis mt-1">
                            当期終了: {{ display(overview.membership.current_period_end) }}
                        </div>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            variant="text"
                            :href="`/admin/customers/${customer.user_id}/membership`"
                        >
                            会員情報を確認
                        </v-btn>
                    </v-card-actions>
                </template>
                <v-card-text v-else class="text-medium-emphasis">
                    会員登録なし
                </v-card-text>
            </v-card>
        </v-col>
    </v-row>
</template>
