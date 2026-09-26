<script setup lang="ts">
/**
 * Reports の KPI カード（小さめ・白背景・細い枠）。並べる時は親を .report-kpi-grid にする。
 * emphasis は画面で最も重要な数値だけに付ける（色で主張しすぎない）。
 */
withDefaults(defineProps<{ label: string; emphasis?: boolean }>(), { emphasis: false });
</script>

<template>
    <div class="report-kpi" :class="{ 'report-kpi--emphasis': emphasis }">
        <div class="report-kpi__label">{{ label }}</div>
        <div class="report-kpi__value"><slot /></div>
        <div v-if="$slots.caption" class="report-kpi__caption"><slot name="caption" /></div>
    </div>
</template>

<style>
.report-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(176px, 1fr));
    gap: var(--ark-space-3);
    margin-bottom: var(--ark-space-4);
}

.report-kpi {
    min-width: 0;
    padding: var(--ark-space-3) var(--ark-space-4);
    border: 1px solid #e3e7ee;
    border-radius: var(--ark-radius-lg);
    background: rgb(var(--v-theme-surface));
    box-shadow: var(--ark-shadow-1);
}

.report-kpi--emphasis {
    border-color: rgba(var(--v-theme-primary), 0.35);
    box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary)), var(--ark-shadow-1);
}

.report-kpi__label {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.75rem;
    font-weight: 700;
}

.report-kpi__value {
    margin-top: 2px;
    color: rgb(var(--v-theme-on-surface));
    font-size: 1.375rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.report-kpi__value small {
    margin-left: 2px;
    color: rgba(var(--v-theme-on-surface), 0.6);
    font-size: 0.75rem;
    font-weight: 600;
}

.report-kpi__caption {
    margin-top: 2px;
    color: rgba(var(--v-theme-on-surface), 0.58);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
