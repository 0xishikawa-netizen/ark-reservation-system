<script setup lang="ts">
/**
 * 月次レポートのタブ（Task 11-31）。どのタブでも同じ位置に出し、対象月・売上基準・基準日を引き継ぐ。
 * 各タブは自分のデータだけを読み込む（全タブを一括取得しない）。
 */
import { computed } from 'vue';
import { MESSAGES } from '@/constants/messages';
import { monthlyTabs, type MonthlyTabKey } from './monthlyTabs';

const props = defineProps<{ active: MonthlyTabKey; month: string; basis?: string | null; asOf?: string | null }>();
const tabs = computed(() => monthlyTabs(props.month, { basis: props.basis, asOf: props.asOf }));
</script>

<template>
    <nav class="monthly-tabs" :aria-label="MESSAGES.monthlyHub.title" data-testid="monthly-tabs">
        <span class="monthly-tabs__title">{{ MESSAGES.monthlyHub.title }}</span>
        <a
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="monthly-tabs__tab"
            :class="{ 'is-active': tab.key === active }"
            :aria-current="tab.key === active ? 'page' : undefined"
            :data-tab="tab.key"
        >{{ tab.title }}</a>
    </nav>
</template>

<style scoped>
.monthly-tabs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    margin: 0 0 16px;
    padding: 4px;
    border: 1px solid #e3e7ee;
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
}

.monthly-tabs__title {
    margin: 0 10px 0 6px;
    font-size: 0.75rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.55);
}

.monthly-tabs__tab {
    padding: 6px 12px;
    border-radius: calc(var(--ark-radius) - 2px);
    color: rgba(var(--v-theme-on-surface), 0.78);
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.monthly-tabs__tab:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}

.monthly-tabs__tab.is-active {
    background: rgb(var(--v-theme-primary));
    color: rgb(var(--v-theme-on-primary));
}
</style>
