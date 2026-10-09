<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

const props = withDefaults(defineProps<{
    modelValue: string;
    minYear?: number;
    maxYear?: number;
}>(), {
    minYear: 2000,
    maxYear: 2100,
});

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const labels = MESSAGES.calendar;
const formats = MESSAGES.customerUi.calendar;

/** 1年の月数。 */
const MONTHS_PER_YEAR = 12;

const currentParts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Tokyo', year: 'numeric', month: 'numeric',
}).formatToParts(new Date());
const currentYear = Number(currentParts.find((part) => part.type === 'year')?.value);
const currentMonth = Number(currentParts.find((part) => part.type === 'month')?.value);
const currentKey = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;

function parseMonth(value: string): { year: number; month: number } | null {
    const match = /^(\d{4})-(\d{2})$/.exec(value);
    if (!match) return null;
    const year = Number(match[1]);
    const month = Number(match[2]);
    return year >= props.minYear && year <= props.maxYear && month >= 1 && month <= MONTHS_PER_YEAR
        ? { year, month } : null;
}

const viewYear = ref(parseMonth(props.modelValue)?.year ?? currentYear);
watch(() => props.modelValue, (value) => {
    const parsed = parseMonth(value);
    if (parsed) viewYear.value = parsed.year;
});

const months = computed(() => Array.from({ length: MONTHS_PER_YEAR }, (_, index) => {
    const month = index + 1;
    const key = `${viewYear.value}-${String(month).padStart(2, '0')}`;
    return { month, key, selected: key === props.modelValue, current: key === currentKey };
}));

function shiftYear(delta: number): void {
    const next = viewYear.value + delta;
    if (next >= props.minYear && next <= props.maxYear) viewYear.value = next;
}

function selectMonth(key: string): void {
    emit('update:modelValue', key);
}

function goToCurrentMonth(): void {
    if (currentYear < props.minYear || currentYear > props.maxYear) return;
    viewYear.value = currentYear;
    emit('update:modelValue', currentKey);
}
</script>

<template>
    <div class="ark-month-cal" role="group" :aria-label="labels.selectMonth">
        <header class="ark-month-cal__head">
            <span class="ark-month-cal__year" aria-live="polite">{{ fillMessage(formats.year, { year: String(viewYear) }) }}</span>
            <div class="ark-month-cal__nav">
                <button type="button" :aria-label="labels.previousYear" :disabled="viewYear <= minYear" @click="shiftYear(-1)">
                    <v-icon icon="mdi-chevron-left" size="20" />
                </button>
                <button type="button" :aria-label="labels.nextYear" :disabled="viewYear >= maxYear" @click="shiftYear(1)">
                    <v-icon icon="mdi-chevron-right" size="20" />
                </button>
            </div>
        </header>

        <div class="ark-month-cal__grid">
            <button
                v-for="item in months"
                :key="item.key"
                type="button"
                class="ark-month-cal__month"
                :class="{ 'is-selected': item.selected, 'is-current': item.current && !item.selected }"
                :aria-label="fillMessage(formats.yearMonth, { year: String(viewYear), month: String(item.month) })"
                :aria-pressed="item.selected"
                :aria-current="item.current ? 'true' : undefined"
                :data-testid="`calendar-month-${item.key}`"
                @click="selectMonth(item.key)"
            >{{ fillMessage(formats.month, { month: String(item.month) }) }}</button>
        </div>

        <footer class="ark-month-cal__foot">
            <button type="button" class="ark-month-cal__current" :disabled="currentYear < minYear || currentYear > maxYear" @click="goToCurrentMonth">
                {{ labels.currentMonth }}
            </button>
        </footer>
    </div>
</template>

<style scoped>
.ark-month-cal {
    width: 296px;
    padding: 14px;
    border: 1px solid #e2e5eb;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 12px 32px rgb(18 25 60 / 14%), 0 3px 8px rgb(18 25 60 / 8%);
    user-select: none;
}
.ark-month-cal__head { display: flex; align-items: center; justify-content: space-between; padding: 2px 4px 12px; }
.ark-month-cal__year { color: rgb(var(--v-theme-primary)); font-size: 1.1rem; font-weight: 700; }
.ark-month-cal__nav { display: flex; gap: 2px; }
.ark-month-cal__nav button { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border: 0; border-radius: 50%; background: transparent; color: rgb(var(--v-theme-primary)); cursor: pointer; }
.ark-month-cal__nav button:hover:not(:disabled) { background: rgb(var(--v-theme-brand-soft)); }
.ark-month-cal__nav button:disabled { opacity: .35; cursor: default; }
.ark-month-cal__grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 4px; }
.ark-month-cal__month { min-height: 48px; border: 0; border-radius: 12px; background: transparent; color: rgb(var(--v-theme-on-surface)); font-size: .9rem; font-weight: 600; cursor: pointer; }
.ark-month-cal__month:hover:not(.is-selected) { background: rgb(var(--v-theme-brand-soft)); }
.ark-month-cal__month.is-current { box-shadow: inset 0 0 0 1.5px rgb(var(--v-theme-primary)); }
.ark-month-cal__month.is-selected { background: rgb(var(--v-theme-primary)); color: #ffffff; box-shadow: 0 2px 8px rgb(26 38 83 / 35%); }
.ark-month-cal__month:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.ark-month-cal__foot { display: flex; justify-content: center; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eef0f4; }
.ark-month-cal__current { padding: 5px 14px; border: 0; border-radius: 999px; background: transparent; color: rgb(var(--v-theme-primary)); font-size: .78rem; font-weight: 600; cursor: pointer; }
.ark-month-cal__current:hover:not(:disabled) { background: rgb(var(--v-theme-brand-soft)); }
.ark-month-cal__current:disabled { opacity: .35; cursor: default; }
</style>
