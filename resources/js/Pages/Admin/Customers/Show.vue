<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { EmptyState, PageHeader, SectionCard, StatusChip } from '@/components/ark';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { MESSAGES } from '@/constants/messages';

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

const props = defineProps<{
    customer: CustomerProfile;
    overview: CustomerOverview;
}>();

const page = usePage();

const nextReservation = computed<RecentReservation | null>(() => {
    const now = Date.now();

    return (
        props.overview.recent_reservations
            .filter(
                // starts_at はUTCの素の日時文字列。'Z' を付けないとブラウザのローカル
                // タイムゾーンとして誤解釈され、JST環境では実時刻比較が最大9時間ズレる。
                (reservation) =>
                    new Date(`${reservation.starts_at.replace(' ', 'T')}Z`).getTime() >= now,
            )
            .sort((left, right) => left.starts_at.localeCompare(right.starts_at))[0] ?? null
    );
});

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

const formatAmount = (amount: number, currency: string): string =>
    `${amount.toLocaleString('ja-JP')} ${currency.toUpperCase()}`;

// starts_at はタイムゾーンなしの素の日時文字列（DBの値をそのまま整形）のため、
// Date を経由せず文字列操作だけで組み立てる（他画面の timeLabel と同じ方針）。
function formatShortDateTime(value: string): string {
    const [datePart, timePart] = value.split(' ');
    const [, month, day] = datePart.split('-');

    return `${Number(month)}/${Number(day)} ${timePart.slice(0, 5)}`;
}
</script>

