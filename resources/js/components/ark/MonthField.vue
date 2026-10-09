<script setup lang="ts">
import { computed, ref, useAttrs } from 'vue';
import ArkMonthCalendar from './ArkMonthCalendar.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ inheritAttrs: false });

type FieldDensity = 'default' | 'comfortable' | 'compact';

const props = withDefaults(defineProps<{
    /** 幅いっぱいに広げる（ブッキングボードの左パネルなど、例外の画面だけで使う）。 */
    block?: boolean;
    modelValue: string;
    label: string;
    minYear?: number;
    maxYear?: number;
    density?: FieldDensity;
}>(), {
    minYear: 2000,
    maxYear: 2100,
    density: 'comfortable',
});

// 親から渡された class/style は外側の要素に当てる（親の scoped スタイルの幅指定が効くように）。
// それ以外の属性は実際の入力欄へ渡す。
const attrs = useAttrs();
const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const fieldAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;

    return rest;
});

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const menuOpen = ref(false);
const displayValue = computed(() => {
    const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(props.modelValue);
    return match
        ? fillMessage(MESSAGES.customerUi.calendar.yearMonth, { year: match[1], month: String(Number(match[2])) })
        : '';
});

function selectMonth(value: string): void {
    emit('update:modelValue', value);
    menuOpen.value = false;
}
</script>

<template>
    <div class="ark-field-root ark-month-field-root" :class="{ 'ark-field-root--block': block }" v-bind="rootAttrs">
        <v-menu v-model="menuOpen" :close-on-content-click="false" location="bottom start" min-width="auto" offset="6">
            <template #activator="{ props: activatorProps }">
                <div class="ark-month-field">
                    <v-text-field
                        autocomplete="off"
                        v-bind="{ ...activatorProps, ...fieldAttrs }"
                        :model-value="displayValue"
                        :label="label"
                        :density="density"
                        variant="outlined"
                        prepend-inner-icon="mdi-calendar-blank-outline"
                        hide-details
                        readonly
                    />
                </div>
            </template>
            <ArkMonthCalendar
                :model-value="modelValue"
                :min-year="minYear"
                :max-year="maxYear"
                @update:model-value="selectMonth"
            />
        </v-menu>
    </div>
</template>

<style scoped>
/* 日付・月・年の入力欄は全画面で同じ幅にそろえる（例外は block で幅いっぱい）。 */
.ark-month-field-root {
    width: var(--ark-field-month);
    max-width: 100%;
    flex: 0 0 auto;
}

.ark-field-root--block {
    width: 100%;
    flex: 1 1 auto;
}

.ark-month-field { width: 260px; max-width: 100%; }
</style>
