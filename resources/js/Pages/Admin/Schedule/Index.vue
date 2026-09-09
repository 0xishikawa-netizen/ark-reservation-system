<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

type ScheduleView = 'day' | 'week';
type ScheduleAxis = 'staff' | 'booth';

interface ScheduleLane {
    id: number | null;
    display_name: string;
    color: string;
    sort_order: number;
}

interface Staff {
    user_id: number;
    display_name: string;
    color: string;
    sort_order: number;
}

interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

interface Shift {
    staff_id: number;
    start_at: string;
    end_at: string;
}

interface ScheduleReservation {
    id: number;
    customer_name: string;
    service_name: string;
    staff_id: number | null;
    booth_id: number | null;
    starts_at: string;
    ends_at: string;
    status: string;
    source: string;
}

interface BusinessHours {
    open: string;
    close: string;
    slot_minutes: number;
}

interface Booth {
    id: number;
    name: string;
    sort_order: number;
}

interface DateRange {
    start: string;
    end: string;
}

interface Filters {
    date: string;
    staff_id: number | null;
    view: ScheduleView;
    axis: ScheduleAxis;
}

interface ShadeSegment {
    top: number;
    height: number;
}

const props = defineProps<{
    staff: Staff[];
    staff_options: StaffOption[];
    shifts: Shift[];
    reservations: ScheduleReservation[];
    business_hours: BusinessHours;
    view: ScheduleView;
    axis: ScheduleAxis;
    range: DateRange;
    days: string[];
    booths: Booth[];
    filters: Filters;
}>();
const page = usePage();
const canManage = computed(() => page.props.auth.can.reservationsManage);

const date = ref(props.filters.date);
const staffId = ref<number | null>(props.filters.staff_id);
const viewMode = ref<ScheduleView>(props.filters.view);
const axisMode = ref<ScheduleAxis>(props.filters.axis);
const pixelsPerMinute = 1.25;

const openMinute = computed(() => timeToMinute(props.business_hours.open));
const closeMinute = computed(() => timeToMinute(props.business_hours.close));
const durationMinutes = computed(() => Math.max(closeMinute.value - openMinute.value, 0));
const canvasHeight = computed(() => durationMinutes.value * pixelsPerMinute);
const tickMinutes = computed(() => props.business_hours.slot_minutes > 30
    ? props.business_hours.slot_minutes
    : 30,
);

const lanes = computed<ScheduleLane[]>(() => {
    if (axisMode.value === 'booth') {
        const result: ScheduleLane[] = props.booths.map((booth) => ({
            id: booth.id,
            display_name: booth.name,
            color: '#00897b',
            sort_order: booth.sort_order,
        }));

        if (props.reservations.some((reservation) => reservation.booth_id === null)) {
            result.push({
                id: null,
                display_name: 'ブース未割当',
                color: '#78909c',
                sort_order: 32767,
            });
        }

        return result;
    }

    const result: ScheduleLane[] = props.staff.map((staff) => ({
        id: staff.user_id,
        display_name: staff.display_name,
        color: staff.color,
        sort_order: staff.sort_order,
    }));

    if (props.reservations.some((reservation) => reservation.staff_id === null)) {
        result.push({
            id: null,
            display_name: '担当なし',
            color: '#78909c',
            sort_order: 32767,
        });
    }

    return result;
});

const gridStyle = computed(() => ({
    gridTemplateColumns: `72px repeat(${Math.max(lanes.value.length, 1)}, minmax(210px, 1fr))`,
    minWidth: `${72 + Math.max(lanes.value.length, 1) * 210}px`,
}));

const timeTicks = computed(() => {
    const ticks: number[] = [];

    for (let minute = openMinute.value; minute <= closeMinute.value; minute += tickMinutes.value) {
        ticks.push(minute);
    }

    if (ticks[ticks.length - 1] !== closeMinute.value) {
        ticks.push(closeMinute.value);
    }

    return ticks;
});

function timeToMinute(value: string): number {
    const [hour = 0, minute = 0] = value.slice(0, 5).split(':').map(Number);

    return hour * 60 + minute;
}

