<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

type DayStatus = 'open' | 'some' | 'full';

const props = withDefaults(defineProps<{
    /** 選択日（ISO 'YYYY-MM-DD'）。未選択は ''。 */
    modelValue: string;
    min?: string;
    max?: string;
    dayStatuses?: Record<string, DayStatus>;
    availabilityRequired?: boolean;
    loading?: boolean;
}>(), {
    min: '',
    max: '',
    dayStatuses: () => ({}),
    availabilityRequired: false,
    loading: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
    'month-change': [value: string];
}>();

const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'] as const;

const toIso = (date: Date): string => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
};

const parseIso = (value: string): Date | null => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [y, m, d] = value.split('-').map(Number);
    const date = new Date(y, m - 1, d);

    return Number.isNaN(date.getTime()) ? null : date;
};

const today = new Date();
const todayIso = toIso(today);

const viewDate = ref<Date>(
    (() => {
        const base = parseIso(props.modelValue) ?? today;

        return new Date(base.getFullYear(), base.getMonth(), 1);
    })(),
);

watch(
    () => props.modelValue,
    (value) => {
        const parsed = parseIso(value);

        if (parsed) {
            viewDate.value = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
        }
    },
);

const headYear = computed(() => `${viewDate.value.getFullYear()}年`);
const headMonth = computed(() => `${viewDate.value.getMonth() + 1}月`);

interface DayCell {
    iso: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    isSelected: boolean;
    disabled: boolean;
    weekday: number;
    status: DayStatus | null;
}

const isDisabled = (iso: string, inMonth = true): boolean => {
    if (props.min && iso < props.min) {
        return true;
    }

    if (props.max && iso > props.max) {
        return true;
    }

    if (props.availabilityRequired
        && (!inMonth || props.dayStatuses[iso] === undefined || props.dayStatuses[iso] === 'full')) {
        return true;
    }

    return false;
};

const statusSymbol = (status: DayStatus | null): string => {
    if (status === 'open') return '○';
    if (status === 'some') return '△';
    if (status === 'full') return '×';
    return '';
};

const statusLabel = (status: DayStatus | null): string => {
    if (status === 'open') return '空きあり';
    if (status === 'some') return '残りわずか';
    if (status === 'full') return '空きなし';
    return '';
};

const weeks = computed<DayCell[][]>(() => {
    const year = viewDate.value.getFullYear();
    const month = viewDate.value.getMonth();
    const firstWeekday = new Date(year, month, 1).getDay();
    const gridStart = new Date(year, month, 1 - firstWeekday);

    const cells: DayCell[] = [];

    for (let i = 0; i < 42; i += 1) {
        const date = new Date(
            gridStart.getFullYear(),
            gridStart.getMonth(),
            gridStart.getDate() + i,
        );
        const iso = toIso(date);

        const inMonth = date.getMonth() === month;
        const status = props.dayStatuses[iso] ?? null;

        cells.push({
            iso,
            day: date.getDate(),
            inMonth,
            isToday: iso === todayIso,
            isSelected: iso === props.modelValue,
            disabled: isDisabled(iso, inMonth),
            weekday: date.getDay(),
            status,
        });
    }

    const rows: DayCell[][] = [];

    for (let i = 0; i < cells.length; i += 7) {
        rows.push(cells.slice(i, i + 7));
    }

    if (rows.length === 6 && rows[5].every((cell) => !cell.inMonth)) {
        rows.pop();
    }

    return rows;
});

const shiftMonth = (delta: number): void => {
    viewDate.value = new Date(
        viewDate.value.getFullYear(),
        viewDate.value.getMonth() + delta,
        1,
    );
    emit('month-change', toIso(viewDate.value).slice(0, 7));
};

const goToday = (): void => {
    viewDate.value = new Date(today.getFullYear(), today.getMonth(), 1);
    emit('month-change', todayIso.slice(0, 7));

    if (!isDisabled(todayIso)) {
        emit('update:modelValue', todayIso);
    }
};

onMounted(() => emit('month-change', toIso(viewDate.value).slice(0, 7)));

const select = (cell: DayCell): void => {
    if (!cell.disabled) {
        emit('update:modelValue', cell.iso);
    }
};
</script>

