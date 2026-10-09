<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { DateField, EmptyValue, PageHeader, SectionCard, TimeField } from '@/components/ark';
import { MESSAGES, confirmDeleteShiftMessage } from '@/constants/messages';
import { fillMessage } from '@/utils/message';

defineOptions({ layout: AdminLayout });

// 時刻入力の選択間隔（分）。
const TIME_STEP_MINUTES = 5;
// 勤怠時刻を素早く調整する候補（分）。
const CLOCK_IN_NUDGE_MINUTES = [-30, -15, 15, 30] as const;
const CLOCK_OUT_NUDGE_MINUTES = [-30, -15, 15, 30, 60] as const;
// 手動勤務一覧を前後に移動する日数（6週間）。
const PERIOD_SHIFT_DAYS = 42;
// 直前予約のプリセット値（分）。
const IMMEDIATE_LEAD_MINUTES = 0;
const HALF_HOUR_LEAD_MINUTES = 30;
const ONE_HOUR_LEAD_MINUTES = 60;
const TWO_HOUR_LEAD_MINUTES = 120;
// 翌月分を開放できる最大日。
const MAX_MONTHLY_RELEASE_DAY = 28;
// 率を百分率へ変換する倍率。
const PERCENT_SCALE = 100;
// 時刻計算に使う1時間の分数と1日の時間数。
const MINUTES_PER_HOUR = 60;
const HOURS_PER_DAY = 24;

interface StaffOption {
    user_id: number;
    display_name: string;
    is_bookable: boolean;
}

interface TemplateEntry {
    id: number;
    weekday: number;
    start_at: string;
    end_at: string;
    is_active: boolean;
}

interface ExceptionEntry {
    id: number;
    exception_date: string;
    is_off: boolean;
    note: string | null;
}

interface ShiftEntry {
    id: number;
    staff_id: number;
    staff_display_name: string;
    work_date: string;
    start_at: string;
    end_at: string;
    origin: string;
}

interface AttendanceBreak { start_at: string; end_at: string; type: string; note: string | null }
interface TimesheetFlag { type: 'late_start' | 'early_start' | 'overtime' | 'early_leave'; minutes: number }
interface TimesheetRow {
    date: string;
    planned: { start: string; end: string; work_min: number; break_min: number; breaks: { start: string; end: string }[] } | null;
    attendance: {
        id: number; status: 'draft' | 'confirmed'; note: string | null; clock_in: string | null; clock_out: string | null;
        work_min: number | null; break_min: number; breaks: { start: string; end: string; type: string }[];
    } | null;
    flags: TimesheetFlag[];
    overtime_min: number;
    booked_count: number;
    booked_min: number;
    available_min: number;
    utilization: number | null;
}

interface BookingSettings {
    horizon_mode: 'none' | 'monthly' | 'rolling';
    horizon_days: number;
    release_day_of_month: number;
    min_lead_minutes: number;
    closed_dates: string[];
    closed_weekdays?: number[];
    enforced: boolean;
    last_bookable_date: string | null;
}

const props = defineProps<{
    staff: StaffOption[];
    selected_staff_id: number | null;
    templates: TemplateEntry[];
    exceptions: ExceptionEntry[];
    shifts: ShiftEntry[];
    timesheet: TimesheetRow[];
    booking: BookingSettings;
    filters: { staff_id: number | null; from: string; to: string };
}>();

type ShiftTab = 'basic' | 'exceptions' | 'attendance' | 'store';
const SHIFT_TABS: ShiftTab[] = ['basic', 'exceptions', 'attendance', 'store'];
// 開いているタブは URL に残す（期間の前後移動やスタッフ切替で画面を読み直しても、同じタブのままにする）。
const initialTab = ((): ShiftTab => {
    const value = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab');

    return SHIFT_TABS.includes(value as ShiftTab) ? (value as ShiftTab) : 'basic';
})();
const tab = ref<ShiftTab>(initialTab);
watch(tab, (value) => {
    if (typeof window === 'undefined') {
        return;
    }
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
});
const isStoreTab = computed(() => tab.value === 'store');
const page = usePage();
const canManageSettings = computed(() => page.props.auth?.can?.settingsManage === true);

const attendanceId = ref<number | null>(null);
const attendanceForm = useForm<{
    staff_id: number | null; business_date: string; clock_in_at: string; clock_out_at: string;
    status: 'draft' | 'confirmed'; note: string; breaks: { start_at: string; end_at: string; type: string; note: string }[];
}>({ staff_id: props.selected_staff_id, business_date: '', clock_in_at: '', clock_out_at: '', status: 'draft', note: '', breaks: [] });
/**
 * 出退勤は「日付＋時刻」で入力する（以前は日時を1つの入力欄で打つ形で使いにくかった）。
 * 保存時にサーバーの形式（YYYY-MM-DDTHH:MM）へ組み立てる。出勤より前の時刻は翌日（深夜の退勤）とみなす。
 */
