<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { MESSAGES } from '@/constants/messages';
import { fillMessage } from '@/utils/message';
import { toIsoDate } from '@/utils/dateFormat';

type AvailabilityStatus = 'open' | 'some' | 'full';

interface WeekDay {
    date: string;
    label: string;
}

interface WeekAvailabilityResponse {
    days: WeekDay[];
    times: string[];
    cells: Record<string, Record<string, AvailabilityStatus>>;
}

const props = defineProps<{
    modelValue: string | null;
    serviceId: number;
    staffId: number | null;
    weekEndpoint: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const WEEKDAYS = MESSAGES.customerUi.calendar.weekdays;

/** 1週間の日数（1画面に並べる日数・週送りの日数）。 */
const DAYS_PER_WEEK = 7;
/** 条件変更後に週の空き状況を取りに行くまでの待ち時間（ミリ秒）。連続変更での多重取得を防ぐ。 */
const WEEK_LOAD_DEBOUNCE_MS = 150;
const todayDate = startOfDay(new Date());
const todayIso = toIsoDate(todayDate);
const weekStart = ref(todayIso);
const week = ref<WeekAvailabilityResponse>({ days: [], times: [], cells: {} });
const loadingWeek = ref(false);
const weekError = ref('');

const previousDisabled = computed(() => addDays(weekStart.value, -DAYS_PER_WEEK) < todayIso);
const rangeLabel = computed(() => {
    if (week.value.days.length === DAYS_PER_WEEK) {
        return rangeText(week.value.days[0].label, week.value.days[DAYS_PER_WEEK - 1].label);
    }

    return rangeText(dateLabel(weekStart.value), dateLabel(addDays(weekStart.value, DAYS_PER_WEEK - 1)));
});

let weekTimer: ReturnType<typeof setTimeout> | null = null;
let weekRequestId = 0;

watch(
    () => props.serviceId,
    () => {
        weekStart.value = todayIso;
        resetForCriteriaChange();
    },
    { immediate: true },
);

watch(
    () => props.staffId,
    () => resetForCriteriaChange(),
);

onBeforeUnmount(() => {
    if (weekTimer !== null) clearTimeout(weekTimer);
    weekRequestId += 1;
});

function rangeText(start: string, end: string): string {
    return fillMessage(MESSAGES.customerUi.weeklyAvailability.range, { start: start, end: end });
}

function startOfDay(value: Date): Date {
    return new Date(value.getFullYear(), value.getMonth(), value.getDate());
}

function parseIso(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function addDays(value: string, amount: number): string {
    const date = parseIso(value);
    date.setDate(date.getDate() + amount);

    return toIsoDate(date);
}

function dateLabel(value: string): string {
    const date = parseIso(value);

    return `${date.getMonth() + 1}/${date.getDate()}(${WEEKDAYS[date.getDay()]})`;
}

function headerParts(day: WeekDay): { date: string; weekday: string } {
    const match = day.label.match(/^(.+)(\(.+\))$/);

    return match === null
        ? { date: day.label, weekday: '' }
        : { date: match[1], weekday: match[2] };
}

function statusSymbol(status: AvailabilityStatus): string {
    if (status === 'open') return '○';
    if (status === 'some') return '△';

    return '×';
}

function statusLabel(status: AvailabilityStatus): string {
    if (status === 'open') return MESSAGES.customerUi.calendar.statusOpen;
    if (status === 'some') return MESSAGES.customerUi.calendar.statusSome;

    return MESSAGES.customerUi.calendar.statusFull;
}

function resetForCriteriaChange(): void {
    emit('update:modelValue', null);
    scheduleWeekLoad();
}

function scheduleWeekLoad(): void {
    if (weekTimer !== null) clearTimeout(weekTimer);
    weekRequestId += 1;
    loadingWeek.value = true;
    weekError.value = '';
    week.value = { days: [], times: [], cells: {} };
    weekTimer = setTimeout(() => void loadWeekAvailability(), WEEK_LOAD_DEBOUNCE_MS);
}

async function loadWeekAvailability(): Promise<void> {
    const requestId = ++weekRequestId;
    loadingWeek.value = true;
    weekError.value = '';
    const params = new URLSearchParams({
        service_id: String(props.serviceId),
        start_date: weekStart.value,
    });
    if (props.staffId !== null) params.set('staff_id', String(props.staffId));

    try {
        const response = await fetch(`${props.weekEndpoint}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(MESSAGES.availability.weekLoadFailed);
        const result = (await response.json()) as WeekAvailabilityResponse;
        if (requestId === weekRequestId) week.value = result;
    } catch (error: unknown) {
        if (requestId === weekRequestId) {
            week.value = { days: [], times: [], cells: {} };
            weekError.value = error instanceof Error
                ? error.message : MESSAGES.availability.weekLoadFailed;
        }
    } finally {
        if (requestId === weekRequestId) loadingWeek.value = false;
    }
}

function moveWeek(offset: number): void {
    const next = addDays(weekStart.value, offset * DAYS_PER_WEEK);
    if (next < todayIso) return;
    weekStart.value = next;
    emit('update:modelValue', null);
    scheduleWeekLoad();
}

function selectCell(day: string, time: string, status: AvailabilityStatus): void {
    if (status === 'full') return;
    emit('update:modelValue', `${day} ${time}:00`);
}
</script>

<template>
    <div class="weekly-availability">
        <div class="weekly-availability__navigation">
            <v-btn
                variant="text" size="small" prepend-icon="mdi-chevron-left"
                :disabled="previousDisabled" @click="moveWeek(-1)"
            >{{ MESSAGES.customerUi.weeklyAvailability.previousWeek }}</v-btn>
            <strong class="weekly-availability__range">{{ rangeLabel }}</strong>
            <v-btn
                variant="text" size="small" append-icon="mdi-chevron-right"
                @click="moveWeek(1)"
            >{{ MESSAGES.customerUi.weeklyAvailability.nextWeek }}</v-btn>
        </div>

        <p v-if="loadingWeek" class="text-body-2 text-medium-emphasis mb-3" aria-live="polite">
            {{ MESSAGES.customerUi.weeklyAvailability.loading }}
        </p>
        <v-alert v-if="weekError" type="error" variant="tonal" class="mb-4">{{ weekError }}</v-alert>

        <div v-if="week.days.length === DAYS_PER_WEEK && week.times.length > 0" class="weekly-availability__table-wrap">
            <table class="weekly-availability__table">
                <thead>
                    <tr>
                        <th class="weekly-availability__time-head" scope="col">{{ MESSAGES.customerUi.weeklyAvailability.time }}</th>
                        <th v-for="day in week.days" :key="day.date" scope="col">
                            <span>{{ headerParts(day).date }}</span>
                            <span>{{ headerParts(day).weekday }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="time in week.times" :key="time">
                        <th scope="row">{{ time }}</th>
                        <td v-for="day in week.days" :key="`${day.date}-${time}`">
                            <button
                                type="button"
                                class="weekly-availability__cell"
                                :class="[
                                    `is-${week.cells[day.date]?.[time] ?? 'full'}`,
                                    { 'is-selected': modelValue === `${day.date} ${time}:00` },
                                ]"
                                :disabled="(week.cells[day.date]?.[time] ?? 'full') === 'full'"
                                :aria-pressed="modelValue === `${day.date} ${time}:00`"
                                :aria-label="`${day.label} ${time} ${statusLabel(week.cells[day.date]?.[time] ?? 'full')}`"
                                @click="selectCell(day.date, time, week.cells[day.date]?.[time] ?? 'full')"
                            >{{ statusSymbol(week.cells[day.date]?.[time] ?? 'full') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <v-alert v-else-if="!loadingWeek && !weekError" type="info" variant="tonal" class="mb-4">
            {{ MESSAGES.availability.noneToShow }}
        </v-alert>

        <div class="weekly-availability__legend text-body-2" :aria-label="MESSAGES.customerUi.weeklyAvailability.legend">
            <span class="text-success">{{ MESSAGES.customerUi.weeklyAvailability.legendOpen }}</span>
            <span class="text-warning">{{ MESSAGES.customerUi.weeklyAvailability.legendSome }}</span>
            <span class="text-medium-emphasis">{{ MESSAGES.customerUi.weeklyAvailability.legendFull }}</span>
        </div>
    </div>
</template>

<style scoped>
.weekly-availability__navigation {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 2px;
    margin-bottom: var(--ark-space-2);
}
.weekly-availability__navigation :deep(.v-btn) { min-width: 0; padding-inline: 6px; }
.weekly-availability__range { text-align: center; font-size: .875rem; line-height: 1.35; }
.weekly-availability__table-wrap { width: 100%; overflow: hidden; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: var(--ark-radius-sm); }
.weekly-availability__table { width: 100%; table-layout: fixed; border-collapse: collapse; background: rgb(var(--v-theme-surface)); }
.weekly-availability__table th,
.weekly-availability__table td { padding: 0; border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); text-align: center; }
.weekly-availability__table tr:last-child th,
.weekly-availability__table tr:last-child td { border-bottom: 0; }
.weekly-availability__table th:last-child,
.weekly-availability__table td:last-child { border-right: 0; }
.weekly-availability__table thead th { height: 48px; background: rgb(var(--v-theme-surface-light)); font-size: .68rem; line-height: 1.25; }
.weekly-availability__table thead th span { display: block; }
.weekly-availability__table th:first-child { width: 46px; font-size: .7rem; font-variant-numeric: tabular-nums; }
.weekly-availability__cell { display: grid; width: 100%; height: 44px; min-width: 0; padding: 0; place-items: center; border: 0; background: transparent; font-size: 1rem; font-weight: 800; cursor: pointer; }
.weekly-availability__cell.is-open { color: rgb(var(--v-theme-success)); }
.weekly-availability__cell.is-some { color: rgb(var(--v-theme-warning)); }
.weekly-availability__cell.is-full { color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); cursor: default; }
.weekly-availability__cell:not(:disabled):hover { background: rgb(var(--v-theme-brand-soft)); }
.weekly-availability__cell.is-selected { background: rgb(var(--v-theme-primary)); color: #ffffff; box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary)); }
.weekly-availability__cell:focus-visible { position: relative; z-index: 1; outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.weekly-availability__legend { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--ark-space-4); margin-top: var(--ark-space-3); }
@media (max-width: 430px) {
    .weekly-availability__navigation :deep(.v-btn__content) { font-size: .75rem; }
    .weekly-availability__navigation :deep(.v-btn__prepend),
    .weekly-availability__navigation :deep(.v-btn__append) { margin-inline: 0; }
    .weekly-availability__range { font-size: .78rem; }
}
</style>