<template>
    <Head :title="`${customer.name}の顧客情報`" />

    <PageHeader title="顧客詳細" :subtitle="customer.name">
        <template #actions>
            <v-btn
                v-if="page.props.auth.can.customersManage"
                color="primary"
                variant="flat"
                prepend-icon="mdi-pencil-outline"
                :href="`/admin/customers/${customer.user_id}/edit`"
            >
                編集
            </v-btn>
        </template>
    </PageHeader>

    <SectionCard title="基本情報" class="basic-information-card">
        <template #append>
            <v-btn variant="text" href="/admin/customers">一覧へ戻る</v-btn>
        </template>

        <dl class="customer-profile-grid">
            <div class="customer-profile-item">
                <dt>氏名</dt>
                <dd>{{ customer.name }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>カナ</dt>
                <dd>{{ customer.kana }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>電話番号</dt>
                <dd>{{ display(customer.phone) }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>生年月日</dt>
                <dd>{{ display(customer.birthday) }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>性別</dt>
                <dd>{{ genderLabel(customer.gender) }}</dd>
            </div>
            <div class="customer-profile-item customer-profile-item--wide">
                <dt>メールアドレス</dt>
                <dd>{{ customer.email }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>メール認証</dt>
                <dd>{{ customer.email_verified ? '認証済み' : '未認証' }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>登録経路</dt>
                <dd>{{ createdViaLabel(customer.created_via) }}</dd>
            </div>
            <div class="customer-profile-item">
                <dt>登録日</dt>
                <dd>{{ display(customer.created_at) }}</dd>
            </div>
            <div class="customer-profile-item customer-profile-item--wide">
                <dt>メモ</dt>
                <dd class="customer-note">{{ display(customer.note) }}</dd>
            </div>
        </dl>
    </SectionCard>

    <div class="overview-grid" :class="{ 'overview-grid--single': !nextReservation }">
        <SectionCard
            v-if="nextReservation"
            title="次回の予約"
            class="next-reservation-card"
        >
            <div class="next-reservation-row">
                <div class="next-reservation-body">
                    <div class="next-reservation-date text-subtitle-1 font-weight-bold text-primary">
                        {{ formatShortDateTime(nextReservation.starts_at) }}
                    </div>
                    <div class="text-body-2 mt-1">{{ nextReservation.service_name }}</div>
                    <div class="text-caption text-medium-emphasis">
                        担当: {{ nextReservation.staff_name ?? '未割当' }}
                    </div>
                    <StatusChip
                        :status="nextReservation.status"
                        :label="nextReservation.status_label"
                        class="mt-2"
                    />
                </div>
                <v-btn
                    variant="tonal"
                    color="primary"
                    size="small"
                    prepend-icon="mdi-calendar-month-outline"
                    class="next-reservation-link"
                    :href="`/admin/schedule?date=${nextReservation.starts_at.slice(0, 10)}&reservation=${nextReservation.id}`"
                >
                    予約を見る
                </v-btn>
            </div>
        </SectionCard>

        <div class="overview-secondary">
            <SectionCard title="月額プラン" class="summary-card">
                <template #append>
                    <StatusChip
                        v-if="overview.membership !== null"
                        :status="overview.membership.status"
                        :label="overview.membership.status_label"
                    />
                </template>

                <template v-if="overview.membership !== null">
                    <div class="d-flex align-center flex-wrap ga-2">
                        <span class="text-h6">{{ overview.membership.plan.name }}</span>
                        <v-chip
                            v-if="overview.membership.cancel_at_period_end"
                            size="small"
                            color="warning"
                            variant="outlined"
                        >
                            期間末で終了
                        </v-chip>
                    </div>
                    <div class="summary-metrics mt-3">
                        <div>
                            <span class="summary-metrics__label">当期残</span>
                            <strong>{{ overview.membership.available }}</strong>
                        </div>
                        <div>
                            <span class="summary-metrics__label">当期終了</span>
                            <strong>
                                {{ display(overview.membership.current_period_end) }}
                            </strong>
                        </div>
                    </div>
                    <v-btn
                        variant="tonal"
                        color="primary"
                        prepend-icon="mdi-card-account-details-outline"
                        class="summary-link"
                        :href="`/admin/customers/${customer.user_id}/membership`"
                    >
                        月額プラン
                    </v-btn>
                </template>
                <p v-else class="text-body-2 text-medium-emphasis mb-0">
                    加入なし
                </p>
            </SectionCard>

            <SectionCard title="回数券" class="summary-card">
                <div class="ticket-summary">
                    <div>
                        <span class="ticket-summary__value">
                            {{ overview.tickets.active_wallet_count }}
                        </span>
                        <span class="text-body-2 text-medium-emphasis">冊</span>
                    </div>
                    <v-divider vertical />
                    <div>
                        <span class="text-body-2 text-medium-emphasis">利用可能</span>
                        <span class="ticket-summary__value ml-2">
                            {{ overview.tickets.total_available }}
                        </span>
                    </div>
                </div>
                <v-btn
                    v-if="overview.tickets.active_wallet_count > 0"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-ticket-confirmation-outline"
                    class="summary-link"
                    :href="`/admin/customers/${customer.user_id}/tickets`"
                >
                    回数券
                </v-btn>
                <p v-else class="text-body-2 text-medium-emphasis mb-0 mt-2">
                    保有なし
                </p>
            </SectionCard>
        </div>
    </div>

    <SectionCard
        title="予約履歴"
        :subtitle="`予約 ${overview.reservation_totals.total} 件 / 今後 ${overview.reservation_totals.upcoming} 件`"
        class="history-card"
    >
        <template #append>
            <v-btn variant="text" :href="`/admin/reservations?customer_id=${customer.user_id}`">予約一覧へ</v-btn>
        </template>

        <v-list v-if="overview.recent_reservations.length > 0" lines="two" class="history-list">
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
                        <StatusChip
                            :status="reservation.status"
                            :label="reservation.status_label"
                        />
                    </template>
                </v-list-item>
                <v-divider v-if="index < overview.recent_reservations.length - 1" />
            </template>
        </v-list>
        <EmptyState
            v-else
            icon="mdi-calendar-blank-outline"
            title="予約履歴はありません。"
        />
    </SectionCard>

    <SectionCard
        title="支払い履歴"
        :subtitle="overview.payments === null
            ? undefined
            : `${overview.payments.total_count} 件 / 要対応 ${overview.payments.needs_attention_count} 件`"
        class="history-card"
    >
        <template v-if="overview.payments !== null" #append>
            <v-btn variant="text" :href="`/admin/payments?customer_id=${customer.user_id}`">支払い一覧へ</v-btn>
        </template>

        <p v-if="overview.payments === null" class="text-body-2 text-medium-emphasis mb-0">
            {{ MESSAGES.customer.paymentViewForbidden }}
        </p>
        <v-list
            v-else-if="overview.payments.recent.length > 0"
            lines="two"
            class="history-list"
        >
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
                            <StatusChip
                                :status="payment.status"
                                :label="payment.status_label"
                            />
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
        <EmptyState
            v-else
            icon="mdi-credit-card-outline"
            title="支払い履歴はありません。"
        />
    </SectionCard>
</template>

<style scoped>
.basic-information-card,
.overview-grid,
.history-card {
    margin-bottom: var(--ark-space-5);
}

.customer-profile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin: 0;
    gap: 0 var(--ark-space-5);
}

.customer-profile-item {
    min-width: 0;
    padding: var(--ark-space-3) 0;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.customer-profile-item dt {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    line-height: 1.4;
}

.customer-profile-item dd {
    margin: var(--ark-space-1) 0 0;
    font-size: 0.9375rem;
    font-weight: 500;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.customer-note {
    white-space: pre-wrap;
}

.overview-grid,
.overview-secondary {
    display: grid;
    gap: var(--ark-space-4);
}

.next-reservation-card {
    border-top: 3px solid rgb(var(--v-theme-primary));
}

.next-reservation-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-4);
    flex-wrap: wrap;
}

.next-reservation-body {
    min-width: 0;
}

.next-reservation-link {
    flex: 0 0 auto;
}

.summary-card {
    height: 100%;
}

.summary-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--ark-space-3);
}

.summary-metrics > div {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.summary-metrics__label {
    color: rgb(var(--v-theme-on-surface-variant));
    font-size: 0.75rem;
}

.summary-link {
    margin-top: var(--ark-space-3);
}

.ticket-summary {
    display: flex;
    align-items: center;
    gap: var(--ark-space-4);
}

.ticket-summary__value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.4;
}

.history-list {
    margin: calc(var(--ark-space-2) * -1) calc(var(--ark-space-4) * -1);
    background: transparent;
}

@media (min-width: 960px) {
    .customer-profile-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .customer-profile-item--wide {
        grid-column: span 2;
    }

    .overview-grid {
        grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
    }

    .overview-grid--single {
        grid-template-columns: minmax(0, 1fr);
    }

    .overview-grid--single .overview-secondary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 599px) {
    .customer-profile-grid,
    .summary-metrics {
        grid-template-columns: minmax(0, 1fr);
    }

    .history-list :deep(.v-list-item) {
        align-items: flex-start;
    }
}
</style>
