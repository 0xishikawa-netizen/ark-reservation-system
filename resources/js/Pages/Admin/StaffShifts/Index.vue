<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { DateField, PageHeader, SectionCard } from '@/components/ark';
import { MESSAGES, confirmDeleteShiftMessage } from '@/constants/messages';

defineOptions({ layout: AdminLayout });

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
interface AttendanceEntry {
    id: number; business_date: string; clock_in_at: string | null; clock_out_at: string | null;
    status: 'draft' | 'confirmed'; note: string | null; breaks: AttendanceBreak[];
}

interface BookingSettings {
    horizon_mode: 'none' | 'monthly' | 'rolling';
    horizon_days: number;
    release_day_of_month: number;
    min_lead_minutes: number;
    closed_dates: string[];
    enforced: boolean;
    last_bookable_date: string | null;
}

const props = defineProps<{
    staff: StaffOption[];
    selected_staff_id: number | null;
    templates: TemplateEntry[];
    exceptions: ExceptionEntry[];
    shifts: ShiftEntry[];
    attendances: AttendanceEntry[];
    booking: BookingSettings;
    filters: { staff_id: number | null; from: string; to: string };
}>();

const tab = ref<'basic' | 'exceptions' | 'booking' | 'attendance'>('basic');

const attendanceId = ref<number | null>(null);
const attendanceForm = useForm<{
    staff_id: number | null; business_date: string; clock_in_at: string; clock_out_at: string;
    status: 'draft' | 'confirmed'; note: string; breaks: { start_at: string; end_at: string; type: string; note: string }[];
}>({ staff_id: props.selected_staff_id, business_date: '', clock_in_at: '', clock_out_at: '', status: 'draft', note: '', breaks: [] });
const editAttendance = (entry: AttendanceEntry): void => {
    attendanceId.value = entry.id;
    attendanceForm.staff_id = staffId.value;
    attendanceForm.business_date = entry.business_date;
    attendanceForm.clock_in_at = entry.clock_in_at ?? '';
    attendanceForm.clock_out_at = entry.clock_out_at ?? '';
    attendanceForm.status = entry.status;
    attendanceForm.note = entry.note ?? '';
    attendanceForm.breaks = entry.breaks.map((value) => ({ ...value, note: value.note ?? '' }));
};
const newAttendance = (): void => {
    attendanceId.value = null;
    attendanceForm.reset();
    attendanceForm.staff_id = staffId.value;
};
const addAttendanceBreak = (): void => {
    attendanceForm.breaks.push({ start_at: '', end_at: '', type: 'break', note: '' });
};
const saveAttendance = (): void => {
    attendanceForm.staff_id = staffId.value;
    if (attendanceId.value === null) {
        attendanceForm.post('/admin/staff-shifts/attendances', { preserveScroll: true, onSuccess: newAttendance });
    } else {
        attendanceForm.put(`/admin/staff-shifts/attendances/${attendanceId.value}`, { preserveScroll: true });
    }
};

// 月曜はじまりで表示（データ上は 0=日曜）。
const WEEKDAYS: { value: number; label: string }[] = [
    { value: 1, label: '月' },
    { value: 2, label: '火' },
    { value: 3, label: '水' },
    { value: 4, label: '木' },
    { value: 5, label: '金' },
    { value: 6, label: '土' },
    { value: 0, label: '日' },
];

const staffId = ref<number | null>(props.selected_staff_id);

const selectStaff = (id: number | null): void => {
    router.get(
        '/admin/staff-shifts',
        { staff_id: id ?? undefined, from: props.filters.from, to: props.filters.to },
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
                { staff_id: staffId.value, exception_date: exceptionDate.value, is_off: false, note: '時間変更' },
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
        { staff_id: staffId.value ?? undefined, from: move(props.filters.from), to: move(props.filters.to) },
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
    { value: 0, title: '直前まで受付' },
    { value: 30, title: '30分前まで' },
    { value: 60, title: '1時間前まで' },
    { value: 120, title: '2時間前まで' },
];

const leadIsPreset = computed(() => leadPresets.some((p) => p.value === bookingForm.min_lead_minutes));

const releaseDayItems = Array.from({ length: 28 }, (_, i) => i + 1);

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

const saveBooking = (): void => {
    bookingForm.put('/admin/staff-shifts/booking', { preserveScroll: true, preserveState: true });
};

const formatJpDate = (iso: string | null): string => {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        return '—';
    }
    const [y, m, d] = iso.split('-').map(Number);

    return `${y}年${m}月${d}日`;
};