const attendanceTimes = reactive({ clockIn: '', clockOut: '' });
const attendanceBreaks = ref<{ start: string; end: string; type: string; note: string }[]>([]);
const timeOf = (value: string | null): string => (value ? value.slice(11, 16) : '');
const nextDate = (iso: string): string => {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(y, m - 1, d + 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
const toDateTime = (date: string, time: string, after: string): string => {
    if (!date || !time) {
        return '';
    }

    return `${after !== '' && time < after ? nextDate(date) : date}T${time}`;
};
const editorOpen = ref(false);
const editorRow = ref<TimesheetRow | null>(null);

/** 行の「記録・編集」。記録がある日はその内容、無い日は予定（勤務枠・休憩）を初期値にして開く。 */
const openEditor = (row: TimesheetRow): void => {
    editorRow.value = row;
    attendanceForm.clearErrors();
    attendanceForm.staff_id = staffId.value;
    attendanceForm.business_date = row.date;
    if (row.attendance !== null) {
        attendanceId.value = row.attendance.id;
        attendanceTimes.clockIn = row.attendance.clock_in ?? '';
        attendanceTimes.clockOut = (row.attendance.clock_out ?? '').slice(-5);
        attendanceForm.status = row.attendance.status;
        attendanceForm.note = row.attendance.note ?? '';
        attendanceBreaks.value = row.attendance.breaks.map((b) => ({ start: b.start, end: b.end, type: b.type, note: '' }));
    } else {
        attendanceId.value = null;
        attendanceTimes.clockIn = row.planned?.start ?? '';
        attendanceTimes.clockOut = row.planned?.end ?? '';
        attendanceForm.status = 'confirmed';
        attendanceForm.note = '';
        attendanceBreaks.value = (row.planned?.breaks ?? []).map((b) => ({ start: b.start, end: b.end, type: 'break', note: '' }));
    }
    editorOpen.value = true;
};
const hhmmToMin = (value: string): number => {
    const [h, m] = value.split(':').map(Number);

    return h * MINUTES_PER_HOUR + m;
};
const minToHhmm = (total: number): string => {
    const clamped = Math.min(Math.max(total, 0), HOURS_PER_DAY * MINUTES_PER_HOUR - 1);

    return `${String(Math.floor(clamped / MINUTES_PER_HOUR)).padStart(2, '0')}:${String(clamped % MINUTES_PER_HOUR).padStart(2, '0')}`;
};
/** 出勤・退勤を ±分ずらす（遅出・早出・残業・早退を数字で素早く入れる）。 */
const nudge = (field: 'clockIn' | 'clockOut', delta: number): void => {
    const current = attendanceTimes[field];
    if (current === '') {
        return;
    }
    attendanceTimes[field] = minToHhmm(hhmmToMin(current) + delta);
};
const addAttendanceBreak = (): void => {
    attendanceBreaks.value.push({ start: '', end: '', type: 'break', note: '' });
};
/** 予定どおりに出勤・退勤した日は、ワンタップで記録する。 */
const recordAsPlanned = (row: TimesheetRow): void => {
    if (row.planned === null || staffId.value === null) {
        return;
    }
    const day = row.date;
    router.post('/admin/staff-shifts/attendances', {
        staff_id: staffId.value,
        business_date: day,
        clock_in_at: `${day}T${row.planned.start}`,
        clock_out_at: `${day}T${row.planned.end}`,
        status: 'confirmed',
        note: '',
        breaks: row.planned.breaks.map((b) => ({ start_at: `${day}T${b.start}`, end_at: `${day}T${b.end}`, type: 'break', note: '' })),
    }, { preserveScroll: true });
};
const flagLabel = (flag: TimesheetFlag): string => `${MESSAGES.attendance.flags[flag.type]} ${fillMessage(MESSAGES.mastersUi.staffShifts.minutesValue, { minutes: String(flag.minutes) })}`;
const minutesLabel = (value: number): string => fillMessage(MESSAGES.mastersUi.staffShifts.hoursMinutesValue, { hours: String(Math.floor(value / MINUTES_PER_HOUR)), minutes: String(value % MINUTES_PER_HOUR).padStart(2, '0') });
const weekdayLabel = (iso: string): string => MESSAGES.mastersUi.staffShifts.weekdaysSundayFirst[new Date(`${iso}T00:00:00`).getDay()];
const timesheetTotals = computed(() => props.timesheet.reduce((sum, row) => ({
    planned: sum.planned + Math.max((row.planned?.work_min ?? 0) - (row.planned?.break_min ?? 0), 0),
    actual: sum.actual + (row.attendance?.work_min ?? 0),
    overtime: sum.overtime + row.overtime_min,
    booked: sum.booked + row.booked_min,
    available: sum.available + row.available_min,
}), { planned: 0, actual: 0, overtime: 0, booked: 0, available: 0 }));
const saveAttendance = (): void => {
    const date = attendanceForm.business_date;
    const clockIn = attendanceTimes.clockIn;
    attendanceForm.clock_in_at = toDateTime(date, clockIn, '');
    attendanceForm.clock_out_at = toDateTime(date, attendanceTimes.clockOut, clockIn);
    attendanceForm.breaks = attendanceBreaks.value.map((entry) => ({
        start_at: toDateTime(date, entry.start, clockIn),
        end_at: toDateTime(date, entry.end, clockIn),
        type: entry.type,
        note: entry.note,
    }));
    attendanceForm.staff_id = staffId.value;
    if (attendanceId.value === null) {
        attendanceForm.post('/admin/staff-shifts/attendances', { preserveScroll: true, onSuccess: () => { editorOpen.value = false; } });
    } else {
        attendanceForm.put(`/admin/staff-shifts/attendances/${attendanceId.value}`, { preserveScroll: true, onSuccess: () => { editorOpen.value = false; } });
    }
};

// 月曜はじまりで表示（データ上は 0=日曜）。
const WEEKDAYS: { value: number; label: string }[] = [
    { value: 1, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[0] },
    { value: 2, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[1] },
    { value: 3, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[2] },
    { value: 4, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[3] },
    { value: 5, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[4] },
    { value: 6, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[5] },
    { value: 0, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[6] },
];

const staffId = ref<number | null>(props.selected_staff_id);

const selectStaff = (id: number | null): void => {
    router.get(
        '/admin/staff-shifts',
        { staff_id: id ?? undefined, from: props.filters.from, to: props.filters.to, tab: tab.value },
        { preserveState: false, preserveScroll: true },
    );
};

watch(staffId, (value) => {
    if (value !== props.selected_staff_id) {
        selectStaff(value);
    }
});

/* ───────────────────────── タブ1：基本シフト ───────────────────────── */

interface DayModel {
    working: boolean;
    ranges: { start: string; end: string }[];
}

const buildDayModels = (): Record<number, DayModel> => {
    const model: Record<number, DayModel> = {};

    for (const { value } of WEEKDAYS) {
        const ranges = props.templates
            .filter((t) => t.weekday === value)
            .map((t) => ({ start: t.start_at, end: t.end_at }));

        model[value] = {
            working: ranges.length > 0,
            ranges: ranges.length > 0 ? ranges : [{ start: '10:00', end: '19:00' }],
        };
    }

    return model;
};

const days = reactive<Record<number, DayModel>>(buildDayModels());

watch(
    () => props.templates,
    () => {
        const rebuilt = buildDayModels();
        for (const { value } of WEEKDAYS) {
            days[value] = rebuilt[value];
        }
    },
);

const toggleWorking = (weekday: number): void => {
    const day = days[weekday];
    day.working = !day.working;

    if (day.working && day.ranges.length === 0) {
        day.ranges.push({ start: '10:00', end: '19:00' });
    }
};

const addRange = (weekday: number): void => {
    days[weekday].ranges.push({ start: '10:00', end: '19:00' });
};

const removeRange = (weekday: number, index: number): void => {
    days[weekday].ranges.splice(index, 1);

    if (days[weekday].ranges.length === 0) {
        days[weekday].working = false;
    }
};

const templatesForm = useForm<{ staff_id: number | null; entries: { weekday: number; start_at: string; end_at: string }[] }>(
    { staff_id: props.selected_staff_id, entries: [] },
);

const templateErrors = computed(() => templatesForm.errors as Record<string, string>);

const saveTemplates = (): void => {
    const entries: { weekday: number; start_at: string; end_at: string }[] = [];

    for (const { value } of WEEKDAYS) {
        const day = days[value];
        if (!day.working) {
            continue;
        }
        for (const range of day.ranges) {
            entries.push({ weekday: value, start_at: range.start, end_at: range.end });
        }
    }

    templatesForm.staff_id = staffId.value;
    templatesForm.entries = entries;
    templatesForm.put('/admin/staff-shifts/templates', { preserveScroll: true, preserveState: true });
};

const generating = ref(false);

const generateNow = (): void => {
    generating.value = true;
    router.post(
        '/admin/staff-shifts/generate',
        { staff_id: staffId.value ?? undefined },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                generating.value = false;
            },
        },
    );
};

