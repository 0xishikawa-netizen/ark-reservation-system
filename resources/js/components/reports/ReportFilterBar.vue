<script setup lang="ts">
import { MESSAGES } from '@/constants/messages';

/**
 * Reports 共通の表示条件パネル。
 * 入力欄は既定スロットに ReportFilterField で並べる（高さ・余白・折り返しを全画面で揃える）。
 * meta スロットには基準日などの補足、actions スロットにはボタンを置く。
 */
withDefaults(defineProps<{
    title?: string;
    loading?: boolean;
    loadingText?: string;
    error?: string | null;
}>(), {
    title: MESSAGES.reporting.customerConditions,
    loading: false,
    loadingText: MESSAGES.common.loading,
    error: null,
});
</script>

<template>
    <section class="report-filter" :aria-label="title">
        <div class="report-filter__head">
            <span class="report-filter__title">
                <v-icon icon="mdi-tune-variant" size="16" />
                {{ title }}
            </span>
            <span v-if="$slots.meta" class="report-filter__meta"><slot name="meta" /></span>
        </div>
        <div class="report-filter__body">
            <div class="report-filter__fields"><slot /></div>
            <div v-if="$slots.actions" class="report-filter__actions"><slot name="actions" /></div>
        </div>
        <div v-if="loading || error" class="report-filter__status">
            <span v-if="loading" role="status" class="report-filter__loading">
                <v-progress-circular indeterminate size="14" width="2" color="primary" />
                {{ loadingText }}
            </span>
            <span v-if="error" role="alert" class="report-filter__error">
                <v-icon icon="mdi-alert-circle-outline" size="16" />
                {{ error }}
            </span>
        </div>
    </section>
</template>

<style scoped>
.report-filter {
    margin-bottom: var(--ark-space-4);
    padding: var(--ark-space-3) var(--ark-space-4) var(--ark-space-4);
    border: 1px solid #e3e7ee;
    border-radius: var(--ark-radius-lg);
    background: rgb(var(--v-theme-surface));
    box-shadow: var(--ark-shadow-1);
}

.report-filter__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-2) var(--ark-space-4);
    margin-bottom: var(--ark-space-3);
}

.report-filter__title {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.8125rem;
    font-weight: 700;
}

.report-filter__meta {
    color: rgba(var(--v-theme-on-surface), 0.6);
    font-size: 0.8125rem;
}

.report-filter__body {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-3);
}

.report-filter__fields {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-3);
    min-width: 0;
}

.report-filter__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-2);
}

.report-filter__status {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ark-space-4);
    margin-top: var(--ark-space-3);
    font-size: 0.8125rem;
}

.report-filter__loading {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.report-filter__error {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: rgb(var(--v-theme-error));
}
</style>
