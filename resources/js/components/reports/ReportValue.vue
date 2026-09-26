<script setup lang="ts">
import { computed } from 'vue';
import EmptyValue from '@/components/ark/EmptyValue.vue';
import { formatReportValue, type ReportValueFormat } from './format';

/**
 * Reports の数値セル。値があれば書式付きで、null / undefined なら薄いグレーの「-」で表示する。
 * emptyLabel には「算出不可」「未設定」「未実績」など値がない理由を渡す（aria-label / title に残る）。
 */
const props = withDefaults(defineProps<{
    value: number | null | undefined;
    format?: ReportValueFormat;
    emptyLabel?: string;
    /** 値があっても表示しない（未来日など）。true の時は emptyLabel の理由で「-」にする。 */
    hidden?: boolean;
}>(), {
    format: 'count',
    emptyLabel: undefined,
    hidden: false,
});

const text = computed(() => (props.hidden ? null : formatReportValue(props.value, props.format)));
</script>

<template>
    <EmptyValue v-if="text === null" :label="emptyLabel" />
    <template v-else>{{ text }}</template>
</template>
