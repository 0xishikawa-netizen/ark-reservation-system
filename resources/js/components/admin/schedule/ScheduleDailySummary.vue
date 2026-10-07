<script setup lang="ts">
import { computed } from 'vue';
import type { DailySummary } from '@/components/admin/schedule/types';
import { MESSAGES } from '@/constants/messages';

/**
 * 予約台帳の下に出す、その日の集計（§23-24, §48）。巨大なKPIカードは並べず、
 * 「予約・来店」「コース別」「売上」の3段にまとめる。売上の段は権限がある時だけ出す。
 */
const props = defineProps<{
    summary: DailySummary;
    /** 見出し（例：本日の集計／10/5(日)の集計）。 */
    title: string;
}>();

const yen = (value: number): string => `¥${value.toLocaleString('ja-JP')}`;

const futureRate = computed(() => {
    const rate = props.summary.future_reservation.rate;

    return rate === null ? MESSAGES.common.emptyValue : `${Math.round(rate * 100)}%`;
});

const visitItems = computed(() => [
    { label: MESSAGES.schedule.dailySummary.reservation, value: String(props.summary.total) },
    { label: MESSAGES.schedule.dailySummary.upcoming, value: String(props.summary.upcoming) },
    { label: MESSAGES.schedule.dailySummary.completed, value: String(props.summary.completed) },
    { label: MESSAGES.schedule.dailySummary.accountingPending, value: String(props.summary.accounting_pending), warn: props.summary.accounting_pending > 0 },
    { label: MESSAGES.schedule.dailySummary.newCustomer, value: String(props.summary.new_customers) },
    { label: MESSAGES.schedule.dailySummary.repeatCustomer, value: String(props.summary.repeat_customers) },
    { label: MESSAGES.schedule.dailySummary.futureReservation, value: `${props.summary.future_reservation.count}（${futureRate.value}）` },
    { label: MESSAGES.schedule.dailySummary.nominated, value: String(props.summary.nominated) },
    { label: MESSAGES.schedule.dailySummary.online, value: String(props.summary.online) },
    { label: MESSAGES.schedule.dailySummary.canceled, value: String(props.summary.canceled) },
    { label: MESSAGES.schedule.dailySummary.noShow, value: String(props.summary.no_show) },
]);

const showMoney = computed(() => props.summary.revenue !== null);
</script>

<template>
    <v-card variant="outlined" class="daily-summary mt-2" data-testid="daily-summary">
        <div class="daily-summary__title">{{ title }}</div>

        <!-- 予約・来店 -->
        <div class="daily-summary__row">
            <div
                v-for="item in visitItems"
                :key="item.label"
                class="daily-summary__item"
                :class="{ 'daily-summary__item--warn': item.warn }"
            >
                <span class="daily-summary__value">{{ item.value }}</span>
                <span class="daily-summary__label">{{ item.label }}</span>
            </div>
        </div>

        <!-- コース別の来店数 -->
        <div v-if="summary.categories.length > 0" class="daily-summary__row daily-summary__row--sub">
            <span class="daily-summary__section">{{ MESSAGES.schedule.dailySummary.byCourse }}</span>
            <span v-for="category in summary.categories" :key="category.code ?? 'unknown'" class="daily-summary__chip">
                {{ category.name ?? MESSAGES.schedule.dailySummary.uncategorized }}<strong>{{ category.count }}</strong>
            </span>
        </div>

        <!-- 売上（権限がある時だけ） -->
        <div v-if="showMoney" class="daily-summary__row daily-summary__row--sub">
            <span class="daily-summary__section">{{ MESSAGES.schedule.dailySummary.revenue }}</span>
            <span class="daily-summary__money daily-summary__money--total">{{ yen(summary.revenue ?? 0) }}</span>
            <span v-if="summary.average_spend !== null" class="daily-summary__chip">{{ MESSAGES.schedule.dailySummary.averageSpend }}<strong>{{ yen(summary.average_spend) }}</strong></span>
            <span class="daily-summary__chip">{{ MESSAGES.schedule.dailySummary.treatment }}<strong>{{ yen(summary.treatment_revenue ?? 0) }}</strong></span>
            <span class="daily-summary__chip">{{ MESSAGES.schedule.dailySummary.retail }}<strong>{{ yen(summary.retail_revenue ?? 0) }}</strong></span>
            <span v-for="method in summary.payment_methods" :key="method.name" class="daily-summary__chip">
                {{ method.name }}<strong>{{ yen(method.amount) }}</strong>
            </span>
        </div>
    </v-card>
</template>

<style scoped>
.daily-summary {
    padding: var(--ark-space-3) var(--ark-space-4);
}

.daily-summary__title {
    margin-bottom: var(--ark-space-2);
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: rgb(var(--v-theme-primary));
}

.daily-summary__row {
    display: flex;
    align-items: stretch;
    flex-wrap: wrap;
    gap: var(--ark-space-3) var(--ark-space-5);
}

.daily-summary__row--sub {
    align-items: center;
    gap: var(--ark-space-2);
    margin-top: var(--ark-space-2);
    padding-top: var(--ark-space-2);
    border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.daily-summary__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    flex: 0 0 auto;
}

.daily-summary__value {
    font-size: 1.0625rem;
    font-weight: 700;
    line-height: 1.1;
    color: rgb(var(--v-theme-on-surface));
    font-variant-numeric: tabular-nums;
}

.daily-summary__label {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
    white-space: nowrap;
}

/* 会計待ちが残っている時は目立たせる（締め忘れ防止）。 */
.daily-summary__item--warn .daily-summary__value {
    color: rgb(var(--v-theme-warning));
}

.daily-summary__section {
    margin-right: var(--ark-space-1);
    font-size: 0.6875rem;
    font-weight: 800;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.daily-summary__chip {
    display: inline-flex;
    align-items: baseline;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(var(--v-theme-on-surface), 0.05);
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.8);
    white-space: nowrap;
}

.daily-summary__chip strong {
    font-size: 0.8125rem;
    color: rgb(var(--v-theme-on-surface));
    font-variant-numeric: tabular-nums;
}

.daily-summary__money--total {
    margin-right: var(--ark-space-1);
    font-size: 1.0625rem;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    font-variant-numeric: tabular-nums;
}
</style>
