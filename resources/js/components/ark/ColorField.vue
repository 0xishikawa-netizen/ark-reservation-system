<script setup lang="ts">
import { computed, ref } from 'vue';
import { MESSAGES } from '@/constants/messages';

const props = defineProps<{
    modelValue: string;
    label: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

// 色相を十分に離した判別しやすいプリセット（すべて白背景で視認可）。
const presetColors = [
    '#1A2653', // navy（ブランド）
    '#2E7CD6', // blue
    '#17A2B8', // cyan
    '#2E9E5B', // green
    '#8FB33A', // lime
    '#E8A33D', // amber
    '#E2622E', // orange
    '#D64550', // red
    '#C2559E', // magenta
    '#7E57C2', // purple
    '#6D4C41', // brown
    '#5A6B7B', // slate
] as const;

const customMenuOpen = ref(false);

const normalizedValue = computed(() => props.modelValue.toUpperCase());
const isCustomSelected = computed(() => !presetColors.includes(
    normalizedValue.value as (typeof presetColors)[number],
));
const customSwatchColor = computed(() => (
    /^#[0-9A-F]{6}$/.test(normalizedValue.value)
        ? normalizedValue.value
        : 'rgb(var(--v-theme-surface-variant))'
));

const selectColor = (color: string): void => {
    emit('update:modelValue', color.toUpperCase());
};

const selectCustomColor = (value: unknown): void => {
    if (typeof value !== 'string') {
        return;
    }

    const hex = value.slice(0, 7).toUpperCase();

    if (/^#[0-9A-F]{6}$/.test(hex)) {
        emit('update:modelValue', hex);
    }
};
</script>

<template>
    <fieldset class="color-field">
        <legend class="color-field__label text-body-2">{{ label }}</legend>

        <div class="color-field__options">
            <button
                v-for="color in presetColors"
                :key="color"
                type="button"
                class="color-field__swatch"
                :class="{ 'color-field__swatch--selected': normalizedValue === color }"
                :style="{ backgroundColor: color }"
                :aria-label="`${label} ${color}`"
                :aria-pressed="normalizedValue === color"
                @click="selectColor(color)"
            >
                <v-icon
                    v-if="normalizedValue === color"
                    icon="mdi-check"
                    size="18"
                    aria-hidden="true"
                />
            </button>

            <v-menu
                v-model="customMenuOpen"
                :close-on-content-click="false"
                location="bottom start"
            >
                <template #activator="{ props: activatorProps }">
                    <button
                        v-bind="activatorProps"
                        type="button"
                        class="color-field__custom"
                        :class="{ 'color-field__custom--selected': isCustomSelected }"
                        :aria-pressed="isCustomSelected"
                    >
                        <span
                            class="color-field__custom-swatch"
                            :style="{ backgroundColor: customSwatchColor }"
                            aria-hidden="true"
                        >
                            <v-icon
                                v-if="isCustomSelected"
                                icon="mdi-check"
                                size="16"
                            />
                        </span>
                        <span>{{ MESSAGES.customerUi.colorField.custom }}</span>
                    </button>
                </template>

                <v-color-picker
                    :model-value="modelValue"
                    mode="hex"
                    :modes="['hex']"
                    @update:model-value="selectCustomColor"
                />
            </v-menu>
        </div>
    </fieldset>
</template>

<style scoped>
.color-field {
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.color-field__label {
    margin-bottom: var(--ark-space-2);
    color: rgb(var(--v-theme-on-surface));
}

.color-field__options {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-2);
}

.color-field__swatch,
.color-field__custom-swatch {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgba(255, 255, 255, 0.9);
    border-radius: 50%;
    color: rgba(255, 255, 255, 1);
    box-shadow: 0 0 0 1px #D9DEE5;
}

.color-field__swatch {
    width: 32px;
    height: 32px;
    padding: 0;
    cursor: pointer;
}

.color-field__swatch--selected,
.color-field__swatch:focus-visible,
.color-field__custom--selected .color-field__custom-swatch,
.color-field__custom:focus-visible .color-field__custom-swatch {
    box-shadow: 0 0 0 2px rgb(var(--v-theme-primary));
}

.color-field__swatch:focus-visible,
.color-field__custom:focus-visible {
    outline: none;
}

.color-field__custom {
    display: inline-flex;
    align-items: center;
    gap: var(--ark-space-2);
    min-height: 36px;
    padding: 0 var(--ark-space-2) 0 0;
    border: 0;
    border-radius: var(--ark-radius-lg);
    background: transparent;
    color: rgb(var(--v-theme-on-surface));
    cursor: pointer;
    font: inherit;
}

.color-field__custom-swatch {
    width: 28px;
    height: 28px;
}
</style>
