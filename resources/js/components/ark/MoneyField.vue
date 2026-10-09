<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatMoney, toDigits } from '@/utils/money';
import { MESSAGES } from '@/constants/messages';

/**
 * 金額の入力欄。数字だけを受け付け、フォーカスが外れている間は 3 桁ごとにカンマを付けて表示する。
 * 値は数値（未入力は null）で親へ返す。親から渡された属性（label・error-messages・readonly 等）は
 * そのまま v-text-field へ渡す。
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    modelValue: number | null | undefined;
    /** 末尾に出す単位。 */
    suffix?: string;
}>(), {
    suffix: MESSAGES.customerUi.format.yenUnit,
});

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const focused = ref(false);

// 入力中はカンマなしの数字、フォーカスが外れたらカンマ付きで表示する。
const display = computed(() => (focused.value
    ? (props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue))
    : formatMoney(props.modelValue)));

function onInput(raw: string | null): void {
    const digits = toDigits(raw ?? '');
    emit('update:modelValue', digits === '' ? null : Number(digits));
}

function onKeydown(event: KeyboardEvent): void {
    // 数字・編集・移動キー以外（e, +, -, . など）は入力させない。
    if (event.ctrlKey || event.metaKey || event.altKey || event.key.length > 1) {
        return;
    }
    if (!/^[0-9０-９]$/.test(event.key)) {
        event.preventDefault();
    }
}
</script>

<template>
    <v-text-field
        v-bind="$attrs"
        :model-value="display"
        :suffix="suffix"
        inputmode="numeric"
        autocomplete="off"
        @update:model-value="onInput"
        @keydown="onKeydown"
        @focus="focused = true"
        @blur="focused = false"
    />
</template>