/* ───────────────────────── タブ2：例外日 ───────────────────────── */

const exceptionDate = ref('');

const weekdayOf = (iso: string): number => {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(y, m - 1, d).getDay();
};

const baseRangesFor = (iso: string): { start: string; end: string }[] => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return [];
    }

    return props.templates
        .filter((t) => t.weekday === weekdayOf(iso))
        .map((t) => ({ start: t.start_at, end: t.end_at }));
};

const shiftsOn = (iso: string): ShiftEntry[] =>
    props.shifts.filter((s) => s.work_date === iso && s.staff_id === staffId.value);

const exceptionOn = (iso: string): ExceptionEntry | undefined =>
    props.exceptions.find((e) => e.exception_date === iso);

const selectedBaseRanges = computed(() => baseRangesFor(exceptionDate.value));
const selectedDayShifts = computed(() => shiftsOn(exceptionDate.value));
const selectedException = computed(() => exceptionOn(exceptionDate.value));

const exceptionForm = useForm<{ staff_id: number | null; exception_date: string; is_off: boolean; note: string }>(
    { staff_id: null, exception_date: '', is_off: true, note: '' },
);

const addShiftForm = useForm<{ staff_id: number | null; work_date: string; start_at: string; end_at: string }>(
    { staff_id: null, work_date: '', start_at: '10:00', end_at: '17:00' },
);

const markDayOff = (): void => {
    if (!exceptionDate.value || staffId.value === null) {
        return;
    }
    exceptionForm.staff_id = staffId.value;
    exceptionForm.exception_date = exceptionDate.value;
    exceptionForm.is_off = true;
    exceptionForm.note = '';
    exceptionForm.post('/admin/staff-shifts/exceptions', { preserveScroll: true, preserveState: true });
};

const addCustomShift = (): void => {
    if (!exceptionDate.value || staffId.value === null) {
        return;
    }

    // 「時間変更で出勤」= 自動生成の対象外にした上で、その日の勤務枠を手動で追加する。
    addShiftForm.staff_id = staffId.value;
    addShiftForm.work_date = exceptionDate.value;
    addShiftForm.post('/admin/staff-shifts', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            router.post(
                '/admin/staff-shifts/exceptions',
                { staff_id: staffId.value, exception_date: exceptionDate.value, is_off: false, note: MESSAGES.mastersUi.staffShifts.timeChangeNote },
                { preserveScroll: true, preserveState: true },
            );
        },
    });
};

const clearException = (id: number): void => {
    router.delete(`/admin/staff-shifts/exceptions/${id}`, { preserveScroll: true, preserveState: true });
};

const deleteShift = (shift: ShiftEntry): void => {
    if (!window.confirm(confirmDeleteShiftMessage(`${shift.work_date} ${shift.start_at}–${shift.end_at}`))) {
        return;
    }
    router.delete(`/admin/staff-shifts/${shift.id}`, { preserveScroll: true, preserveState: true });
};

const periodShift = (deltaDays: number): void => {
    const move = (iso: string): string => {
        const [y, m, d] = iso.split('-').map(Number);
        const date = new Date(y, m - 1, d);
        date.setDate(date.getDate() + deltaDays);

        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    };

    router.get(
        '/admin/staff-shifts',
        { staff_id: staffId.value ?? undefined, from: move(props.filters.from), to: move(props.filters.to), tab: tab.value },
        { preserveState: false, preserveScroll: true },
    );
};

const upcomingManualShifts = computed(() =>
    props.shifts
        .filter((s) => s.staff_id === staffId.value && s.origin === 'manual')
        .slice()
        .sort((a, b) => (a.work_date + a.start_at).localeCompare(b.work_date + b.start_at)),
);

/* ───────────────────────── タブ3：予約受付 ───────────────────────── */

const bookingForm = useForm({
    horizon_mode: props.booking.horizon_mode,
    horizon_days: props.booking.horizon_days,
    release_day_of_month: props.booking.release_day_of_month,
    min_lead_minutes: props.booking.min_lead_minutes,
    closed_dates: [...props.booking.closed_dates],
});

const bookingErrors = computed(() => bookingForm.errors as Record<string, string>);

const leadPresets = [
    { value: IMMEDIATE_LEAD_MINUTES, title: MESSAGES.mastersUi.staffShifts.leadPresets.immediate },
    { value: HALF_HOUR_LEAD_MINUTES, title: MESSAGES.mastersUi.staffShifts.leadPresets.halfHour },
    { value: ONE_HOUR_LEAD_MINUTES, title: MESSAGES.mastersUi.staffShifts.leadPresets.oneHour },
    { value: TWO_HOUR_LEAD_MINUTES, title: MESSAGES.mastersUi.staffShifts.leadPresets.twoHours },
];

const leadIsPreset = computed(() => leadPresets.some((p) => p.value === bookingForm.min_lead_minutes));

const releaseDayItems = Array.from({ length: MAX_MONTHLY_RELEASE_DAY }, (_, i) => i + 1);

const newClosedDate = ref('');

const addClosedDate = (): void => {
    const value = newClosedDate.value;
    if (/^\d{4}-\d{2}-\d{2}$/.test(value) && !bookingForm.closed_dates.includes(value)) {
        bookingForm.closed_dates = [...bookingForm.closed_dates, value].sort();
    }
    newClosedDate.value = '';
};

const removeClosedDate = (value: string): void => {
    bookingForm.closed_dates = bookingForm.closed_dates.filter((d) => d !== value);
};

/** 毎週の定休日（業務マスタの店舗カレンダーと同じ設定）。 */
const closedWeekdaysForm = useForm({ weekdays: [...(props.booking.closed_weekdays ?? [])] });
const weekdayOptions = [
    { value: 1, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[0] }, { value: 2, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[1] },
    { value: 3, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[2] }, { value: 4, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[3] },
    { value: 5, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[4] }, { value: 6, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[5] },
    { value: 7, label: MESSAGES.mastersUi.staffShifts.weekdaysMondayFirst[6] },
];
const saveClosedWeekdays = (): void => {
    closedWeekdaysForm.put('/admin/settings/business-masters/calendar/closed-weekdays', { preserveScroll: true, preserveState: true });
};
const periodLabel = computed(() => `${props.filters.from.replace(/-/g, '/')} 〜 ${props.filters.to.replace(/-/g, '/')}`);

const saveBooking = (): void => {
    bookingForm.put('/admin/staff-shifts/booking', { preserveScroll: true, preserveState: true });
};

const formatJpDate = (iso: string | null): string => {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return MESSAGES.common.notSet;
    }
    const [y, m, d] = iso.split('-').map(Number);

    return fillMessage(MESSAGES.mastersUi.staffShifts.yearMonthDay, { year: String(y), month: String(m), day: String(d) });
};

