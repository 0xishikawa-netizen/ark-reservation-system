<script setup lang="ts" generic="T extends string | number | null">
/**
 * 表示条件パネル用のセレクト。MonthField / DateField と同じ outlined・compact・ラベル内包に揃える。
 */
defineOptions({ inheritAttrs: false });

defineProps<{
    modelValue: T;
    label: string;
    items: { title: string; value: T }[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();
</script>

<template>
    <v-select
        v-bind="$attrs"
        :model-value="modelValue"
        :items="items"
        :label="label"
        item-title="title"
        item-value="value"
        density="compact"
        variant="outlined"
        hide-details
        menu-icon="mdi-chevron-down"
        @update:model-value="(value: T) => emit('update:modelValue', value)"
    />
</template>
