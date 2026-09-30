<script setup lang="ts">
import { computed, ref } from 'vue';
import ArkCalendar from './ArkCalendar.vue';

// 親から渡された class/style は v-menu ではなく実際の入力欄(v-text-field)に当てる。
defineOptions({ inheritAttrs: false });

type FieldDensity = 'default' | 'comfortable' | 'compact';

const props = withDefaults(defineProps<{
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

    return `${y}/${String(m).padStart(2, '0')}/${String(d).padStart(2, '0')}（${weekday}）`;
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
                v-bind="{ ...activatorProps, ...$attrs }"
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
</template>
