<script setup lang="ts">
import { computed, ref } from 'vue';
import ArkMonthCalendar from './ArkMonthCalendar.vue';

defineOptions({ inheritAttrs: false });

type FieldDensity = 'default' | 'comfortable' | 'compact';

const props = withDefaults(defineProps<{
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

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const menuOpen = ref(false);
const displayValue = computed(() => {
    const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(props.modelValue);
    return match ? `${match[1]}年${Number(match[2])}月` : '';
});

function selectMonth(value: string): void {
    emit('update:modelValue', value);
    menuOpen.value = false;
}
</script>

<template>
    <v-menu v-model="menuOpen" :close-on-content-click="false" location="bottom start" min-width="auto" offset="6">
        <template #activator="{ props: activatorProps }">
            <div class="ark-month-field">
                <v-text-field
                    v-bind="{ ...activatorProps, ...$attrs }"
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
</template>

<style scoped>
.ark-month-field { width: 260px; max-width: 100%; }
</style>