const horizonSummary = computed(() => {
    if (bookingForm.horizon_mode === 'monthly') {
        return fillMessage(MESSAGES.mastersUi.staffShifts.monthlySummary, { day: String(bookingForm.release_day_of_month) });
    }
    if (bookingForm.horizon_mode === 'rolling') {
        return fillMessage(MESSAGES.mastersUi.staffShifts.rollingSummary, { days: String(bookingForm.horizon_days) });
    }

    return MESSAGES.mastersUi.staffShifts.unlimitedSummary;
});
</script>

<template>
    <Head :title="MESSAGES.mastersUi.staffShifts.title" />

    <PageHeader
        :title="MESSAGES.mastersUi.staffShifts.title"
        :subtitle="MESSAGES.mastersUi.staffShifts.subtitle"
    >
        <template #actions>
            <v-btn
                variant="outlined"
                color="primary"
                prepend-icon="mdi-calendar-month-outline"
                href="/admin/schedule"
            >
                {{ MESSAGES.mastersUi.staffShifts.bookingBoard }}
            </v-btn>
        </template>
    </PageHeader>

    <!-- スタッフ個人の設定と、店舗全体の設定を分けて見せる。2 つのタブ列で同じ値を共有するため、
         選択の強制（mandatory）を切る（切らないと片方の列が自分のタブを選び直してしまう）。 -->
    <div class="ark-shift-tabs mb-5">
        <div class="ark-shift-tabs__group">
            <span class="ark-shift-tabs__label"><v-icon icon="mdi-account-outline" size="16" />{{ MESSAGES.mastersUi.staffShifts.perStaff }}</span>
            <v-tabs v-model="tab" color="primary" density="comfortable" :mandatory="false">
                <v-tab value="basic">{{ MESSAGES.mastersUi.staffShifts.basicTab }}</v-tab>
                <v-tab value="exceptions">{{ MESSAGES.mastersUi.staffShifts.exceptionsTab }}</v-tab>
                <v-tab value="attendance">{{ MESSAGES.attendance.tab }}</v-tab>
            </v-tabs>
        </div>
        <div class="ark-shift-tabs__group">
            <span class="ark-shift-tabs__label"><v-icon icon="mdi-store-outline" size="16" />{{ MESSAGES.mastersUi.staffShifts.wholeStore }}</span>
            <v-tabs v-model="tab" color="primary" density="comfortable" :mandatory="false">
                <v-tab value="store">{{ MESSAGES.mastersUi.staffShifts.storeTab }}</v-tab>
            </v-tabs>
        </div>
    </div>

    <!-- スタッフ選択（スタッフごとのタブで共通） -->
    <div v-if="!isStoreTab" class="mb-4" style="max-width: 320px">
        <v-select
            v-model="staffId"
            :label="MESSAGES.mastersUi.staffShifts.staff"
            :items="staff"
            item-title="display_name"
            item-value="user_id"
            hide-details
        >
            <template #item="{ props: itemProps, item }">
                <v-list-item
                    v-bind="itemProps"
                    :subtitle="item.raw.is_bookable ? undefined : MESSAGES.mastersUi.staffShifts.bookingStopped"
                />
            </template>
        </v-select>
    </div>

    <v-window v-model="tab">
        <v-window-item value="attendance">
            <SectionCard :title="MESSAGES.attendance.title" :subtitle="MESSAGES.attendance.subtitle">
                <v-alert type="info" variant="tonal" density="compact" class="mb-4">{{ MESSAGES.attendance.help }}</v-alert>
                <p v-if="staffId === null">{{ MESSAGES.attendance.selectStaff }}</p>
                <template v-else>
                    <!-- 期間の合計：予定の実働／実績の実働／残業／稼働率 -->
                    <div class="ts-totals" data-testid="timesheet-totals">
                        <div class="ts-total"><small>{{ MESSAGES.attendance.totalPlanned }}</small><strong>{{ minutesLabel(timesheetTotals.planned) }}</strong></div>
                        <div class="ts-total"><small>{{ MESSAGES.attendance.totalActual }}</small><strong>{{ minutesLabel(timesheetTotals.actual) }}</strong></div>
                        <div class="ts-total"><small>{{ MESSAGES.attendance.totalOvertime }}</small><strong>{{ minutesLabel(timesheetTotals.overtime) }}</strong></div>
                        <div class="ts-total"><small>{{ MESSAGES.attendance.totalUtilization }}</small><strong>{{ timesheetTotals.available > 0 ? Math.round(timesheetTotals.booked / timesheetTotals.available * PERCENT_SCALE) : MESSAGES.common.emptyValue }}<template v-if="timesheetTotals.available > 0">%</template></strong></div>
                    </div>
                    <p v-if="timesheet.length === 0" class="text-medium-emphasis">{{ MESSAGES.attendance.none }}</p>
                    <v-table v-else density="comfortable" class="ts-table">
                        <thead>
                            <tr>
                                <th>{{ MESSAGES.attendance.date }}</th>
                                <th>{{ MESSAGES.attendance.planned }}</th>
                                <th>{{ MESSAGES.attendance.actual }}</th>
                                <th>{{ MESSAGES.attendance.diff }}</th>
                                <th class="text-right">{{ MESSAGES.attendance.utilization }}</th>
                                <th class="text-right">{{ MESSAGES.attendance.operation }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in timesheet" :key="row.date" :data-testid="`ts-row-${row.date}`">
                                <td class="ts-date">{{ row.date.slice(5).replace('-', '/') }}<small>（{{ weekdayLabel(row.date) }}）</small></td>
                                <td>
                                    <template v-if="row.planned">
                                        <strong>{{ row.planned.start }}〜{{ row.planned.end }}</strong>
                                        <small class="ts-sub">{{ MESSAGES.attendance.breakLabel }} {{ fillMessage(MESSAGES.mastersUi.staffShifts.minutesValue, { minutes: String(row.planned.break_min) }) }} ・ {{ MESSAGES.mastersUi.staffShifts.actualWork }} {{ minutesLabel(Math.max(row.planned.work_min - row.planned.break_min, 0)) }}</small>
                                    </template>
                                    <span v-else class="text-medium-emphasis">{{ MESSAGES.attendance.noShift }}</span>
                                </td>
                                <td>
                                    <template v-if="row.attendance">
                                        <strong>{{ row.attendance.clock_in ?? MESSAGES.common.notRecorded }}〜{{ row.attendance.clock_out ? row.attendance.clock_out.slice(-5) : MESSAGES.common.notRecorded }}</strong>
                                        <small class="ts-sub">{{ MESSAGES.attendance.breakLabel }} {{ fillMessage(MESSAGES.mastersUi.staffShifts.minutesValue, { minutes: String(row.attendance.break_min) }) }}<template v-if="row.attendance.work_min !== null"> ・ {{ MESSAGES.mastersUi.staffShifts.actualWork }} {{ minutesLabel(row.attendance.work_min) }}</template>
                                            <span v-if="row.attendance.status === 'draft'" class="ts-draft">{{ MESSAGES.attendance.draft }}</span></small>
                                    </template>
                                    <span v-else class="text-medium-emphasis">{{ MESSAGES.attendance.notRecorded }}</span>
                                </td>
                                <td>
                                    <span v-for="flag in row.flags" :key="flag.type" class="ts-flag" :class="`ts-flag--${flag.type}`">{{ flagLabel(flag) }}</span>
                                    <span v-if="row.attendance && row.flags.length === 0" class="ts-flag ts-flag--ok">{{ MESSAGES.attendance.asPlanned }}</span>
                                </td>
                                <td class="text-right">
                                    <template v-if="row.utilization !== null">
                                        <strong>{{ row.utilization }}%</strong>
                                        <small class="ts-sub">{{ fillMessage(MESSAGES.mastersUi.staffShifts.bookedSummary, { count: String(row.booked_count), minutes: String(row.booked_min) }) }}</small>
                                    </template>
                                    <span v-else class="text-medium-emphasis">-</span>
                                </td>
                                <td class="text-right ts-actions">
                                    <v-btn v-if="!row.attendance && row.planned" size="small" variant="text" color="primary" prepend-icon="mdi-check" @click="recordAsPlanned(row)">{{ MESSAGES.attendance.recordAsPlanned }}</v-btn>
                                    <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline" @click="openEditor(row)">{{ row.attendance ? MESSAGES.attendance.edit : MESSAGES.attendance.record }}</v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </template>
            </SectionCard>

            <v-dialog v-model="editorOpen" max-width="560">
                <v-card v-if="editorRow">
                    <v-card-title>{{ editorRow.date }}（{{ weekdayLabel(editorRow.date) }}） {{ MESSAGES.attendance.editorTitle }}</v-card-title>
                    <v-card-text>
                        <p v-if="editorRow.planned" class="ts-plan">{{ MESSAGES.attendance.planned }}：{{ editorRow.planned.start }}〜{{ editorRow.planned.end }}（{{ MESSAGES.attendance.breakLabel }} {{ fillMessage(MESSAGES.mastersUi.staffShifts.minutesValue, { minutes: String(editorRow.planned.break_min) }) }}）</p>
                        <div class="ark-att__row">
                            <TimeField v-model="attendanceTimes.clockIn" :label="MESSAGES.attendance.clockIn" :step-minutes="TIME_STEP_MINUTES" />
                            <span class="ark-weekgrid__sep">〜</span>
                            <TimeField v-model="attendanceTimes.clockOut" :label="MESSAGES.attendance.clockOut" :step-minutes="TIME_STEP_MINUTES" clearable />
                        </div>
                        <!-- 遅出・早出・残業・早退を数字で素早く調整 -->
                        <div class="ts-nudge">
                            <span class="ts-nudge__label">{{ MESSAGES.attendance.clockIn }}</span>
                            <v-btn v-for="d in CLOCK_IN_NUDGE_MINUTES" :key="`in${d}`" size="x-small" variant="outlined" @click="nudge('clockIn', d)">{{ d > 0 ? '+' : '' }}{{ d }}</v-btn>
                            <span class="ts-nudge__hint">{{ MESSAGES.attendance.nudgeInHint }}</span>
                        </div>
                        <div class="ts-nudge">
                            <span class="ts-nudge__label">{{ MESSAGES.attendance.clockOut }}</span>
                            <v-btn v-for="d in CLOCK_OUT_NUDGE_MINUTES" :key="`out${d}`" size="x-small" variant="outlined" @click="nudge('clockOut', d)">{{ d > 0 ? '+' : '' }}{{ d }}</v-btn>
                            <span class="ts-nudge__hint">{{ MESSAGES.attendance.nudgeOutHint }}</span>
                        </div>
                        <div v-for="(entry, index) in attendanceBreaks" :key="index" class="ark-att__row">
                            <span class="ark-att__breaklabel">{{ MESSAGES.attendance.breakLabel }}{{ index + 1 }}</span>
                            <TimeField v-model="entry.start" :label="MESSAGES.attendance.breakStart" :step-minutes="TIME_STEP_MINUTES" />
                            <span class="ark-weekgrid__sep">〜</span>
                            <TimeField v-model="entry.end" :label="MESSAGES.attendance.breakEnd" :step-minutes="TIME_STEP_MINUTES" />
                            <v-btn variant="text" color="error" size="small" prepend-icon="mdi-close" @click="attendanceBreaks.splice(index, 1)">{{ MESSAGES.attendance.removeBreak }}</v-btn>
                        </div>
                        <v-btn variant="text" size="small" prepend-icon="mdi-plus" class="mb-3" @click="addAttendanceBreak">{{ MESSAGES.attendance.addBreak }}</v-btn>
                        <div class="ark-att__row">
                            <v-select v-model="attendanceForm.status" :label="MESSAGES.attendance.statusLabel" :items="[{ title: MESSAGES.attendance.draft, value: 'draft' }, { title: MESSAGES.attendance.confirmed, value: 'confirmed' }]" hide-details class="ark-att__status" />
                            <v-text-field v-model="attendanceForm.note" :label="MESSAGES.attendance.note" hide-details class="ark-att__note" />
                        </div>
                        <p v-if="Object.keys(attendanceForm.errors).length" role="alert" class="text-error text-body-2">{{ MESSAGES.attendance.formError }} {{ Object.values(attendanceForm.errors).join(' / ') }}</p>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="editorOpen = false">{{ MESSAGES.checkout.close }}</v-btn>
                        <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="attendanceForm.processing" @click="saveAttendance">{{ MESSAGES.attendance.save }}</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-window-item>
        <!-- ───────── タブ1：基本シフト ───────── -->
        <v-window-item value="basic">
            <SectionCard
                :title="MESSAGES.mastersUi.staffShifts.basicTitle"
                :subtitle="MESSAGES.mastersUi.staffShifts.basicSubtitle"
            >
                <p v-if="staffId === null" class="text-medium-emphasis">
                    {{ MESSAGES.staff.selectStaff }}
                </p>

                <div v-else class="ark-weekgrid">
                    <div
                        v-for="wd in WEEKDAYS"
                        :key="wd.value"
                        class="ark-weekgrid__day"
                        :class="{ 'ark-weekgrid__day--off': !days[wd.value].working }"
                    >
                        <div class="ark-weekgrid__head">
                            <span class="ark-weekgrid__label">{{ wd.label }}</span>
                            <v-btn
                                :variant="days[wd.value].working ? 'tonal' : 'text'"
                                :color="days[wd.value].working ? 'primary' : undefined"
                                size="small"
                                @click="toggleWorking(wd.value)"
                            >
                                {{ days[wd.value].working ? MESSAGES.mastersUi.staffShifts.working : MESSAGES.mastersUi.staffShifts.off }}
                            </v-btn>
                        </div>

                        <template v-if="days[wd.value].working">
                            <div
                                v-for="(range, index) in days[wd.value].ranges"
                                :key="index"
                                class="ark-weekgrid__range"
                            >
                                <TimeField v-model="range.start" :label="MESSAGES.mastersUi.staffShifts.start" :step-minutes="TIME_STEP_MINUTES" class="ark-weekgrid__time" />
                                <span class="ark-weekgrid__sep">〜</span>
                                <TimeField v-model="range.end" :label="MESSAGES.mastersUi.staffShifts.end" :step-minutes="TIME_STEP_MINUTES" class="ark-weekgrid__time" />
                                <v-btn
                                    icon="mdi-close"
                                    size="x-small"
                                    variant="text"
                                    :aria-label="fillMessage(MESSAGES.mastersUi.staffShifts.removeTimeRangeAria, { weekday: wd.label })"
                                    @click="removeRange(wd.value, index)"
                                />
                            </div>
                            <v-btn
                                variant="text"
                                size="small"
                                prepend-icon="mdi-plus"
                                @click="addRange(wd.value)"
                            >
                                {{ MESSAGES.mastersUi.staffShifts.addTimeRange }}
                            </v-btn>
                        </template>
                        <p v-else class="ark-weekgrid__offlabel">{{ MESSAGES.mastersUi.staffShifts.off }}</p>
                    </div>
                </div>

                <p
                    v-if="templateErrors.entries || Object.keys(templateErrors).some((k) => k.startsWith('entries.'))"
                    class="text-error text-body-2 mt-3"
                >
                    {{ templateErrors.entries ?? MESSAGES.mastersUi.staffShifts.invalidTimeRanges }}
                </p>

                <div v-if="staffId !== null" class="d-flex flex-wrap ga-3 mt-5">
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="templatesForm.processing"
                        @click="saveTemplates"
                    >
                        {{ MESSAGES.mastersUi.staffShifts.saveBasic }}
                    </v-btn>
                    <v-btn
                        variant="outlined"
                        color="primary"
                        prepend-icon="mdi-calendar-sync-outline"
                        :loading="generating"
                        @click="generateNow"
                    >
                        {{ MESSAGES.mastersUi.staffShifts.generateNow }}
                    </v-btn>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- ───────── タブ2：例外日 ───────── -->
        <v-window-item value="exceptions">
            <div class="ark-exceptions">
                <SectionCard
                    :title="MESSAGES.mastersUi.staffShifts.exceptionsTitle"
                    :subtitle="MESSAGES.mastersUi.staffShifts.exceptionsSubtitle"
                >
                    <p v-if="staffId === null" class="text-medium-emphasis">
                        {{ MESSAGES.staff.selectStaff }}
                    </p>

                    <template v-else>
                        <div style="max-width: 280px">
                            <DateField v-model="exceptionDate" :label="MESSAGES.mastersUi.staffShifts.selectDate" :clearable="false" />
                        </div>

                        <div v-if="exceptionDate" class="ark-exceptions__detail mt-4">
                            <div class="ark-exceptions__row">
                                <span class="ark-exceptions__key">{{ MESSAGES.mastersUi.staffShifts.regularShift }}</span>
                                <span v-if="selectedBaseRanges.length === 0" class="text-medium-emphasis">{{ MESSAGES.mastersUi.staffShifts.noBasicShift }}</span>
                                <span v-else>
                                    {{ selectedBaseRanges.map((r) => `${r.start}〜${r.end}`).join(' / ') }}
                                </span>
                            </div>
                            <div class="ark-exceptions__row">
                                <span class="ark-exceptions__key">{{ MESSAGES.mastersUi.staffShifts.shiftsOnDate }}</span>
                                <span v-if="selectedDayShifts.length === 0" class="text-medium-emphasis">{{ MESSAGES.mastersUi.staffShifts.none }}</span>
                                <span v-else class="d-flex flex-wrap ga-2">
                                    <v-chip
                                        v-for="s in selectedDayShifts"
                                        :key="s.id"
                                        size="small"
                                        :color="s.origin === 'manual' ? 'primary' : undefined"
                                        variant="tonal"
                                        closable
                                        @click:close="deleteShift(s)"
                                    >
                                        {{ s.start_at }}〜{{ s.end_at }}
                                        <span v-if="s.origin === 'manual'" class="ml-1 text-caption">{{ MESSAGES.mastersUi.staffShifts.individual }}</span>
                                    </v-chip>
                                </span>
                            </div>
                            <div v-if="selectedException" class="ark-exceptions__row">
                                <span class="ark-exceptions__key">{{ MESSAGES.mastersUi.staffShifts.currentSetting }}</span>
                                <v-chip size="small" :color="selectedException.is_off ? 'error' : 'warning'" variant="tonal">
                                    {{ selectedException.is_off ? MESSAGES.mastersUi.staffShifts.configuredOff : MESSAGES.mastersUi.staffShifts.configuredTimeChange }}
                                </v-chip>
                                <v-btn size="small" variant="text" @click="clearException(selectedException.id)">
                                    {{ MESSAGES.mastersUi.staffShifts.backToRegular }}
                                </v-btn>
                            </div>

                            <div class="ark-exceptions__actions mt-3">
                                <v-btn
                                    color="error"
                                    variant="tonal"
                                    prepend-icon="mdi-cancel"
                                    @click="markDayOff"
                                >
                                    {{ MESSAGES.mastersUi.staffShifts.markOff }}
                                </v-btn>

                                <div class="ark-exceptions__addshift">
                                    <TimeField v-model="addShiftForm.start_at" :label="MESSAGES.mastersUi.staffShifts.start" :step-minutes="TIME_STEP_MINUTES" class="ark-weekgrid__time" />
                                    <span class="ark-weekgrid__sep">〜</span>
                                    <TimeField v-model="addShiftForm.end_at" :label="MESSAGES.mastersUi.staffShifts.end" :step-minutes="TIME_STEP_MINUTES" class="ark-weekgrid__time" />
                                    <v-btn
                                        color="primary"
                                        variant="tonal"
                                        prepend-icon="mdi-plus"
                                        :loading="addShiftForm.processing"
                                        @click="addCustomShift"
                                    >
                                        {{ MESSAGES.mastersUi.staffShifts.addCustomShift }}
                                    </v-btn>
                                </div>
                                <p
                                    v-if="addShiftForm.errors.start_at || addShiftForm.errors.end_at"
                                    class="text-error text-body-2"
                                >
                                    {{ addShiftForm.errors.start_at ?? addShiftForm.errors.end_at }}
                                </p>
                            </div>
                        </div>
                    </template>
                </SectionCard>

                <SectionCard :title="MESSAGES.mastersUi.staffShifts.registeredExceptions" :subtitle="MESSAGES.mastersUi.staffShifts.registeredExceptionsSubtitle" class="mt-5">
                    <p v-if="exceptions.length === 0" class="text-medium-emphasis">
                        {{ MESSAGES.shift.noUpcomingExceptions }}
                    </p>
                    <v-table v-else density="comfortable">
                        <thead>
                            <tr>
                                <th>{{ MESSAGES.mastersUi.staffShifts.date }}</th>
                                <th>{{ MESSAGES.mastersUi.staffShifts.content }}</th>
                                <th>{{ MESSAGES.mastersUi.staffShifts.note }}</th>
                                <th class="text-right">{{ MESSAGES.mastersUi.staffShifts.operation }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in exceptions" :key="e.id">
                                <td>{{ e.exception_date }}</td>
                                <td>
                                    <v-chip size="small" :color="e.is_off ? 'error' : 'warning'" variant="tonal">
                                        {{ e.is_off ? MESSAGES.mastersUi.staffShifts.off : MESSAGES.mastersUi.staffShifts.timeChange }}
                                    </v-chip>
                                </td>
                                <td class="text-medium-emphasis"><template v-if="e.note">{{ e.note }}</template><EmptyValue v-else :label="MESSAGES.common.notEntered" /></td>
                                <td class="text-right">
                                    <v-btn size="small" variant="tonal" @click="clearException(e.id)">
                                        {{ MESSAGES.mastersUi.staffShifts.restoreRegular }}
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </SectionCard>

                <SectionCard :title="MESSAGES.mastersUi.staffShifts.manualShifts" :subtitle="MESSAGES.mastersUi.staffShifts.manualShiftsSubtitle" class="mt-5">
                    <div class="ark-period mb-2">
                        <v-btn size="small" variant="outlined" prepend-icon="mdi-chevron-left" @click="periodShift(-PERIOD_SHIFT_DAYS)">{{ MESSAGES.mastersUi.staffShifts.previousSixWeeks }}</v-btn>
                        <span class="ark-period__label">{{ periodLabel }}</span>
                        <v-btn size="small" variant="outlined" append-icon="mdi-chevron-right" @click="periodShift(PERIOD_SHIFT_DAYS)">{{ MESSAGES.mastersUi.staffShifts.nextSixWeeks }}</v-btn>
                    </div>
                    <p v-if="upcomingManualShifts.length === 0" class="text-medium-emphasis">
                        {{ MESSAGES.shift.noIndividualShifts }}
                    </p>
                    <v-table v-else density="comfortable">
                        <tbody>
                            <tr v-for="s in upcomingManualShifts" :key="s.id">
                                <td>{{ s.work_date }}</td>
                                <td>{{ s.start_at }}〜{{ s.end_at }}</td>
                                <td class="text-right">
                                    <v-btn size="small" variant="text" color="error" @click="deleteShift(s)">
                                        {{ MESSAGES.mastersUi.staffShifts.delete }}
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </SectionCard>
            </div>
        </v-window-item>

        <!-- ───────── 店舗全体：休業日・予約受付 ───────── -->
        <v-window-item value="store">
            <SectionCard :title="MESSAGES.mastersUi.staffShifts.regularHolidayTitle" :subtitle="MESSAGES.mastersUi.staffShifts.regularHolidaySubtitle" class="mb-5">
                <template v-if="canManageSettings">
                    <div class="d-flex flex-wrap align-center ga-3">
                        <v-chip-group v-model="closedWeekdaysForm.weekdays" multiple column :aria-label="MESSAGES.mastersUi.staffShifts.regularHolidayAria">
                            <v-chip v-for="day in weekdayOptions" :key="day.value" :value="day.value" filter variant="outlined" selected-class="ark-closed-on">{{ day.label }}</v-chip>
                        </v-chip-group>
                        <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="closedWeekdaysForm.processing" @click="saveClosedWeekdays">{{ MESSAGES.mastersUi.staffShifts.saveRegularHoliday }}</v-btn>
                    </div>
                </template>
                <p v-else class="text-medium-emphasis">{{ (booking.closed_weekdays ?? []).length === 0 ? MESSAGES.mastersUi.staffShifts.noRegularHoliday : weekdayOptions.filter((d) => (booking.closed_weekdays ?? []).includes(d.value)).map((d) => d.label).join('・') }}</p>
            </SectionCard>

            <SectionCard
                :title="MESSAGES.mastersUi.staffShifts.horizonTitle"
                :subtitle="MESSAGES.mastersUi.staffShifts.horizonSubtitle"
            >
                <v-radio-group v-model="bookingForm.horizon_mode" hide-details>
                    <v-radio value="monthly">
                        <template #label>
                            <div>
                                <div class="font-weight-medium">{{ MESSAGES.mastersUi.staffShifts.monthlyMode }}</div>
                                <div class="text-body-2 text-medium-emphasis">
                                    {{ MESSAGES.mastersUi.staffShifts.monthlyExample }}
                                </div>
                            </div>
                        </template>
                    </v-radio>
                    <div v-if="bookingForm.horizon_mode === 'monthly'" class="ark-booking__inset">
                        <v-select
                            v-model.number="bookingForm.release_day_of_month"
                            :items="releaseDayItems"
                            :label="MESSAGES.mastersUi.staffShifts.releaseDay"
                            hide-details
                            style="max-width: 200px"
                            :suffix="MESSAGES.mastersUi.staffShifts.daySuffix"
                        />
                    </div>

                    <v-radio value="rolling" class="mt-2">
                        <template #label>
                            <div>
                                <div class="font-weight-medium">{{ MESSAGES.mastersUi.staffShifts.rollingMode }}</div>
                                <div class="text-body-2 text-medium-emphasis">{{ MESSAGES.mastersUi.staffShifts.rollingDescription }}</div>
                            </div>
                        </template>
                    </v-radio>
                    <div v-if="bookingForm.horizon_mode === 'rolling'" class="ark-booking__inset">
                        <v-text-field
                            v-model.number="bookingForm.horizon_days"
                            type="number"
                            :label="MESSAGES.mastersUi.staffShifts.horizonDays"
                            hide-details
                            style="max-width: 200px"
                            :suffix="MESSAGES.mastersUi.staffShifts.daysAheadSuffix"
                        />
                    </div>

                    <v-radio value="none" class="mt-2">
                        <template #label>
                            <div>
                                <div class="font-weight-medium">{{ MESSAGES.mastersUi.staffShifts.unlimitedMode }}</div>
                                <div class="text-body-2 text-medium-emphasis">{{ MESSAGES.mastersUi.staffShifts.unlimitedDescription }}</div>
                            </div>
                        </template>
                    </v-radio>
                </v-radio-group>

                <v-alert type="info" variant="tonal" density="compact" class="mt-4">
                    {{ horizonSummary }}
                    <template v-if="booking.enforced && booking.last_bookable_date">
                        {{ fillMessage(MESSAGES.mastersUi.staffShifts.currentLimit, { date: formatJpDate(booking.last_bookable_date) }) }}
                    </template>
                </v-alert>
            </SectionCard>

            <SectionCard :title="MESSAGES.mastersUi.staffShifts.leadTitle" class="mt-5">
                <v-select
                    v-if="leadIsPreset"
                    v-model.number="bookingForm.min_lead_minutes"
                    :items="leadPresets"
                    item-title="title"
                    item-value="value"
                    :label="MESSAGES.mastersUi.staffShifts.leadSelect"
                    hide-details
                    style="max-width: 280px"
                />
                <div v-else class="d-flex align-center ga-2">
                    <v-text-field
                        v-model.number="bookingForm.min_lead_minutes"
                        type="number"
                        :label="MESSAGES.mastersUi.staffShifts.leadInput"
                        :suffix="MESSAGES.mastersUi.staffShifts.minutesBeforeSuffix"
                        hide-details
                        style="max-width: 200px"
                    />
                    <v-btn variant="text" size="small" @click="bookingForm.min_lead_minutes = IMMEDIATE_LEAD_MINUTES">
                        {{ MESSAGES.mastersUi.staffShifts.resetPreset }}
                    </v-btn>
                </div>
                <p class="text-body-2 text-medium-emphasis mt-2">
                    {{ bookingForm.min_lead_minutes === IMMEDIATE_LEAD_MINUTES
                        ? MESSAGES.mastersUi.staffShifts.immediateLeadSummary
                        : fillMessage(MESSAGES.mastersUi.staffShifts.leadSummary, { minutes: String(bookingForm.min_lead_minutes) }) }}
                </p>
            </SectionCard>

            <SectionCard
                :title="MESSAGES.mastersUi.staffShifts.temporaryClosureTitle"
                :subtitle="MESSAGES.mastersUi.staffShifts.temporaryClosureSubtitle"
                class="mt-5"
            >
                <div class="d-flex align-end ga-2" style="max-width: 360px">
                    <DateField v-model="newClosedDate" :label="MESSAGES.mastersUi.staffShifts.addClosure" :clearable="false" />
                    <v-btn color="primary" variant="tonal" :disabled="!newClosedDate" @click="addClosedDate">
                        {{ MESSAGES.mastersUi.staffShifts.add }}
                    </v-btn>
                </div>
                <div class="d-flex flex-wrap ga-2 mt-3">
                    <v-chip
                        v-for="d in bookingForm.closed_dates"
                        :key="d"
                        closable
                        variant="tonal"
                        @click:close="removeClosedDate(d)"
                    >
                        {{ d }}
                    </v-chip>
                    <span v-if="bookingForm.closed_dates.length === 0" class="text-medium-emphasis">
                        {{ MESSAGES.shift.noClosedDates }}
                    </span>
                </div>
            </SectionCard>

            <div class="mt-5">
                <v-btn color="primary" variant="flat" :loading="bookingForm.processing" @click="saveBooking">
                    {{ MESSAGES.mastersUi.staffShifts.saveBooking }}
                </v-btn>
                <span v-if="Object.keys(bookingErrors).length" class="text-error text-body-2 ml-3">
                    {{ MESSAGES.common.checkInput }}
                </span>
            </div>
        </v-window-item>
    </v-window>
</template>

<style scoped>
.ark-shift-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--ark-space-4);
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

.ark-shift-tabs__group {
    display: flex;
    flex-direction: column;
}

.ark-shift-tabs__label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding-left: var(--ark-space-3);
    font-size: 0.75rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.ark-weekgrid__time {
    flex: 1 1 0;
    /* 時計アイコン＋「10:00」が見切れない幅 */
    min-width: 118px;
}

.ark-att__title {
    margin: 0 0 var(--ark-space-3);
    font-weight: 700;
}

.ark-att__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-3);
    margin-bottom: var(--ark-space-3);
}

