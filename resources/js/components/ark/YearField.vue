<script setup lang="ts">
import { computed, ref, watch, useAttrs } from 'vue';
import { MESSAGES } from '@/constants/messages';

/**
 * 対象年の入力欄。MonthField（ArkMonthCalendar）と同じ見た目のポップアップで12年分から選ぶ。
 * ブラウザ標準の number input を使わず、Reports の年選択はこれに統一する。
 */
defineOptions({ inheritAttrs: false });

type FieldDensity = 'default' | 'comfortable' | 'compact';

const props = withDefaults(defineProps<{
    /** 幅いっぱいに広げる（ブッキングボードの左パネルなど、例外の画面だけで使う）。 */
    block?: boolean;
    modelValue: number;
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

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();
const labels = MESSAGES.calendar;
const menuOpen = ref(false);
const currentYear = Number(new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Tokyo', year: 'numeric' }).format(new Date()));

const pageStartFor = (year: number): number => year - ((year - props.minYear) % 12);
const pageStart = ref(pageStartFor(props.modelValue));
watch(() => props.modelValue, (year) => { pageStart.value = pageStartFor(year); });

const years = computed(() => Array.from({ length: 12 }, (_, index) => pageStart.value + index)
    .filter((year) => year >= props.minYear && year <= props.maxYear));
const displayValue = computed(() => (Number.isInteger(props.modelValue) ? `${props.modelValue}年` : ''));

function shiftPage(delta: number): void {
    const next = pageStart.value + delta * 12;
    if (next + 11 >= props.minYear && next <= props.maxYear) pageStart.value = next;
}

function select(year: number): void {
    emit('update:modelValue', year);
    menuOpen.value = false;
}
</script>

<template>
    <div class="ark-field-root ark-year-field-root" :class="{ 'ark-field-root--block': block }" v-bind="rootAttrs">
        <v-menu v-model="menuOpen" :close-on-content-click="false" location="bottom start" min-width="auto" offset="6">
            <template #activator="{ props: activatorProps }">
                <div class="ark-year-field">
                    <v-text-field
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
            <div class="ark-year-cal" role="group" :aria-label="labels.selectYear">
                <header class="ark-year-cal__head">
                    <span class="ark-year-cal__range">{{ years[0] }}〜{{ years[years.length - 1] }}年</span>
                    <div class="ark-year-cal__nav">
                        <button type="button" :aria-label="labels.previousYears" :disabled="pageStart <= minYear" @click="shiftPage(-1)">
                            <v-icon icon="mdi-chevron-left" size="20" />
                        </button>
                        <button type="button" :aria-label="labels.nextYears" :disabled="pageStart + 12 > maxYear" @click="shiftPage(1)">
                            <v-icon icon="mdi-chevron-right" size="20" />
                        </button>
                    </div>
                </header>
                <div class="ark-year-cal__grid">
                    <button
                        v-for="year in years"
                        :key="year"
                        type="button"
                        class="ark-year-cal__year"
                        :class="{ 'is-selected': year === modelValue, 'is-current': year === currentYear && year !== modelValue }"
                        :aria-pressed="year === modelValue"
                        :data-testid="`calendar-year-${year}`"
                        @click="select(year)"
                    >{{ year }}</button>
                </div>
                <footer class="ark-year-cal__foot">
                    <button type="button" class="ark-year-cal__current" :disabled="currentYear < minYear || currentYear > maxYear" @click="select(currentYear)">
                        {{ labels.currentYear }}
                    </button>
                </footer>
            </div>
        </v-menu>
    </div>
</template>

<style scoped>
/* 日付・月・年の入力欄は全画面で同じ幅にそろえる（例外は block で幅いっぱい）。 */
.ark-year-field-root {
    width: var(--ark-field-year);
    max-width: 100%;
    flex: 0 0 auto;
}

.ark-field-root--block {
    width: 100%;
    flex: 1 1 auto;
}

.ark-year-field { width: 200px; max-width: 100%; }
.ark-year-cal {
    width: 296px;
    padding: 14px;
    border: 1px solid #e2e5eb;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 12px 32px rgb(18 25 60 / 14%), 0 3px 8px rgb(18 25 60 / 8%);
    user-select: none;
}
.ark-year-cal__head { display: flex; align-items: center; justify-content: space-between; padding: 2px 4px 12px; }
.ark-year-cal__range { color: rgb(var(--v-theme-primary)); font-size: 1.05rem; font-weight: 700; }
.ark-year-cal__nav { display: flex; gap: 2px; }
.ark-year-cal__nav button { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border: 0; border-radius: 50%; background: transparent; color: rgb(var(--v-theme-primary)); cursor: pointer; }
.ark-year-cal__nav button:hover:not(:disabled) { background: rgb(var(--v-theme-brand-soft)); }
.ark-year-cal__nav button:disabled { opacity: .35; cursor: default; }
.ark-year-cal__grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 4px; }
.ark-year-cal__year { min-height: 48px; border: 0; border-radius: 12px; background: transparent; color: rgb(var(--v-theme-on-surface)); font-size: .9rem; font-weight: 600; font-variant-numeric: tabular-nums; cursor: pointer; }
.ark-year-cal__year:hover:not(.is-selected) { background: rgb(var(--v-theme-brand-soft)); }
.ark-year-cal__year.is-current { box-shadow: inset 0 0 0 1.5px rgb(var(--v-theme-primary)); }
.ark-year-cal__year.is-selected { background: rgb(var(--v-theme-primary)); color: #ffffff; box-shadow: 0 2px 8px rgb(26 38 83 / 35%); }
.ark-year-cal__year:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.ark-year-cal__foot { display: flex; justify-content: center; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eef0f4; }
.ark-year-cal__current { padding: 5px 14px; border: 0; border-radius: 999px; background: transparent; color: rgb(var(--v-theme-primary)); font-size: .78rem; font-weight: 600; cursor: pointer; }
.ark-year-cal__current:hover:not(:disabled) { background: rgb(var(--v-theme-brand-soft)); }
.ark-year-cal__current:disabled { opacity: .35; cursor: default; }
</style>