const horizonSummary = computed(() => {
    if (bookingForm.horizon_mode === 'monthly') {
        return `毎月${bookingForm.release_day_of_month}日に翌月末までを開放します。`;
    }
    if (bookingForm.horizon_mode === 'rolling') {
        return `今日から${bookingForm.horizon_days}日先まで予約できます。`;
    }

    return '予約可能期間の制限なし（全期間受付）。';
});
</script>

<template>
    <Head title="勤務枠" />

    <PageHeader
        title="勤務枠"
        subtitle="基本シフトを決めておき、例外日と予約受付ルールだけを調整します。"
    >
        <template #actions>
            <v-btn
                variant="outlined"
                color="primary"
                prepend-icon="mdi-calendar-month-outline"
                href="/admin/schedule"
            >
                ブッキングボード
            </v-btn>
        </template>
    </PageHeader>

    <v-tabs v-model="tab" color="primary" class="mb-5">
        <v-tab value="basic">基本シフト</v-tab>
        <v-tab value="exceptions">例外日</v-tab>
        <v-tab value="booking">予約受付</v-tab>
        <v-tab value="attendance">{{ MESSAGES.attendance.tab }}</v-tab>
    </v-tabs>

    <!-- スタッフ選択（基本シフト / 例外日で共通） -->
    <div v-if="tab !== 'booking'" class="mb-4" style="max-width: 320px">
        <v-select
            v-model="staffId"
            label="スタッフ"
            :items="staff"
            item-title="display_name"
            item-value="user_id"
            hide-details
            density="comfortable"
        >
            <template #item="{ props: itemProps, item }">
                <v-list-item
                    v-bind="itemProps"
                    :subtitle="item.raw.is_bookable ? undefined : '予約受付停止中'"
                />
            </template>
        </v-select>
    </div>

    <v-window v-model="tab">
        <v-window-item value="attendance">
            <SectionCard :title="MESSAGES.attendance.title" :subtitle="MESSAGES.attendance.subtitle">
                <p v-if="staffId === null">{{ MESSAGES.attendance.selectStaff }}</p>
                <template v-else>
                    <div class="d-flex flex-wrap ga-3 mb-3">
                        <v-text-field v-model="attendanceForm.business_date" type="date" :label="MESSAGES.attendance.date" style="max-width: 190px" />
                        <v-text-field v-model="attendanceForm.clock_in_at" type="datetime-local" :label="MESSAGES.attendance.clockIn" style="max-width: 240px" />
                        <v-text-field v-model="attendanceForm.clock_out_at" type="datetime-local" :label="MESSAGES.attendance.clockOut" style="max-width: 240px" />
                    </div>
                    <div v-for="(entry, index) in attendanceForm.breaks" :key="index" class="d-flex flex-wrap ga-3 align-center mb-2">
                        <v-text-field v-model="entry.start_at" type="datetime-local" :label="MESSAGES.attendance.breakStart" style="max-width: 240px" />
                        <v-text-field v-model="entry.end_at" type="datetime-local" :label="MESSAGES.attendance.breakEnd" style="max-width: 240px" />
                        <v-btn variant="text" color="error" @click="attendanceForm.breaks.splice(index, 1)">{{ MESSAGES.attendance.removeBreak }}</v-btn>
                    </div>
                    <v-btn variant="text" class="mb-3" @click="addAttendanceBreak">{{ MESSAGES.attendance.addBreak }}</v-btn>
                    <div class="d-flex flex-wrap ga-3">
                        <v-select v-model="attendanceForm.status" :items="[{ title: MESSAGES.attendance.draft, value: 'draft' }, { title: MESSAGES.attendance.confirmed, value: 'confirmed' }]" style="max-width: 180px" />
                        <v-text-field v-model="attendanceForm.note" :label="MESSAGES.attendance.note" style="max-width: 400px" />
                    </div>
                    <p v-if="Object.keys(attendanceForm.errors).length" role="alert" class="text-error">{{ MESSAGES.attendance.formError }} {{ Object.values(attendanceForm.errors).join(' / ') }}</p>
                    <div class="d-flex ga-3">
                        <v-btn color="primary" :loading="attendanceForm.processing" @click="saveAttendance">{{ MESSAGES.attendance.save }}</v-btn>
                        <v-btn variant="outlined" @click="newAttendance">{{ MESSAGES.attendance.new }}</v-btn>
                    </div>
                </template>
            </SectionCard>
            <SectionCard :title="MESSAGES.attendance.title" class="mt-5">
                <p v-if="attendances.length === 0">{{ MESSAGES.attendance.none }}</p>
                <v-table v-else>
                    <tbody>
                        <tr v-for="entry in attendances" :key="entry.id">
                            <td>{{ entry.business_date }}</td><td>{{ entry.clock_in_at ?? '—' }} 〜 {{ entry.clock_out_at ?? '—' }}</td>
                            <td>{{ entry.status === 'confirmed' ? MESSAGES.attendance.confirmed : MESSAGES.attendance.draft }}</td>
                            <td>{{ entry.note ?? '—' }}</td>
                            <td><v-btn variant="text" @click="editAttendance(entry)">{{ MESSAGES.attendance.edit }}</v-btn></td>
                        </tr>
                    </tbody>
                </v-table>
            </SectionCard>
        </v-window-item>
        <!-- ───────── タブ1：基本シフト ───────── -->
        <v-window-item value="basic">
            <SectionCard
                title="曜日ごとの通常勤務時間"
                subtitle="ここで決めた内容から、予約受付期間ぶんの勤務枠を毎朝自動で作成します。"
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
                                {{ days[wd.value].working ? '勤務' : '休み' }}
                            </v-btn>
                        </div>

                        <template v-if="days[wd.value].working">
                            <div
                                v-for="(range, index) in days[wd.value].ranges"
                                :key="index"
                                class="ark-weekgrid__range"
                            >
                                <v-text-field
                                    v-model="range.start"
                                    type="time"
                                    density="compact"
                                    hide-details
                                    variant="outlined"
                                />
                                <span class="ark-weekgrid__sep">〜</span>
                                <v-text-field
                                    v-model="range.end"
                                    type="time"
                                    density="compact"
                                    hide-details
                                    variant="outlined"
                                />
                                <v-btn
                                    icon="mdi-close"
                                    size="x-small"
                                    variant="text"
                                    :aria-label="`${wd.label}曜日の時間帯を削除`"
                                    @click="removeRange(wd.value, index)"
                                />
                            </div>
                            <v-btn
                                variant="text"
                                size="small"
                                prepend-icon="mdi-plus"
                                @click="addRange(wd.value)"
                            >
                                時間帯を追加
                            </v-btn>
                        </template>
                        <p v-else class="ark-weekgrid__offlabel">休み</p>
                    </div>
                </div>

                <p
                    v-if="templateErrors.entries || Object.keys(templateErrors).some((k) => k.startsWith('entries.'))"
                    class="text-error text-body-2 mt-3"
                >
                    {{ templateErrors.entries ?? '時間帯の設定を確認してください（重複・逆転がないか）。' }}
                </p>

                <div v-if="staffId !== null" class="d-flex flex-wrap ga-3 mt-5">
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="templatesForm.processing"
                        @click="saveTemplates"
                    >
                        基本シフトを保存
                    </v-btn>
                    <v-btn
                        variant="outlined"
                        color="primary"
                        prepend-icon="mdi-calendar-sync-outline"
                        :loading="generating"
                        @click="generateNow"
                    >
                        今すぐ勤務枠へ反映
                    </v-btn>
                </div>
            </SectionCard>
        </v-window-item>

        <!-- ───────── タブ2：例外日 ───────── -->
        <v-window-item value="exceptions">
            <div class="ark-exceptions">
                <SectionCard
                    title="特定の日だけ変更する"
                    subtitle="臨時休み・時間変更・追加出勤など、通常と違う日だけ設定します。"
                >
                    <p v-if="staffId === null" class="text-medium-emphasis">
                        {{ MESSAGES.staff.selectStaff }}
                    </p>

                    <template v-else>
                        <div style="max-width: 280px">
                            <DateField v-model="exceptionDate" label="日付を選ぶ" :clearable="false" />
                        </div>

                        <div v-if="exceptionDate" class="ark-exceptions__detail mt-4">
                            <div class="ark-exceptions__row">
                                <span class="ark-exceptions__key">通常シフト</span>
                                <span v-if="selectedBaseRanges.length === 0" class="text-medium-emphasis">休み（基本シフトなし）</span>
                                <span v-else>
                                    {{ selectedBaseRanges.map((r) => `${r.start}〜${r.end}`).join(' / ') }}
                                </span>
                            </div>
                            <div class="ark-exceptions__row">
                                <span class="ark-exceptions__key">この日の勤務枠</span>
                                <span v-if="selectedDayShifts.length === 0" class="text-medium-emphasis">なし</span>
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
                                        <span v-if="s.origin === 'manual'" class="ml-1 text-caption">個別</span>
                                    </v-chip>
                                </span>
                            </div>
                            <div v-if="selectedException" class="ark-exceptions__row">
                                <span class="ark-exceptions__key">現在の設定</span>
                                <v-chip size="small" :color="selectedException.is_off ? 'error' : 'warning'" variant="tonal">
                                    {{ selectedException.is_off ? '休みに設定' : '時間変更に設定' }}
                                </v-chip>
                                <v-btn size="small" variant="text" @click="clearException(selectedException.id)">
                                    通常シフトに戻す
                                </v-btn>
                            </div>

                            <div class="ark-exceptions__actions mt-3">
                                <v-btn
                                    color="error"
                                    variant="tonal"
                                    prepend-icon="mdi-cancel"
                                    @click="markDayOff"
                                >
                                    この日を休みにする
                                </v-btn>

                                <div class="ark-exceptions__addshift">
                                    <v-text-field
                                        v-model="addShiftForm.start_at"
                                        type="time"
                                        label="開始"
                                        density="compact"
                                        hide-details
                                        variant="outlined"
                                        style="max-width: 130px"
                                    />
                                    <span class="ark-weekgrid__sep">〜</span>
                                    <v-text-field
                                        v-model="addShiftForm.end_at"
                                        type="time"
                                        label="終了"
                                        density="compact"
                                        hide-details
                                        variant="outlined"
                                        style="max-width: 130px"
                                    />
                                    <v-btn
                                        color="primary"
                                        variant="tonal"
                                        prepend-icon="mdi-plus"
                                        :loading="addShiftForm.processing"
                                        @click="addCustomShift"
                                    >
                                        出勤を追加
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

                <SectionCard title="設定済みの例外日" class="mt-5">
                    <p v-if="exceptions.length === 0" class="text-medium-emphasis">
                        {{ MESSAGES.shift.noUpcomingExceptions }}
                    </p>
                    <v-table v-else density="comfortable">
                        <thead>
                            <tr>
                                <th>日付</th>
                                <th>内容</th>
                                <th>メモ</th>
                                <th class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in exceptions" :key="e.id">
                                <td>{{ e.exception_date }}</td>
                                <td>
                                    <v-chip size="small" :color="e.is_off ? 'error' : 'warning'" variant="tonal">
                                        {{ e.is_off ? '休み' : '時間変更' }}
                                    </v-chip>
                                </td>
                                <td class="text-medium-emphasis">{{ e.note ?? '—' }}</td>
                                <td class="text-right">
                                    <v-btn size="small" variant="text" @click="clearException(e.id)">
                                        解除
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </SectionCard>

                <SectionCard title="個別の勤務枠（この期間）" class="mt-5">
                    <template #append>
                        <div class="d-flex ga-1">
                            <v-btn size="small" variant="text" @click="periodShift(-28)">前へ</v-btn>
                            <v-btn size="small" variant="text" @click="periodShift(28)">次へ</v-btn>
                        </div>
                    </template>
                    <p class="text-caption text-medium-emphasis mb-2">
                        {{ filters.from }} 〜 {{ filters.to }} に手動で作成された勤務枠。
                    </p>
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
                                        削除
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </SectionCard>
            </div>
        </v-window-item>

        <!-- ───────── タブ3：予約受付 ───────── -->
        <v-window-item value="booking">
            <SectionCard
                title="お客様がいつまで予約できるか"
                subtitle="店舗全体の予約受付ルールです。管理者の手動予約はこの期間制限を受けません。"
            >
                <v-radio-group v-model="bookingForm.horizon_mode" hide-details>
                    <v-radio value="monthly">
                        <template #label>
                            <div>
                                <div class="font-weight-medium">毎月決まった日に翌月分を開放（おすすめ）</div>
                                <div class="text-body-2 text-medium-emphasis">
                                    例：毎月20日になると、翌月末までの予約を受け付けます。
                                </div>
                            </div>
                        </template>
                    </v-radio>
                    <div v-if="bookingForm.horizon_mode === 'monthly'" class="ark-booking__inset">
                        <v-select
                            v-model.number="bookingForm.release_day_of_month"
                            :items="releaseDayItems"
                            label="翌月分の開放日"
                            density="comfortable"
                            hide-details
                            style="max-width: 200px"
                            suffix="日"
                        />
                    </div>

                    <v-radio value="rolling" class="mt-2">
                        <template #label>
                            <div>
                                <div class="font-weight-medium">今日から一定日数先まで</div>
                                <div class="text-body-2 text-medium-emphasis">常に「◯日先まで」予約できます。</div>
                            </div>
                        </template>
                    </v-radio>
                    <div v-if="bookingForm.horizon_mode === 'rolling'" class="ark-booking__inset">
                        <v-text-field
                            v-model.number="bookingForm.horizon_days"
                            type="number"
                            label="何日先まで"
                            density="comfortable"
                            hide-details
                            style="max-width: 200px"
                            suffix="日先"
                        />
                    </div>

                    <v-radio value="none" class="mt-2">
                        <template #label>
                            <div>
                                <div class="font-weight-medium">制限なし</div>
                                <div class="text-body-2 text-medium-emphasis">いつの日付でも予約を受け付けます。</div>
                            </div>
                        </template>
                    </v-radio>
                </v-radio-group>

                <v-alert type="info" variant="tonal" density="compact" class="mt-4">
                    {{ horizonSummary }}
                    <template v-if="booking.enforced && booking.last_bookable_date">
                        （現在の受付上限：{{ formatJpDate(booking.last_bookable_date) }}）
                    </template>
                </v-alert>
            </SectionCard>

            <SectionCard title="直前予約の締切" class="mt-5">
                <v-select
                    v-if="leadIsPreset"
                    v-model.number="bookingForm.min_lead_minutes"
                    :items="leadPresets"
                    item-title="title"
                    item-value="value"
                    label="開始の何分前まで受け付けるか"
                    density="comfortable"
                    hide-details
                    style="max-width: 280px"
                />
                <div v-else class="d-flex align-center ga-2">
                    <v-text-field
                        v-model.number="bookingForm.min_lead_minutes"
                        type="number"
                        label="開始の何分前まで"
                        suffix="分前"
                        density="comfortable"
                        hide-details
                        style="max-width: 200px"
                    />
                    <v-btn variant="text" size="small" @click="bookingForm.min_lead_minutes = 0">
                        プリセットに戻す
                    </v-btn>
                </div>
                <p class="text-body-2 text-medium-emphasis mt-2">
                    {{ bookingForm.min_lead_minutes === 0
                        ? '開始直前まで予約を受け付けます。'
                        : `開始の ${bookingForm.min_lead_minutes} 分前で受付を締め切ります。` }}
                </p>
            </SectionCard>

            <SectionCard
                title="店舗休業日"
                subtitle="ここで指定した日は、全スタッフ・管理者の手動予約を含めて予約できません。"
                class="mt-5"
            >
                <div class="d-flex align-end ga-2" style="max-width: 360px">
                    <DateField v-model="newClosedDate" label="休業日を追加" :clearable="false" />
                    <v-btn color="primary" variant="tonal" :disabled="!newClosedDate" @click="addClosedDate">
                        追加
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
                    予約受付設定を保存
                </v-btn>
                <span v-if="Object.keys(bookingErrors).length" class="text-error text-body-2 ml-3">
                    {{ MESSAGES.common.checkInput }}
                </span>
            </div>
        </v-window-item>
    </v-window>
</template>

<style scoped>
.ark-weekgrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
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
</style>
