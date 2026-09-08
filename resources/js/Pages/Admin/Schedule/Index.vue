<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

interface StaffLane {
    user_id: number | null;
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

interface Filters {
    date: string;
    staff_id: number | null;
}

interface ShadeSegment {
    top: number;
    height: number;
}

const props = defineProps<{
    staff: Array<Omit<StaffLane, 'user_id'> & { user_id: number }>;
    staff_options: StaffOption[];
    shifts: Shift[];
    reservations: ScheduleReservation[];
    business_hours: BusinessHours;
    filters: Filters;
}>();
const page = usePage();
const canManage = computed(() => page.props.auth.can.reservationsManage);

const date = ref(props.filters.date);
const staffId = ref<number | null>(props.filters.staff_id);
const pixelsPerMinute = 1.25;

const openMinute = computed(() => timeToMinute(props.business_hours.open));
const closeMinute = computed(() => timeToMinute(props.business_hours.close));
const durationMinutes = computed(() => Math.max(closeMinute.value - openMinute.value, 0));
const canvasHeight = computed(() => durationMinutes.value * pixelsPerMinute);
const tickMinutes = computed(() => props.business_hours.slot_minutes > 30
    ? props.business_hours.slot_minutes
    : 30,
);

const lanes = computed<StaffLane[]>(() => {
    const result: StaffLane[] = props.staff.map((staff) => ({ ...staff }));

    if (props.reservations.some((reservation) => reservation.staff_id === null)) {
        result.push({
            user_id: null,
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

function reservationsFor(staffIdValue: number | null): ScheduleReservation[] {
    return props.reservations.filter((reservation) => reservation.staff_id === staffIdValue);
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
    }, { preserveState: true, replace: true });
}

function moveDay(days: number): void {
    const next = new Date(`${date.value}T12:00:00`);
    next.setDate(next.getDate() + days);
    const year = next.getFullYear();
    const month = String(next.getMonth() + 1).padStart(2, '0');
    const day = String(next.getDate()).padStart(2, '0');
    date.value = `${year}-${month}-${day}`;
    navigate();
}

function createHref(): string {
    const params = new URLSearchParams({ date: date.value });

    if (staffId.value !== null) {
        params.set('staff_id', String(staffId.value));
    }

    return `/admin/reservations/create?${params.toString()}`;
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
        <v-card-text class="toolbar-grid">
            <div class="d-flex ga-2 align-center">
                <v-btn variant="outlined" aria-label="前日" @click="moveDay(-1)">前日</v-btn>
                <v-btn variant="outlined" aria-label="翌日" @click="moveDay(1)">翌日</v-btn>
            </div>
            <v-text-field v-model="date" type="date" label="表示日" hide-details @change="navigate" />
            <v-select
                v-model="staffId"
                :items="staff_options"
                item-title="display_name"
                item-value="user_id"
                label="スタッフ絞り込み"
                clearable
                hide-details
                @update:model-value="navigate"
            />
        </v-card-text>
    </v-card>

    <v-alert v-if="lanes.length === 0" type="info" variant="tonal">
        表示対象の予約受付スタッフはいません。
    </v-alert>

    <v-card v-else class="schedule-card">
        <div class="schedule-scroll">
            <div class="schedule-grid schedule-header" :style="gridStyle">
                <div class="time-header">時刻</div>
                <div
                    v-for="lane in lanes"
                    :key="lane.user_id ?? 'unassigned'"
                    class="staff-header"
                    :style="{ borderTopColor: lane.color }"
                >
                    {{ lane.display_name }}
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
                    :key="lane.user_id ?? 'unassigned'"
                    class="staff-lane"
                    :style="{ height: `${canvasHeight}px` }"
                >
                    <div
                        v-for="tick in timeTicks"
                        :key="`line-${tick}`"
                        class="time-line"
                        :style="{ top: `${(tick - openMinute) * pixelsPerMinute}px` }"
                    />
                    <div
                        v-for="(segment, index) in nonWorkingSegments(lane.user_id)"
                        :key="`shade-${index}`"
                        class="non-working"
                        :style="{ top: `${segment.top}px`, height: `${segment.height}px` }"
                    />
                    <button
                        v-for="reservation in reservationsFor(lane.user_id)"
                        :key="reservation.id"
                        type="button"
                        class="reservation-card"
                        :disabled="!canManage"
                        :class="sourceClass(reservation.source)"
                        :style="reservationStyle(reservation)"
                        @click="router.visit(`/admin/reservations/${reservation.id}/edit`)"
                    >
                        <span class="reservation-time">{{ reservation.starts_at.slice(11, 16) }}</span>
                        <strong>{{ reservation.customer_name }}</strong>
                        <span>{{ reservation.service_name }}</span>
                        <small>{{ statusLabel(reservation.status) }}・{{ reservation.source }}</small>
                    </button>
                </div>
            </div>
        </div>
    </v-card>
</template>

<style scoped>
.toolbar-grid {
    display: grid;
    grid-template-columns: auto minmax(180px, 240px) minmax(220px, 320px);
    align-items: center;
    gap: 1rem;
}

.schedule-card {
    overflow: hidden;
}

.schedule-scroll {
    overflow: auto;
    max-height: calc(100vh - 270px);
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
    border-left: 1px solid #e0e0e0;
    border-top: 5px solid;
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
    padding: 5px 8px;
    border: 1px solid rgba(0, 0, 0, 0.18);
    border-left-width: 5px;
    border-radius: 6px;
    background: #ede7f6;
    color: #263238;
    cursor: pointer;
    text-align: left;
    line-height: 1.25;
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

.reservation-time {
    font-size: 0.72rem;
    font-weight: 700;
}

.reservation-card small {
    opacity: 0.78;
}

.source-admin {
    border-left-color: #673ab7;
    background: #ede7f6;
}

.source-ark-web {
    border-left-color: #00897b;
    background: #e0f2f1;
}

.source-hotpepper {
    border-left-color: #d81b60;
    background: #fce4ec;
}

.source-epark {
    border-left-color: #1e88e5;
    background: #e3f2fd;
}

.source-peak-manager {
    border-left-color: #3949ab;
    background: #e8eaf6;
}

@media (max-width: 800px) {
    .toolbar-grid {
        grid-template-columns: 1fr;
    }
}
</style>
