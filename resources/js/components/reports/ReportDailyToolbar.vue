<script setup lang="ts">
import { MESSAGES } from '@/constants/messages';
import ReportFilterField from './ReportFilterField.vue';
import ReportSelect from './ReportSelect.vue';
import ReportStaffChips from './ReportStaffChips.vue';

/**
 * 日別一覧の上に置く表示切替（全員 ↔ スタッフ・日付・勤務/実績ありのみ）。useDailyFilter と組で使う。
 * 画面固有の切替（並び順など）は既定スロットに置く。
 */
defineProps<{
    staff: { id: number; name: string }[];
    dateItems: { title: string; value: string | null }[];
    count: number;
}>();

const staffId = defineModel<number | null>('staffId', { required: true });
const date = defineModel<string | null>('date', { required: true });
const activeOnly = defineModel<boolean>('activeOnly', { required: true });
const labels = MESSAGES.reporting;
</script>

<template>
    <div class="report-daily-toolbar">
        <ReportStaffChips v-model="staffId" :staff="staff" />
        <div class="report-daily-toolbar__controls">
            <ReportFilterField size="md">
                <ReportSelect v-model="date" :items="dateItems" :label="labels.staffDate" data-testid="daily-date-filter" />
            </ReportFilterField>
            <slot />
            <v-switch v-model="activeOnly" :label="labels.dailyActiveOnly" color="primary" density="compact" hide-details inset data-testid="daily-active-only" />
        </div>
    </div>
    <p class="report-daily-toolbar__count">{{ count }}{{ labels.dailyRowsUnit }}</p>
</template>

<style scoped>
.report-daily-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-3);
    margin-bottom: var(--ark-space-2);
}

.report-daily-toolbar__controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-3);
}

.report-daily-toolbar__controls :deep(.v-switch) {
    flex: 0 0 auto;
}

.report-daily-toolbar__controls :deep(.v-switch .v-label) {
    font-size: 0.8125rem;
    white-space: nowrap;
}

.report-daily-toolbar__count {
    margin: 0 0 var(--ark-space-2);
    color: rgba(var(--v-theme-on-surface), 0.55);
    font-size: 0.75rem;
}
</style>