function minuteToLabel(value: number): string {
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

function reservationStyle(reservation: ScheduleReservation): Record<string, string> {
    const startsAt = timeToMinute(reservation.starts_at.slice(11, 16));
    const endsAt = timeToMinute(reservation.ends_at.slice(11, 16));
    const top = Math.max(startsAt - openMinute.value, 0) * pixelsPerMinute;
    const visibleEnd = Math.min(endsAt, closeMinute.value);
    const height = Math.max(
        (visibleEnd - Math.max(startsAt, openMinute.value)) * pixelsPerMinute,
        2,
    );

    return {
        top: `${top}px`,
        height: `${height}px`,
    };
}

function reservationsFor(laneId: number | null, day: string): ScheduleReservation[] {
    return props.reservations.filter((reservation) => {
        const reservationLaneId = axisMode.value === 'staff'
            ? reservation.staff_id
            : reservation.booth_id;

        return reservationLaneId === laneId && reservation.starts_at.slice(0, 10) === day;
    });
}

function nonWorkingSegments(staffIdValue: number | null): ShadeSegment[] {
    if (staffIdValue === null) {
        return [];
    }

    const staffShifts = props.shifts.filter((shift) => shift.staff_id === staffIdValue);
    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const segments: ShadeSegment[] = [];
    let segmentStart: number | null = null;

    for (let minute = openMinute.value; minute < closeMinute.value; minute += unit) {
        const segmentEnd = Math.min(minute + unit, closeMinute.value);
        const working = staffShifts.some((shift) =>
            minute >= timeToMinute(shift.start_at)
            && segmentEnd <= timeToMinute(shift.end_at),
        );

        if (!working && segmentStart === null) {
            segmentStart = minute;
        }

        if (working && segmentStart !== null) {
            segments.push({
                top: (segmentStart - openMinute.value) * pixelsPerMinute,
                height: (minute - segmentStart) * pixelsPerMinute,
            });
            segmentStart = null;
        }
    }

    if (segmentStart !== null) {
        segments.push({
            top: (segmentStart - openMinute.value) * pixelsPerMinute,
            height: (closeMinute.value - segmentStart) * pixelsPerMinute,
        });
    }

    return segments;
}

function sourceClass(source: string): string {
    return `source-${source.toLowerCase().replace('_', '-')}`;
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        confirmed: '確定',
        completed: '完了',
        no_show: 'No-show',
        pending_payment: '支払い待ち',
        pending_external_sync: '連携待ち',
    };

    return labels[status] ?? status;
}

function navigate(): void {
    router.get('/admin/schedule', {
        date: date.value,
        staff_id: staffId.value ?? undefined,
        view: viewMode.value,
        axis: axisMode.value,
    }, { preserveState: true, replace: true });
}

function movePeriod(direction: number): void {
    const next = new Date(`${date.value}T12:00:00`);
    next.setDate(next.getDate() + direction * (viewMode.value === 'week' ? 7 : 1));
    const year = next.getFullYear();
    const month = String(next.getMonth() + 1).padStart(2, '0');
    const day = String(next.getDate()).padStart(2, '0');
    date.value = `${year}-${month}-${day}`;
    navigate();
}

function createHref(): string {
    const targetDate = viewMode.value === 'week' ? props.range.start : date.value;
    const params = new URLSearchParams({ date: targetDate });

    if (staffId.value !== null) {
        params.set('staff_id', String(staffId.value));
    }

    return `/admin/reservations/create?${params.toString()}`;
}

function dayLabel(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
    }).format(new Date(`${value}T12:00:00`));
}
</script>

