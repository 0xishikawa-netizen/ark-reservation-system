<script setup lang="ts">
import { computed } from 'vue';
import { MESSAGES } from '@/constants/messages';

/**
 * 値なし（NULL・未設定・算出不可・未取得・未実績など）の共通表示。
 * 画面上は薄いグレーの「-」に統一し、元の意味は aria-label / title に残す。
 * 0 は値なしではないため、このコンポーネントを使わず 0 として表示すること。
 */
const props = defineProps<{
    /** 値がない理由（例: 算出不可 / 未設定 / 未実績）。読み上げとツールチップに使う。 */
    label?: string;
}>();

const reason = computed(() => props.label ?? MESSAGES.common.notApplicableLabel);
</script>

<template>
    <span class="ark-empty-value" :aria-label="reason" :title="reason">{{ MESSAGES.common.emptyValue }}</span>
</template>

<style>
.ark-empty-value {
    color: rgba(var(--v-theme-on-surface), 0.36);
    font-weight: 400;
    cursor: default;
}
</style>
