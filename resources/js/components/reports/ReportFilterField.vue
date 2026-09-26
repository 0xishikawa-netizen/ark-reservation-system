<script setup lang="ts">
/**
 * 表示条件パネル内の1項目。幅は内容に応じた3段階（sm: 年・区分 / md: 年月・日付・基準 / lg: 名前選択）に揃え、
 * 中の入力欄（MonthField / DateField / YearField / ReportSelect）はこの幅いっぱいに広げる。
 */
withDefaults(defineProps<{ size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' });
</script>

<template>
    <div class="report-filter-field" :class="`report-filter-field--${size}`">
        <slot />
    </div>
</template>

<style scoped>
.report-filter-field {
    flex: 0 1 auto;
    min-width: 0;
    max-width: 100%;
}

.report-filter-field--sm { width: 168px; }
.report-filter-field--md { width: 216px; }
.report-filter-field--lg { width: 256px; }

.report-filter-field :deep(.ark-month-field),
.report-filter-field :deep(.ark-year-field),
.report-filter-field :deep(.v-input) {
    width: 100%;
}

@media (max-width: 599px) {
    .report-filter-field { flex: 1 1 100%; width: 100%; }
}
</style>
