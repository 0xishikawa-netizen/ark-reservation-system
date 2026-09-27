<script setup lang="ts">
/** 文字の値を表示する。null・空文字は数値と同じく薄いグレーの「-」（EmptyValue）にする。 */
import { EmptyValue } from '@/components/ark';

defineProps<{ value: string | null | undefined; emptyLabel?: string }>();
</script>

<template>
    <template v-if="value !== null && value !== undefined && value !== ''">{{ value }}</template>
    <EmptyValue v-else :label="emptyLabel" />
</template>