.ark-att__date {
    flex: 0 0 220px;
}

.ark-att__breaklabel {
    min-width: 60px;
    font-size: 0.8125rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.ark-att__status {
    flex: 0 0 180px;
}

.ark-att__note {
    flex: 1 1 280px;
    max-width: 480px;
}

.ark-period {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-3);
}

.ark-period__label {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.ark-closed-on {
    background: rgb(var(--v-theme-error));
    color: rgb(var(--v-theme-on-error));
    border-color: rgb(var(--v-theme-error));
}

.ark-weekgrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--ark-space-4);
}

.ark-weekgrid__day {
    border: 1px solid #d9dee5;
    border-radius: var(--ark-radius);
    padding: var(--ark-space-3);
}

.ark-weekgrid__day--off {
    background: rgba(var(--v-theme-on-surface), 0.03);
}

.ark-weekgrid__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--ark-space-2);
}

.ark-weekgrid__label {
    font-size: 1rem;
    font-weight: 700;
}

.ark-weekgrid__range {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: var(--ark-space-2);
}

.ark-weekgrid__sep {
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.ark-weekgrid__offlabel {
    color: rgba(var(--v-theme-on-surface), 0.5);
    margin: 0;
}

.ark-exceptions__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-2);
    padding: var(--ark-space-2) 0;
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.ark-exceptions__key {
    min-width: 120px;
    font-size: 0.8125rem;
    font-weight: 700;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.ark-exceptions__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-3);
}