<template>
    <Head title="予約台帳" />

    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-3">
        <div>
            <h1 class="text-h4">予約台帳</h1>
            <div class="text-medium-emphasis mt-1">
                {{ business_hours.open.slice(0, 5) }}〜{{ business_hours.close.slice(0, 5) }}
            </div>
        </div>
        <v-btn v-if="canManage" color="primary" :href="createHref()">新規予約</v-btn>
    </div>

    <v-card class="mb-4">
        <v-card-text class="schedule-toolbar">
            <div class="toolbar-period" role="group" aria-label="表示期間を移動">
                <v-btn size="small" variant="outlined" :aria-label="viewMode === 'week' ? '前週' : '前日'" @click="movePeriod(-1)">
                    {{ viewMode === 'week' ? '前週' : '前日' }}
                </v-btn>
                <v-btn size="small" variant="outlined" :aria-label="viewMode === 'week' ? '翌週' : '翌日'" @click="movePeriod(1)">
                    {{ viewMode === 'week' ? '翌週' : '翌日' }}
                </v-btn>
            </div>
            <v-text-field
                v-model="date"
                class="toolbar-field toolbar-date"
                type="date"
                label="表示日"
                density="compact"
                hide-details
                @change="navigate"
            />
            <v-select
                v-model="staffId"
                class="toolbar-field toolbar-staff"
                :items="staff_options"
                item-title="display_name"
                item-value="user_id"
                label="スタッフ絞り込み"
                density="compact"
                clearable
                hide-details
                @update:model-value="navigate"
            />
            <div class="toolbar-mode">
                <span class="toolbar-label">表示</span>
                <v-btn-toggle
                    v-model="viewMode"
                    class="toolbar-toggle"
                    mandatory
                    color="primary"
                    variant="outlined"
                    density="compact"
                    aria-label="表示期間"
                    @update:model-value="navigate"
                >
                    <v-btn value="day">日</v-btn>
                    <v-btn value="week">週</v-btn>
                </v-btn-toggle>
            </div>
            <div class="toolbar-mode">
                <span class="toolbar-label">軸</span>
                <v-btn-toggle
                    v-model="axisMode"
                    class="toolbar-toggle"
                    mandatory
                    color="primary"
                    variant="outlined"
                    density="compact"
                    aria-label="表示軸"
                    @update:model-value="navigate"
                >
                    <v-btn value="staff">スタッフ</v-btn>
                    <v-btn value="booth">ブース</v-btn>
                </v-btn-toggle>
            </div>
        </v-card-text>
    </v-card>

    <v-alert v-if="lanes.length === 0" type="info" variant="tonal">
        {{ axisMode === 'staff' ? '表示対象の予約受付スタッフはいません。' : '表示対象のブースはありません。' }}
    </v-alert>

    <v-card v-else class="schedule-card">
        <section
            v-for="displayDay in days"
            :key="displayDay"
            :class="{ 'week-day-section': viewMode === 'week' }"
        >
            <h2 v-if="viewMode === 'week'" class="date-header">
                <span class="date-chip">{{ dayLabel(displayDay) }}</span>
            </h2>
            <div class="schedule-scroll" :class="{ 'schedule-scroll--day': viewMode === 'day' }">
                <div class="schedule-grid schedule-header" :style="gridStyle">
                    <div class="time-header">時刻</div>
                    <div
                        v-for="lane in lanes"
                        :key="lane.id ?? 'unassigned'"
                        class="staff-header"
                        :class="{ 'staff-header--unassigned': lane.id === null }"
                        :style="{ borderTopColor: lane.color }"
                        :title="lane.display_name"
                    >
                        <span>{{ lane.display_name }}</span>
                    </div>
                </div>

                <div class="schedule-grid" :style="gridStyle">
                    <div class="time-axis" :style="{ height: `${canvasHeight}px` }">
                        <div
                            v-for="tick in timeTicks"
                            :key="tick"
                            class="time-label"
                            :style="{ top: `${(tick - openMinute) * pixelsPerMinute}px` }"
                        >
                            {{ minuteToLabel(tick) }}
                        </div>
                    </div>

                    <div
                        v-for="lane in lanes"
                        :key="lane.id ?? 'unassigned'"
                        class="staff-lane"
                        :style="{ height: `${canvasHeight}px` }"
                    >
                        <div
                            v-for="tick in timeTicks"
                            :key="`line-${tick}`"
                            class="time-line"
                            :style="{ top: `${(tick - openMinute) * pixelsPerMinute}px` }"
                        />
                        <template v-if="viewMode === 'day' && axisMode === 'staff'">
                            <div
                                v-for="(segment, index) in nonWorkingSegments(lane.id)"
                                :key="`shade-${index}`"
                                class="non-working"
                                :style="{ top: `${segment.top}px`, height: `${segment.height}px` }"
                            />
                        </template>
                        <button
                            v-for="reservation in reservationsFor(lane.id, displayDay)"
                            :key="reservation.id"
                            type="button"
                            class="reservation-card"
                            :disabled="!canManage"
                            :class="sourceClass(reservation.source)"
                            :style="reservationStyle(reservation)"
                            @click="router.visit(`/admin/reservations/${reservation.id}/edit`)"
                        >
                            <span class="reservation-topline">
                                <span class="reservation-time">{{ reservation.starts_at.slice(11, 16) }}</span>
                                <span class="reservation-status">{{ statusLabel(reservation.status) }}</span>
                            </span>
                            <strong class="reservation-customer" :title="reservation.customer_name">
                                {{ reservation.customer_name }}
                            </strong>
                            <span class="reservation-service" :title="reservation.service_name">
                                {{ reservation.service_name }}
                            </span>
                            <small class="reservation-source" :title="reservation.source">
                                {{ reservation.source }}
                            </small>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </v-card>
</template>

<style scoped>
.schedule-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.toolbar-period {
    display: flex;
    flex: 0 0 auto;
    gap: 0.5rem;
}

.toolbar-field {
    flex: 0 1 auto;
}

.toolbar-date {
    width: 180px;
}

.toolbar-staff {
    flex: 1 1 230px;
    min-width: 220px;
}

.toolbar-mode {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 0.5rem;
}

