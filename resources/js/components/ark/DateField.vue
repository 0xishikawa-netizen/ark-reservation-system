<script setup lang="ts">
import { computed, ref, useAttrs } from 'vue';
import ArkCalendar from './ArkCalendar.vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

// 親から渡された class/style は v-menu ではなく実際の入力欄(v-text-field)に当てる。
defineOptions({ inheritAttrs: false });

type FieldDensity = 'default' | 'comfortable' | 'compact';

const props = withDefaults(defineProps<{
    /** 幅いっぱいに広げる（ブッキングボードの左パネルなど、例外の画面だけで使う）。 */
    block?: boolean;
    modelValue: string;
    label: string;
    clearable?: boolean;
    hideDetails?: boolean | 'auto';
    density?: FieldDensity;
    /** 選択可能な範囲（ISO 'YYYY-MM-DD'）。未指定なら制限なし。 */
    min?: string;
    max?: string;
}>(), {
    clearable: true,
    hideDetails: true,
    density: 'comfortable',
    min: '',
    max: '',
});

// 親から渡された class/style は外側の要素に当てる（親の scoped スタイルの幅指定が効くように）。
// それ以外の属性は実際の入力欄へ渡す。
const attrs = useAttrs();
const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const fieldAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;

    return rest;
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const menuOpen = ref(false);

const displayValue = computed(() => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(props.modelValue)) {
        return '';
    }

    const [y, m, d] = props.modelValue.split('-').map(Number);
    const date = new Date(y, m - 1, d);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const weekday = new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(date);

    return fillMessage(MESSAGES.customerUi.calendar.dateWithWeekday, { year: String(y), month: String(m).padStart(2, '0'), day: String(d).padStart(2, '0'), weekday: weekday });
});

const onSelect = (value: string): void => {
    emit('update:modelValue', value);
    menuOpen.value = false;
};

const clearValue = (): void => {
    emit('update:modelValue', '');
    menuOpen.value = false;
};
</script>

<template>
    <div class="ark-field-root ark-date-field" :class="{ 'ark-field-root--block': block }" v-bind="rootAttrs">
        <v-menu
            v-model="menuOpen"
            :close-on-content-click="false"
            location="bottom start"
            min-width="auto"
            offset="6"
        >
            <template #activator="{ props: activatorProps }">
                <v-text-field
                    autocomplete="off"
                    v-bind="{ ...activatorProps, ...fieldAttrs }"
                    :model-value="displayValue"
                    :label="label"
                    :clearable="clearable"
                    :hide-details="hideDetails"
                    :density="density"
                    variant="outlined"
                    prepend-inner-icon="mdi-calendar-blank-outline"
                    readonly
                    @click:clear.stop="clearValue"
                />
            </template>

            <ArkCalendar
                :model-value="modelValue"
                :min="min"
                :max="max"
                @update:model-value="onSelect"
            />
        </v-menu>
    </div>
</template>

<style scoped>
/* 日付・月・年の入力欄は全画面で同じ幅にそろえる（例外は block で幅いっぱい）。 */
.ark-date-field {
    width: var(--ark-field-date);
    max-width: 100%;
    flex: 0 0 auto;
}

.ark-field-root--block {
    width: 100%;
    flex: 1 1 auto;
}
</style>