<template>
    <div class="ark-cal" role="group" aria-label="日付を選択">
        <header class="ark-cal__head">
            <div class="ark-cal__headline">
                <span class="ark-cal__year">{{ headYear }}</span>
                <span class="ark-cal__month">{{ headMonth }}</span>
            </div>
            <div class="ark-cal__nav">
                <button type="button" aria-label="前の月" @click="shiftMonth(-1)">
                    <v-icon icon="mdi-chevron-left" size="20" />
                </button>
                <button type="button" aria-label="次の月" @click="shiftMonth(1)">
                    <v-icon icon="mdi-chevron-right" size="20" />
                </button>
            </div>
        </header>

        <div class="ark-cal__weekdays">
            <span
                v-for="(label, index) in WEEKDAYS"
                :key="label"
                :class="{
                    'ark-cal__wd--sun': index === 0,
                    'ark-cal__wd--sat': index === 6,
                }"
            >{{ label }}</span>
        </div>

        <div class="ark-cal__grid">
            <template v-for="(week, wi) in weeks" :key="wi">
                <button
                    v-for="cell in week"
                    :key="cell.iso"
                    type="button"
                    class="ark-cal__day"
                    :class="{
                        'is-out': !cell.inMonth,
                        'is-today': cell.isToday && !cell.isSelected,
                        'is-selected': cell.isSelected,
                        'is-sun': cell.weekday === 0,
                        'is-sat': cell.weekday === 6,
                    }"
                    :disabled="cell.disabled"
                    :aria-pressed="cell.isSelected"
                    :aria-current="cell.isToday ? 'date' : undefined"
                    :aria-label="`${cell.iso}${cell.status ? ` ${statusLabel(cell.status)}` : ''}`"
                    @click="select(cell)"
                >
                    <span class="ark-cal__num">{{ cell.day }}</span>
                    <span
                        v-if="cell.inMonth && cell.status"
                        class="ark-cal__status"
                        :class="`is-${cell.status}`"
                        aria-hidden="true"
                    >{{ statusSymbol(cell.status) }}</span>
                </button>
            </template>
        </div>

        <footer class="ark-cal__foot">
            <span v-if="loading" class="ark-cal__loading">空き状況を確認中…</span>
            <button type="button" class="ark-cal__today" @click="goToday">
                今日にもどる
            </button>
        </footer>
    </div>
</template>

<style scoped>
.ark-cal {
    width: 296px;
    padding: 14px;
    border: 1px solid #e2e5eb;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 12px 32px rgb(18 25 60 / 14%), 0 3px 8px rgb(18 25 60 / 8%);
    user-select: none;
}

.ark-cal__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 2px 4px 10px;
}

.ark-cal__headline {
    display: flex;
    align-items: baseline;
    gap: 6px;
}

.ark-cal__year {
    font-size: 0.78rem;
    font-weight: 600;
    color: rgb(var(--v-theme-secondary));
}

.ark-cal__month {
    font-size: 1.15rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    color: rgb(var(--v-theme-primary));
}

.ark-cal__nav {
    display: flex;
    gap: 2px;
}

.ark-cal__nav button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: rgb(var(--v-theme-primary));
    cursor: pointer;
    transition: background-color 0.12s ease;
}

.ark-cal__nav button:hover {
    background: rgb(var(--v-theme-brand-soft));
}

.ark-cal__weekdays,
.ark-cal__grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

.ark-cal__weekdays {
    margin-bottom: 4px;
}

.ark-cal__weekdays span {
    padding-block: 6px;
    text-align: center;
    font-size: 0.7rem;
    font-weight: 700;
    color: rgb(var(--v-theme-secondary));
}

.ark-cal__wd--sun {
    color: rgb(var(--v-theme-error)) !important;
}

.ark-cal__wd--sat {
    color: rgb(var(--v-theme-info)) !important;
}

.ark-cal__grid {
    gap: 1px 0;
}

.ark-cal__day {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    aspect-ratio: 1;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.ark-cal__num {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
    color: rgb(var(--v-theme-on-surface));
    transition: background-color 0.12s ease, color 0.12s ease, box-shadow 0.12s ease;
}

.ark-cal__status {
    position: absolute;
    bottom: -1px;
    height: 14px;
    font-size: 0.72rem;
    font-weight: 800;
    line-height: 1;
}

.ark-cal__status.is-open {
    color: rgb(var(--v-theme-success));
}

.ark-cal__status.is-some {
    color: rgb(var(--v-theme-warning));
}

.ark-cal__status.is-full {
    color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity));
}

.ark-cal__day.is-selected .ark-cal__status {
    color: #ffffff;
}

.ark-cal__day:hover:not(:disabled) .ark-cal__num {
    background: rgb(var(--v-theme-brand-soft));
}

.ark-cal__day.is-out .ark-cal__num {
    color: rgb(var(--v-theme-secondary));
    opacity: 0.45;
}

.ark-cal__day.is-sun:not(.is-out):not(.is-selected) .ark-cal__num {
    color: rgb(var(--v-theme-error));
}

.ark-cal__day.is-sat:not(.is-out):not(.is-selected) .ark-cal__num {
    color: rgb(var(--v-theme-info));
}

.ark-cal__day.is-today .ark-cal__num {
    font-weight: 700;
    box-shadow: inset 0 0 0 1.5px rgb(var(--v-theme-primary));
}

.ark-cal__day.is-selected .ark-cal__num {
    background: rgb(var(--v-theme-primary));
    color: #ffffff;
    font-weight: 700;
    box-shadow: 0 2px 8px rgb(26 38 83 / 35%);
}

.ark-cal__day:disabled {
    cursor: default;
}

.ark-cal__day:disabled .ark-cal__num {
    opacity: 0.28;
}

.ark-cal__day:focus-visible .ark-cal__num {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 2px;
}

.ark-cal__foot {
    display: flex;
    justify-content: center;
    margin-top: 8px;
    padding-top: 10px;
    border-top: 1px solid #eef0f4;
}

.ark-cal__loading {
    align-self: center;
    margin-right: auto;
    color: rgb(var(--v-theme-secondary));
    font-size: 0.72rem;
}

.ark-cal__today {
    padding: 5px 14px;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: rgb(var(--v-theme-primary));
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.12s ease;
}

.ark-cal__today:hover {
    background: rgb(var(--v-theme-brand-soft));
}
</style>