.ark-exceptions__addshift {
    display: flex;
    align-items: center;
    gap: 6px;
}

.ark-booking__inset {
    padding: var(--ark-space-2) 0 var(--ark-space-3) 34px;
}

/* 勤怠一覧 */
.ts-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 16px; }
.ts-total { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border-radius: 10px; background: rgba(var(--v-theme-primary), 0.05); }
.ts-total small { font-size: 0.6875rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.6); }
.ts-total strong { font-size: 1.125rem; font-variant-numeric: tabular-nums; }
.ts-table td { vertical-align: top; padding-top: 10px; padding-bottom: 10px; }
.ts-date { white-space: nowrap; font-weight: 700; }
.ts-date small { margin-left: 2px; font-weight: 500; color: rgba(var(--v-theme-on-surface), 0.6); }
.ts-sub { display: block; margin-top: 2px; font-size: 0.6875rem; color: rgba(var(--v-theme-on-surface), 0.6); }
.ts-draft { margin-left: 6px; padding: 0 6px; border-radius: 999px; background: #fff4e0; color: #8a5300; }
.ts-flag { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 999px; font-size: 0.6875rem; font-weight: 700; }
.ts-flag--late_start, .ts-flag--early_leave { background: #fff4e0; color: #8a5300; }
.ts-flag--early_start, .ts-flag--overtime { background: #e8f0fe; color: #1a4bb8; }
.ts-flag--ok { background: #e6f4ea; color: #1e6b34; }
.ts-actions { white-space: nowrap; }
.ts-plan { margin: 0 0 12px; font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), 0.7); }
.ts-nudge { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 10px; }
.ts-nudge__label { min-width: 36px; font-size: 0.75rem; font-weight: 700; }
.ts-nudge__hint { font-size: 0.6875rem; color: rgba(var(--v-theme-on-surface), 0.55); }
</style>