.toolbar-label {
    color: #546e7a;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

.toolbar-toggle :deep(.v-btn) {
    min-width: 48px;
    padding-inline: 12px;
}

.schedule-card {
    overflow: hidden;
}

.schedule-scroll {
    overflow-x: auto;
    overflow-y: hidden;
}

.schedule-scroll--day {
    overflow: auto;
    max-height: calc(100vh - 270px);
}

.week-day-section + .week-day-section {
    border-top: 1px solid #cfd8dc;
}

.date-header {
    margin: 0;
    padding: 10px 16px 8px;
    background: #f7f9fa;
}

.date-chip {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 3px 12px;
    border: 1px solid #c5d5df;
    border-radius: 999px;
    background: white;
    color: #37474f;
    font-size: 0.875rem;
    font-weight: 700;
}

.schedule-grid {
    display: grid;
}

.schedule-header {
    position: sticky;
    top: 0;
    z-index: 10;
    background: white;
    border-bottom: 1px solid #cfd8dc;
}

.time-header,
.staff-header {
    min-height: 58px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.staff-header {
    min-width: 0;
    padding: 8px 12px;
    border-left: 1px solid #e0e0e0;
    border-top: 5px solid;
    background: #fff;
    color: #263238;
}

.staff-header span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.staff-header--unassigned {
    background: #f5f7f8;
    color: #78909c;
    font-weight: 500;
}

.time-axis,
.staff-lane {
    position: relative;
}

.time-axis {
    background: #fafafa;
}

.time-label {
    position: absolute;
    right: 10px;
    transform: translateY(-50%);
    color: #546e7a;
    font-size: 0.75rem;
}

.staff-lane {
    border-left: 1px solid #cfd8dc;
    background: white;
    overflow: hidden;
}

.time-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: #eceff1;
}

.non-working {
    position: absolute;
    left: 0;
    right: 0;
    background: repeating-linear-gradient(
        -45deg,
        rgba(96, 125, 139, 0.08),
        rgba(96, 125, 139, 0.08) 6px,
        rgba(96, 125, 139, 0.14) 6px,
        rgba(96, 125, 139, 0.14) 12px
    );
}

.reservation-card {
    position: absolute;
    z-index: 2;
    left: 5px;
    right: 5px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    overflow: hidden;
    gap: 2px;
    box-sizing: border-box;
    min-width: 0;
    padding: 5px 8px 6px;
    border: 1px solid rgba(0, 0, 0, 0.18);
    border-left-width: 5px;
    border-radius: 6px;
    background: #fff;
    color: #263238;
    cursor: pointer;
    text-align: left;
    line-height: 1.25;
    box-shadow: 0 1px 3px rgba(38, 50, 56, 0.12);
}

.reservation-card:hover {
    filter: brightness(0.97);
    box-shadow: 0 2px 6px rgba(38, 50, 56, 0.18);
}

.reservation-card:disabled {
    cursor: default;
}

.reservation-card:disabled:hover {
    filter: none;
    box-shadow: none;
}

.reservation-card:focus-visible {
    outline: 2px solid #1565c0;
    outline-offset: -2px;
}

.reservation-topline {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}

.reservation-time {
    flex: 0 0 auto;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.reservation-status {
    overflow: hidden;
    min-width: 0;
    padding: 1px 6px;
    border: 1px solid #b0bec5;
    border-radius: 999px;
    background: #f5f7f8;
    color: #37474f;
    font-size: 0.625rem;
    font-weight: 700;
    line-height: 1.4;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reservation-customer,
.reservation-service,
.reservation-source {
    display: block;
    overflow: hidden;
    width: 100%;
    min-width: 0;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reservation-customer {
    font-size: 0.8125rem;
    font-weight: 700;
}

.reservation-service {
    color: #607d8b;
    font-size: 0.72rem;
}

.reservation-source {
    color: #78909c;
    font-size: 0.625rem;
}

.source-admin {
    border-left-color: #673ab7;
}

.source-ark-web {
    border-left-color: #00897b;
}

.source-hotpepper {
    border-left-color: #d81b60;
}

.source-epark {
    border-left-color: #1e88e5;
}

.source-peak-manager {
    border-left-color: #3949ab;
}

@media (max-width: 800px) {
    .schedule-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        align-items: stretch;
    }

    .toolbar-period,
    .toolbar-field,
    .toolbar-mode {
        width: 100%;
    }

    .toolbar-period :deep(.v-btn) {
        flex: 1 1 50%;
    }

    .toolbar-date,
    .toolbar-staff {
        min-width: 0;
    }

    .toolbar-mode {
        flex-direction: column;
        align-items: stretch;
        gap: 0.375rem;
    }

    .toolbar-toggle {
        display: flex;
        width: 100%;
    }

    .toolbar-toggle :deep(.v-btn) {
        flex: 1 1 50%;
    }
}
</style>
