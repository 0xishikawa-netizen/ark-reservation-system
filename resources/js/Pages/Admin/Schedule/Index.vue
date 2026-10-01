<script setup lang="ts">
import { scheduleTrackHeight } from "@/components/admin/scheduleLayout";
import { Head, router, usePage } from "@inertiajs/vue3";
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from "vue";
import AdminLayout from "@/layouts/AdminLayout.vue";
import { ArkCalendar, DateField } from "@/components/ark";
import ReservationDetailPanel from "@/components/admin/ReservationDetailPanel.vue";
import CustomerSearchPanel from "@/components/admin/CustomerSearchPanel.vue";
import PanelCustomerSearchBar from "@/components/admin/PanelCustomerSearchBar.vue";
import NewReservationPanel, {
    type CreatePrefill,
} from "@/components/admin/NewReservationPanel.vue";
import ScheduleBlockCreatePanel, {
    type BlockCreatePrefill,
} from "@/components/admin/ScheduleBlockCreatePanel.vue";
import ScheduleBlockDetailPanel from "@/components/admin/ScheduleBlockDetailPanel.vue";
import SlotChoicePanel from "@/components/admin/SlotChoicePanel.vue";
import ScheduleNotifications from "@/components/admin/ScheduleNotifications.vue";
import { firstErrorMessage } from "@/composables/inertiaErrors";
import {
    DEFAULT_NOTIFICATION_REPEAT,
    DEFAULT_NOTIFICATION_SOUND,
    DEFAULT_NOTIFICATION_VOLUME,
    isNotificationRepeatMode,
    isNotificationSoundType,
} from "@/composables/notificationSound";
import { reservationStatusLabel, statusColor } from "@/design/tokens";
import {
    popPanelHistoryStack,
    pushPanelHistoryStack,
} from "@/composables/panelHistory";
import {
    applyBlockPrefill,
    applyReservationPrefill,
    createEmptyBlockDraft,
    createEmptyReservationDraft,
    resetBlockDraft,
    resetReservationDraft,
} from "@/composables/reservationDraft";
import { cannotStartAtTimeMessage, MESSAGES } from "@/constants/messages";

defineOptions({ layout: AdminLayout });

type ScheduleView = "day" | "week";
type ScheduleAxis = "staff" | "booth" | "both";

interface ScheduleLane {
    id: number | null;
    display_name: string;
    color: string;
    sort_order: number;
    kind: "staff" | "booth";
    /** 勤務予定外（休みだが予約・予定がある）スタッフ行。 */
    off_duty?: boolean;
}

interface Staff {
    user_id: number;
    display_name: string;
    color: string;
    sort_order: number;
    /** 対象期間に勤務枠がある（false は休みなのに予約・予定が入っている等の矛盾データで表示しているスタッフ）。 */
    is_working?: boolean;
}

interface OffStaff {
    user_id: number;
    display_name: string;
}

interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

interface Shift {
    staff_id: number;
    work_date: string;
    start_at: string;
    end_at: string;
}

interface ScheduleReservation {
    id: number;
    customer_id: number;
    customer_name: string;
    customer_gender: string | null;
    is_new_customer: boolean;
    service_id: number;
    service_name: string;
    service_color: string;
    staff_id: number | null;
    booth_id: number | null;
    starts_at: string;
    /** 占有の終わり（終了後インターバルを含む）。 */
    ends_at: string;
    /** 終了後インターバル（分）。予約（施術）の終わりは ends_at − buffer_min。 */
    buffer_min?: number;
    status: string;
    source: string;
    version: number;
    is_staff_requested: boolean;
}

interface ScheduleBlock {
    id: number;
    staff_id: number | null;
    booth_id: number | null;
    date: string;
    start_at: string;
    end_at: string;
    type: string;
    type_label: string;
    title: string | null;
    note: string | null;
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
    left: number;
    width: number;
}

interface MenuOption {
    id: number;
    name: string;
    duration_min: number;
    requires_staff: boolean;
    staff_ids: number[];
    price: number;
    category: string | null;
    color: string;
}

interface BoothOption {
    id: number;
    name: string;
}

interface DailySummary {
    total: number;
    completed: number;
    new_customers: number;
    repeat_customers: number;
    canceled: number;
    no_show: number;
    revenue: number | null;
}

const props = defineProps<{
    staff: Staff[];
    /** 対象期間に出勤予定がないため台帳に出していないスタッフ（ツールバーの「休み」表示用）。 */
    off_staff?: OffStaff[];
    staff_options: StaffOption[];
    menu_options: MenuOption[];
    booth_options: BoothOption[];
    /** 店舗全体・直近30日の実績から算出した「よく使うメニュー」のID（MenuPicker用・§7）。 */
    popular_service_ids: number[];
    shifts: Shift[];
    reservations: ScheduleReservation[];
    blocks: ScheduleBlock[];
    business_hours: BusinessHours;
    view: ScheduleView;
    axis: ScheduleAxis;
    range: DateRange;
    days: string[];
    booths: Booth[];
    summary: DailySummary | null;
    focus_reservation_id: number | null;
    focus_customer_id: number | null;
    focus_block_id: number | null;
    panel_mode: "search" | "create" | "block-create" | "slot-choice" | null;
    notification_sound?: {
        enabled: boolean;
        type: string;
        volume: number;
        repeat: string;
    };
    create_prefill: CreatePrefill;
    block_create_prefill: BlockCreatePrefill;
    filters: Filters;
}>();
const page = usePage();
const canManage = computed(() => page.props.auth.can.reservationsManage);
const canManageShifts = computed(() => page.props.auth.can.shiftsManage);
// 顧客検索は閲覧操作なので reservations.view で使える（§19）。reservations.manage 専用の
// 新規予約・編集・D&D等とは別の権限で判定する。
const canSearchCustomers = computed(() => page.props.auth.can.reservationsView);

const date = ref(props.filters.date);
const staffId = ref<number | null>(props.filters.staff_id);
const viewMode = ref<ScheduleView>(props.filters.view);
const axisMode = ref<ScheduleAxis>(props.filters.axis);
const FIXED_PIXELS_PER_MINUTE = 3.2;
const sectionHeaderHeight = 28;

// 日表示の行高（人数に応じて可変）の計算は scheduleLayout.ts。ここでは画面の空きを実測して渡す。
const TIMELINE_HEADER_HEIGHT = 44;
const TIMELINE_SCROLLBAR_ALLOWANCE = 14;
const timelineShellEl = ref<HTMLElement | null>(null);
const dailySummaryEl = ref<HTMLElement | null>(null);
const availableTrackSpace = ref(0);

// 「現在時刻へスクロール」（通常の固定ズーム）か「1日全体を表示」（幅に収まるよう自動縮小）かの切替（§追加）。
const timelineViewMode = ref<"now" | "fit">("fit");
const timelineScrollEl = ref<HTMLElement | null>(null);
const timelineScrollWidth = ref(0);
let timelineResizeObserver: ResizeObserver | null = null;

// 左パネルの高さ。右側（ツールバー〜台帳〜本日の集計）の下端にぴったり揃える。
// ブース表示などで台帳が縦に長くなっても、紺の背景が「本日の集計」の下端まで伸びる。
// 中身の方が長い時はパネル内でスクロールする。
const boardPanelEl = ref<HTMLElement | null>(null);
const boardMainEl = ref<HTMLElement | null>(null);
const boardLayoutEl = ref<HTMLElement | null>(null);
const panelMaxHeight = ref("calc(100dvh - 140px)");

/** 要素の上端のページ内位置（スクロール量に依存しない）。 */
function documentTop(el: HTMLElement): number {
    return el.getBoundingClientRect().top + window.scrollY;
}

/**
 * 左パネルは「本日の集計」の高さに合わせず、ブラウザ画面内で使える最下部（画面下端−ページ下余白）まで伸ばす。
 * 上端位置（ヘッダー＋上部操作領域の高さ）は画面幅で折り返しが変わるため実測し、高さは 100dvh 基準の calc にする。
 * あわせて日表示の行高の計算に使う「台帳に使える縦の空き」も更新する。
 */
function recalcPanelMaxHeight(): void {
    if (typeof window === "undefined") {
        return;
    }

    const layout = boardLayoutEl.value;

    if (layout !== null) {
        panelMaxHeight.value = `calc(100dvh - ${Math.max(Math.round(documentTop(layout)), 0)}px - var(--ark-space-5))`;
    }

    const shell = timelineShellEl.value;

    if (shell === null) {
        availableTrackSpace.value = 0;

        return;
    }

    const summaryHeight = dailySummaryEl.value !== null ? dailySummaryEl.value.offsetHeight + 8 : 0;
    // ページ下余白（v-container の padding 24px）分も残し、ページ全体に余計な縦スクロールを出さない。
    availableTrackSpace.value =
        window.innerHeight -
        documentTop(shell) -
        TIMELINE_HEADER_HEIGHT -
        TIMELINE_SCROLLBAR_ALLOWANCE -
        summaryHeight -
        24;
}

/**
 * スマホ幅（Peak Manager と同じ 1023px 以下）ではパネルが画面全体を覆うオーバーレイになる。
 * 既定状態（顧客検索）のまま出しっぱなしだと台帳が一切見えなくなるため、
 * 狭い画面では「明示的に何かを開いた時」だけパネルを出す。
 */
const isNarrowScreen = ref(false);
let narrowQuery: MediaQueryList | null = null;
let panelPositionResizeObserver: ResizeObserver | null = null;

function syncNarrowScreen(event: MediaQueryListEvent | MediaQueryList): void {
    isNarrowScreen.value = event.matches;
}

onMounted(() => {
    if (typeof window === "undefined") {
        return;
    }

    if (window.matchMedia) {
        narrowQuery = window.matchMedia("(max-width: 1023px)");
        syncNarrowScreen(narrowQuery);
        narrowQuery.addEventListener("change", syncNarrowScreen);
    }

    if (typeof ResizeObserver !== "undefined") {
        panelPositionResizeObserver = new ResizeObserver(() => {
            recalcPanelMaxHeight();
        });

        if (boardMainEl.value !== null) {
            panelPositionResizeObserver.observe(boardMainEl.value);
        }
    }
});

onBeforeUnmount(() => {
    narrowQuery?.removeEventListener("change", syncNarrowScreen);
    panelPositionResizeObserver?.disconnect();
    panelPositionResizeObserver = null;
});

const openMinute = computed(() => timeToMinute(props.business_hours.open));
const closeMinute = computed(() => timeToMinute(props.business_hours.close));
const durationMinutes = computed(() =>
    Math.max(closeMinute.value - openMinute.value, 0),
);
const pixelsPerMinute = computed(() => {
    if (
        timelineViewMode.value === "fit" &&
        timelineScrollWidth.value > 0 &&
        durationMinutes.value > 0 &&
        // スマホ幅で1日分を画面幅に押し込むと時刻ラベルが重なって読めなくなるため、
        // 狭い画面では「全体表示」でも固定倍率＋横スクロールにする。
        !isNarrowScreen.value
    ) {
        return timelineScrollWidth.value / durationMinutes.value;
    }

    return FIXED_PIXELS_PER_MINUTE;
});
const timelineWidth = computed(() =>
    Math.max(durationMinutes.value * pixelsPerMinute.value, 1),
);
const tickMinutes = computed(() =>
    props.business_hours.slot_minutes > 30
        ? props.business_hours.slot_minutes
        : 30,
);

const offStaff = computed<OffStaff[]>(() => props.off_staff ?? []);

function staffLanes(): ScheduleLane[] {
    const result: ScheduleLane[] = props.staff.map((staff) => ({
        id: staff.user_id,
        display_name: staff.display_name,
        color: staff.color,
        sort_order: staff.sort_order,
        kind: "staff" as const,
        off_duty: staff.is_working === false,
    }));

    if (
        props.reservations.some((reservation) => reservation.staff_id === null)
    ) {
        result.push({
            id: null,
            display_name: "担当なし",
            color: "rgb(var(--v-theme-secondary))",
            sort_order: 32767,
            kind: "staff" as const,
        });
    }

    return result;
}

function boothLanes(): ScheduleLane[] {
    const result: ScheduleLane[] = props.booths.map((booth) => ({
        id: booth.id,
        display_name: booth.name,
        color: "rgb(var(--v-theme-primary))",
        sort_order: booth.sort_order,
        kind: "booth" as const,
    }));

    if (
        props.reservations.some((reservation) => reservation.booth_id === null)
    ) {
        result.push({
            id: null,
            display_name: "ブース未割当",
            color: "rgb(var(--v-theme-secondary))",
            sort_order: 32767,
            kind: "booth" as const,
        });
    }

    return result;
}

/** 「両方」表示のときは、スタッフ行のあとにブース行を続けて 1 つの台帳に並べる（§21）。 */
const lanes = computed<ScheduleLane[]>(() => {
    if (axisMode.value === "booth") {
        return boothLanes();
    }
    if (axisMode.value === "both") {
        return [...staffLanes(), ...boothLanes()];
    }

    return staffLanes();
});

type DisplayRow =
    | {
          kind: "header";
          sectionKind: "staff" | "booth";
          label: string;
          icon: string;
      }
    | { kind: "lane"; lane: ScheduleLane };

/**
 * 「両方」表示のときだけ、横幅いっぱいの「スタッフ／ブース」見出し行を挟む（§25）。
 * レーンラベル列とトラック列の両方が同じ displayRows を辿るため、行数・高さのズレが起きない。
 */
const displayRows = computed<DisplayRow[]>(() => {
    if (axisMode.value !== "both") {
        return lanes.value.map((lane) => ({ kind: "lane" as const, lane }));
    }

    return [
        {
            kind: "header" as const,
            sectionKind: "staff" as const,
            label: "スタッフ",
            icon: "mdi-account-group-outline",
        },
        ...staffLanes().map((lane) => ({ kind: "lane" as const, lane })),
        {
            kind: "header" as const,
            sectionKind: "booth" as const,
            label: "ブース",
            icon: "mdi-view-grid-outline",
        },
        ...boothLanes().map((lane) => ({ kind: "lane" as const, lane })),
    ];
});

/** 日表示の1行の高さ（px）。見出し行（「両方」表示のスタッフ／ブース見出し）は固定高さのまま。 */
const timelineTrackHeight = computed(() => {
    const laneRows = displayRows.value.filter((row) => row.kind === "lane").length;

    return scheduleTrackHeight(
        laneRows,
        displayRows.value.length - laneRows,
        sectionHeaderHeight,
        availableTrackSpace.value,
        isNarrowScreen.value,
    );
});

const timeTicks = computed(() => {
    const ticks: number[] = [];

    for (
        let minute = openMinute.value;
        minute <= closeMinute.value;
        minute += tickMinutes.value
    ) {
        ticks.push(minute);
    }

    if (ticks[ticks.length - 1] !== closeMinute.value) {
        ticks.push(closeMinute.value);
    }

    return ticks;
});

function timeToMinute(value: string): number {
    const [hour = 0, minute = 0] = value.slice(0, 5).split(":").map(Number);

    return hour * 60 + minute;
}

function minuteToLabel(value: number): string {
    return `${String(Math.floor(value / 60)).padStart(2, "0")}:${String(value % 60).padStart(2, "0")}`;
}

function reservationStyle(
    reservation: ScheduleReservation,
): Record<string, string> {
    const startsAt = timeToMinute(reservation.starts_at.slice(11, 16));
    const endsAt = timeToMinute(reservation.ends_at.slice(11, 16));
    const visibleStart = Math.min(
        Math.max(startsAt, openMinute.value),
        closeMinute.value,
    );
    const visibleEnd = Math.max(
        Math.min(endsAt, closeMinute.value),
        openMinute.value,
    );
    const left = (visibleStart - openMinute.value) * pixelsPerMinute.value;
    const width = Math.max(
        (visibleEnd - visibleStart) * pixelsPerMinute.value,
        2,
    );

    return {
        left: `${left}px`,
        width: `${width}px`,
    };
}

/** 予約（施術）の終了時刻。インターバルは予約の後ろに別区間として表示する（Task 11-29）。 */
function serviceEndLabel(reservation: ScheduleReservation): string {
    return minuteToLabel(timeToMinute(reservation.ends_at.slice(11, 16)) - (reservation.buffer_min ?? 0));
}

/** カード内でインターバル区間（右端）を描く幅。 */
function bufferStyle(reservation: ScheduleReservation): Record<string, string> {
    return { width: `${(reservation.buffer_min ?? 0) * pixelsPerMinute.value}px` };
}

function reservationsFor(
    lane: { id: number | null; kind: "staff" | "booth" },
    day: string,
): ScheduleReservation[] {
    return props.reservations.filter((reservation) => {
        const reservationLaneId =
            lane.kind === "staff" ? reservation.staff_id : reservation.booth_id;

        return (
            reservationLaneId === lane.id &&
            reservation.starts_at.slice(0, 10) === day
        );
    });
}

/** 予定ブロック（休憩・他業務等）。予約カードとは明確に区別する（§36）。 */
function blocksFor(
    lane: { id: number | null; kind: "staff" | "booth" },
    day: string,
): ScheduleBlock[] {
    return props.blocks.filter((block) => {
        const blockLaneId =
            lane.kind === "staff" ? block.staff_id : block.booth_id;

        return blockLaneId === lane.id && block.date === day;
    });
}

function blockStyle(block: ScheduleBlock): Record<string, string> {
    const startsAt = timeToMinute(block.start_at);
    const endsAt = timeToMinute(block.end_at);
    const visibleStart = Math.min(
        Math.max(startsAt, openMinute.value),
        closeMinute.value,
    );
    const visibleEnd = Math.max(
        Math.min(endsAt, closeMinute.value),
        openMinute.value,
    );

    return {
        left: `${(visibleStart - openMinute.value) * pixelsPerMinute.value}px`,
        width: `${Math.max((visibleEnd - visibleStart) * pixelsPerMinute.value, 2)}px`,
    };
}

/* ───────────── 週表示（§2-3）─────────────
 * 日表示のタイムラインをそのまま7日ぶん並べると横に広くなりすぎるため、
 * スタッフ（または ブース）を行、日付を列とした「1日ぶんの営業時間を100%とする
 * コンパクトな帯グラフ」で1週間を俯瞰できるようにする。クリック時に開くパネル・
 * 予約詳細・空き枠からの新規予約/予定追加は日表示と同じ関数をそのまま再利用する
 * （業務ロジックの重複実装はしない）。 */
interface WeekRect {
    leftPct: number;
    widthPct: number;
}

function weekBarRect(startMin: number, endMin: number): WeekRect {
    const total = Math.max(closeMinute.value - openMinute.value, 1);
    const visibleStart = Math.min(
        Math.max(startMin, openMinute.value),
        closeMinute.value,
    );
    const visibleEnd = Math.max(
        Math.min(endMin, closeMinute.value),
        openMinute.value,
    );

    return {
        leftPct: ((visibleStart - openMinute.value) / total) * 100,
        widthPct: Math.max(((visibleEnd - visibleStart) / total) * 100, 1.5),
    };
}

function weekRectStyle(rect: WeekRect): Record<string, string> {
    return { left: `${rect.leftPct}%`, width: `${rect.widthPct}%` };
}

/** 勤務外（§2必須項目）。日表示の nonWorkingSegments と同じアルゴリズムを、日付ごとに適用する。 */
function weekNonWorkingRects(lane: ScheduleLane, day: string): WeekRect[] {
    if (lane.kind !== "staff" || lane.id === null) {
        return [];
    }

    const staffShifts = props.shifts.filter(
        (shift) => shift.staff_id === lane.id && shift.work_date === day,
    );
    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const rects: WeekRect[] = [];
    let segmentStart: number | null = null;

    for (
        let minute = openMinute.value;
        minute < closeMinute.value;
        minute += unit
    ) {
        const segmentEnd = Math.min(minute + unit, closeMinute.value);
        const working = staffShifts.some(
            (shift) =>
                minute >= timeToMinute(shift.start_at) &&
                segmentEnd <= timeToMinute(shift.end_at),
        );

        if (!working && segmentStart === null) {
            segmentStart = minute;
        }

        if (working && segmentStart !== null) {
            rects.push(weekBarRect(segmentStart, minute));
            segmentStart = null;
        }
    }

    if (segmentStart !== null) {
        rects.push(weekBarRect(segmentStart, closeMinute.value));
    }

    return rects;
}

function weekReservationTooltip(reservation: ScheduleReservation): string {
    return `${reservation.starts_at.slice(11, 16)}〜${serviceEndLabel(reservation)} ${reservation.customer_name} ／ ${reservation.service_name}`;
}

function weekBlockTooltip(block: ScheduleBlock): string {
    return `${block.start_at}〜${block.end_at} ${block.title ?? block.type_label}`;
}

/** 空きセルクリック → 待機中の予約がなければ「予約／予定」の選択パネルを開く。 */
function onWeekCellClick(
    lane: ScheduleLane,
    day: string,
    event: MouseEvent,
): void {
    if (!canManage.value) {
        return;
    }

    const cellEl = event.currentTarget as HTMLElement;
    const rect = cellEl.getBoundingClientRect();
    const ratio =
        rect.width > 0
            ? Math.min(Math.max((event.clientX - rect.left) / rect.width, 0), 1)
            : 0;
    const rawMinute =
        openMinute.value + ratio * (closeMinute.value - openMinute.value);
    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const snappedMinute = Math.min(
        Math.max(Math.round(rawMinute / unit) * unit, openMinute.value),
        closeMinute.value,
    );

    const slotPrefill = {
        staffId:
            lane.kind === "staff" && lane.id !== null ? lane.id : undefined,
        boothId:
            lane.kind === "booth" && lane.id !== null ? lane.id : undefined,
        date: day,
        time: minuteToLabel(snappedMinute),
    };

    // 「メニューで空きを確認」中の空き枠クリックは予約の操作なので、予約／予定の選択を挟まず
    // そのメニュー（＋クリックした担当/ブース）を選択済みで予約パネルを開く（Task 11-30）。
    if (shouldFillCreatePanelFromSlot() || previewServiceId.value !== null) {
        fillCreatePanelFromSlot(slotPrefill);

        return;
    }

    openSlotChoicePanel(slotPrefill);
}

const BLOCK_ICON: Record<string, string> = {
    BREAK: "mdi-coffee-outline",
    MEETING: "mdi-account-group-outline",
    ADMIN: "mdi-file-document-outline",
    CLEANING: "mdi-broom",
    WORK: "mdi-briefcase-outline",
    TRAINING: "mdi-school-outline",
    OUT: "mdi-walk",
    OTHER: "mdi-dots-horizontal",
};

const BLOCK_COLOR: Record<string, string> = {
    BREAK: "warning",
    MEETING: "info",
    ADMIN: "secondary",
    CLEANING: "success",
    WORK: "primary",
    TRAINING: "accent",
    OUT: "secondary",
    OTHER: "secondary",
};

function blockIcon(type: string): string {
    return BLOCK_ICON[type] ?? "mdi-calendar-blank-outline";
}

function blockColor(type: string): string {
    return BLOCK_COLOR[type] ?? "secondary";
}

function nonWorkingSegments(staffIdValue: number | null): ShadeSegment[] {
    if (staffIdValue === null) {
        return [];
    }

    const staffShifts = props.shifts.filter(
        (shift) =>
            shift.staff_id === staffIdValue && shift.work_date === date.value,
    );
    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const segments: ShadeSegment[] = [];
    let segmentStart: number | null = null;

    for (
        let minute = openMinute.value;
        minute < closeMinute.value;
        minute += unit
    ) {
        const segmentEnd = Math.min(minute + unit, closeMinute.value);
        const working = staffShifts.some(
            (shift) =>
                minute >= timeToMinute(shift.start_at) &&
                segmentEnd <= timeToMinute(shift.end_at),
        );

        if (!working && segmentStart === null) {
            segmentStart = minute;
        }

        if (working && segmentStart !== null) {
            segments.push({
                left: (segmentStart - openMinute.value) * pixelsPerMinute.value,
                width: (minute - segmentStart) * pixelsPerMinute.value,
            });
            segmentStart = null;
        }
    }

    if (segmentStart !== null) {
        segments.push({
            left: (segmentStart - openMinute.value) * pixelsPerMinute.value,
            width: (closeMinute.value - segmentStart) * pixelsPerMinute.value,
        });
    }

    return segments;
}

/* ───────────── 予約台帳サイドパネル（顧客詳細／顧客検索／新規予約を共用）＋ 来店履歴ナビ ───────────── */

type PanelKind =
    | "detail-reservation"
    | "detail-customer"
    | "search"
    | "create"
    | "block-create"
    | "block-detail"
    | "slot-choice"
    | null;

// パネルの状態は URL クエリが正本（§10）：?reservation= / ?customer= / ?block= / ?panel=search|create|block-create。
const panelReservationId = computed<number | null>(
    () => props.focus_reservation_id,
);
const panelCustomerId = computed<number | null>(() => props.focus_customer_id);
const panelBlockId = computed<number | null>(() => props.focus_block_id);
const panelKind = computed<PanelKind>(() => {
    if (panelReservationId.value !== null) return "detail-reservation";
    if (panelCustomerId.value !== null) return "detail-customer";
    if (panelBlockId.value !== null) return "block-detail";
    if (props.panel_mode === "search") return "search";
    if (props.panel_mode === "create") return "create";
    if (props.panel_mode === "block-create") return "block-create";
    if (props.panel_mode === "slot-choice") return "slot-choice";

    // 顧客検索は基本的に常に表示しておく（他のパネルを閉じたときの既定状態）。
    return "search";
});
const awaitingBoardSlotSelection = computed(
    () =>
        panelKind.value === "create" &&
        props.create_prefill.customer_id !== null &&
        props.create_prefill.date === null,
);
const panelOpen = computed(() => panelKind.value !== null);

/**
 * 空き枠クリック時、「予約／予定」の選択パネルを出さずに、入力中の新規予約へ
 * 日時（＋クリックした担当/ブース）だけ流し込むか。
 * 再予約（引用）や、顧客選択済みで予約入力中の時は、選択パネルは不要。
 */
function shouldFillCreatePanelFromSlot(): boolean {
    return (
        awaitingBoardSlotSelection.value ||
        (panelKind.value === "create" && reservationDraft.customer_id !== null)
    );
}

/**
 * 新規予約パネルの作り直しキー。空き枠クリックで日時・担当が変わった時に作り直し、
 * クリックした日時を確実に反映する（開いたままだと最初の1回しか prefill を読まず、
 * 「開始時間が表示されない」状態になっていた）。顧客・メニュー等は draft が保持する。
 */
const createPanelKey = computed(
    () =>
        `${props.create_prefill.date}|${props.create_prefill.time}|${props.create_prefill.staff_id}|${props.create_prefill.booth_id}`,
);

/**
 * 再予約（引用）中の「日時を選ぶ」モード。「日付を跨いで変更」と同じ操作感で、
 * 日付を移動しながら空き枠をクリックして日時を入れられる。
 */
const slotPickActive = computed(
    () =>
        panelKind.value === "create" && props.create_prefill.customer_id !== null,
);
/** まだ日時が決まっていない間だけ、マウスに予約内容を追従させる。 */
const slotPickGhostVisible = computed(
    () => slotPickActive.value && props.create_prefill.date === null,
);
const slotPickLabel = computed(() => {
    const customer = reservationDraft.customer_name ?? "お客様";
    const service =
        props.menu_options.find((m) => m.id === reservationDraft.service_id)
            ?.name ?? null;

    return service === null ? `${customer}様` : `${customer}様 ／ ${service}`;
});

/**
 * 再予約の「ボードで日時を選ぶ」モードを解除する。新規予約パネルは開いたまま、
 * 日付・開始時間はパネル内で手入力する形に戻す（顧客・メニュー等は draft が保持）。
 */
function releaseSlotPick(): void {
    visitPanel({
        panel: "create",
        pf_service_id: reservationDraft.service_id ?? undefined,
        pf_staff_id: reservationDraft.staff_id ?? undefined,
    });
}

/** 空き枠クリック → 新規予約パネルへ日時を流し込む。顧客・メニューも URL に残し、
 * 日付を移動しても再予約の続きとして扱えるようにする。 */
function fillCreatePanelFromSlot(
    slotPrefill: Partial<{
        staffId: number;
        boothId: number;
        date: string;
        time: string;
    }>,
): void {
    // 再予約（引用）で実際の日時を選んだら、「メニューで空きを確認」の絞り込みは役目を
    // 終えるので自動で解除する（日付跨ぎ移動と同じ）。
    if (slotPickActive.value) {
        previewServiceId.value = null;
    }

    openCreatePanel({
        customerId:
            props.create_prefill.customer_id ??
            reservationDraft.customer_id ??
            undefined,
        serviceId:
            props.create_prefill.service_id ??
            previewServiceId.value ??
            reservationDraft.service_id ??
            undefined,
        staffId: slotPrefill.staffId ?? reservationDraft.staff_id ?? undefined,
        boothId: slotPrefill.boothId,
        date: slotPrefill.date,
        time: slotPrefill.time,
    });
}

watch(panelKind, () => {
    void nextTick(() => recalcPanelMaxHeight());
});

// パネルの中身が切り替わったとき（例：予約詳細→新規予約）に、前の内容のスクロール位置が
// 残ったまま新しい内容の途中（メニュー欄など）から表示されてしまうのを防ぐ。毎回先頭に戻す。
const panelContentKey = computed(
    () =>
        `${panelKind.value}:${panelReservationId.value}:${panelCustomerId.value}:${panelBlockId.value}`,
);
watch(panelContentKey, () => {
    void nextTick(() => {
        const body = boardPanelEl.value?.querySelector(".panel-shell__body");
        body?.scrollTo({ top: 0 });
    });
});

// 左のアイコンレール（Peak Manager 参考）でパネル自体の表示/非表示を切り替える。
// panelKind（URL）はそのまま保持し、見た目だけ畳む・開くローカル状態。
const panelCollapsed = ref(false);

/** 実際にパネル（＋スクリム）を表示するか。 */
const panelVisible = computed(
    () =>
        panelOpen.value &&
        !panelCollapsed.value &&
        !(isNarrowScreen.value && panelKind.value === "search"),
);

function togglePanelCollapsed(): void {
    panelCollapsed.value = !panelCollapsed.value;
}

function railOpenReservation(): void {
    panelCollapsed.value = false;
    openCreatePanel();
}

function railOpenCustomerSearch(): void {
    panelCollapsed.value = false;
    openSearchPanel();
}

/** 空き枠選択パネルで「どのスタッフの枠か」を出すための表示名。 */
const slotChoiceStaffName = computed<string | null>(() => {
    const staffId = props.create_prefill.staff_id;

    if (staffId === null) {
        return null;
    }

    return (
        props.staff_options.find((s) => s.user_id === staffId)?.display_name ??
        null
    );
});

const activeBlock = computed<ScheduleBlock | null>(() => {
    if (panelBlockId.value === null) return null;

    return props.blocks.find((b) => b.id === panelBlockId.value) ?? null;
});

/* ───────────── 新規予約／予定追加の入力中身を保持する draft（§1・§17） ─────────────
 * このページ（Schedule/Index.vue）が生きている間だけ持ち続ける。パネル切替・戻る・
 * MenuPicker・顧客検索では消さず、作成成功時にだけ空にする（NewReservationPanel.vue /
 * ScheduleBlockCreatePanel.vue へ同じオブジェクトをそのまま渡して直接書き込ませる）。 */
const reservationDraft = reactive(createEmptyReservationDraft());
const blockDraft = reactive(createEmptyBlockDraft());

/* ───────────── 左パネル内部の「戻る」（§6）─────────────
 * ブラウザ履歴は増やさず（visitPanel は既に replace で history を汚さない設計）、
 * 「直前のパネル状態」だけをこの軽量スタックで数件だけ覚えておく。
 * リロードでは復元しない（URL にはこの履歴自体は乗せない）——リロード直後は
 * 常に「戻る」なしの状態から始まる、という単純な仕様にする。 */
const PANEL_HISTORY_LIMIT = 5;
const panelHistoryStack = ref<PanelQueryOverrides[]>([]);
const canGoBackPanel = computed(() => panelHistoryStack.value.length > 0);
const searchBarEl = ref<{
    restoreFocus: () => void;
    closeDropdown: () => void;
} | null>(null);

// 検索以外のパネルに切り替わったら、開いたままの検索ドロップダウンを閉じる
// （新しい内容の上に被って見えてしまうため）。検索語・結果自体は保持する。
watch(panelKind, (kind) => {
    if (kind !== "search") {
        searchBarEl.value?.closeDropdown();
    }
});

/** 現在表示中のパネル状態を、そのまま visitPanel に渡せる overrides の形に戻す。 */
function currentPanelOverrides(): PanelQueryOverrides {
    switch (panelKind.value) {
        case "detail-reservation":
            return panelReservationId.value !== null
                ? { reservation: panelReservationId.value }
                : { panel: "search" };
        case "detail-customer":
            return panelCustomerId.value !== null
                ? { customer: panelCustomerId.value }
                : { panel: "search" };
        case "block-detail":
            return panelBlockId.value !== null
                ? { block: panelBlockId.value }
                : { panel: "search" };
        case "create":
            return {
                panel: "create",
                pf_customer_id: props.create_prefill.customer_id ?? undefined,
                pf_service_id: props.create_prefill.service_id ?? undefined,
                pf_staff_id: props.create_prefill.staff_id ?? undefined,
                pf_booth_id: props.create_prefill.booth_id ?? undefined,
                pf_date: props.create_prefill.date ?? undefined,
                pf_time: props.create_prefill.time ?? undefined,
            };
        case "block-create":
            return {
                panel: "block-create",
                bf_staff_id: props.block_create_prefill.staff_id ?? undefined,
                bf_booth_id: props.block_create_prefill.booth_id ?? undefined,
                bf_date: props.block_create_prefill.date ?? undefined,
                bf_time: props.block_create_prefill.time ?? undefined,
            };
        case "slot-choice":
            return {
                panel: "slot-choice",
                pf_staff_id: props.create_prefill.staff_id ?? undefined,
                pf_booth_id: props.create_prefill.booth_id ?? undefined,
                pf_date: props.create_prefill.date ?? undefined,
                pf_time: props.create_prefill.time ?? undefined,
                bf_staff_id: props.block_create_prefill.staff_id ?? undefined,
                bf_booth_id: props.block_create_prefill.booth_id ?? undefined,
                bf_date: props.block_create_prefill.date ?? undefined,
                bf_time: props.block_create_prefill.time ?? undefined,
            };
        case "search":
        default:
            return { panel: "search" };
    }
}

function pushPanelHistory(): void {
    if (!panelOpen.value) {
        return;
    }

    panelHistoryStack.value = pushPanelHistoryStack(
        panelHistoryStack.value,
        currentPanelOverrides(),
        PANEL_HISTORY_LIMIT,
    );
}

function goBackPanel(): void {
    const { target, rest } = popPanelHistoryStack(panelHistoryStack.value);

    if (target === null) {
        return;
    }

    panelHistoryStack.value = rest;
    visitPanel(target, { isBack: true });

    if (target.panel === "search") {
        void nextTick(() => searchBarEl.value?.restoreFocus());
    }
}

// 履歴クリックで移動した直後に、対象カードを一時的に強調する。
const highlightReservationId = ref<number | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | null = null;

interface PanelQueryOverrides {
    reservation?: number | undefined;
    customer?: number | undefined;
    block?: number | undefined;
    panel?: "search" | "create" | "block-create" | "slot-choice" | undefined;
    date?: string;
    pf_customer_id?: number;
    pf_service_id?: number;
    pf_staff_id?: number;
    pf_booth_id?: number;
    pf_date?: string;
    pf_time?: string;
    bf_staff_id?: number;
    bf_booth_id?: number;
    bf_date?: string;
    bf_time?: string;
}

function baseQuery(): Record<string, string | number | undefined> {
    return {
        date: date.value,
        view: viewMode.value,
        axis: axisMode.value,
        staff_id: staffId.value ?? undefined,
    };
}

/**
 * 予約・予定の作成/変更後、サーバーは `back()` で戻すのではなく明示的にこの表示状態へ
 * 戻す（軸・スタッフ絞り込みが「スタッフ」にリセットされてしまうのを防ぐ）。
 */
const returnQuery = computed(() => ({
    view: viewMode.value,
    axis: axisMode.value,
    staff_id: staffId.value ?? undefined,
}));

function visitPanel(
    overrides: PanelQueryOverrides,
    options: {
        preserveState?: boolean;
        preserveScroll?: boolean;
        replace?: boolean;
        isBack?: boolean;
    } = {},
): void {
    const { isBack, ...visitOptions } = options;

    if (!isBack) {
        pushPanelHistory();
    }

    // 折りたたんでいる時にカード等をクリックしたら、その内容を見せるために開き直す。
    panelCollapsed.value = false;

    router.get(
        "/admin/schedule",
        { ...baseQuery(), ...overrides },
        {
            preserveState: true,
            preserveScroll: true,
            replace: panelOpen.value,
            ...visitOptions,
        },
    );
}

function openReservationPanel(reservationId: number): void {
    if (panelReservationId.value === reservationId) {
        return;
    }

    visitPanel({ reservation: reservationId });
}

function openCustomerPanel(customerId: number): void {
    visitPanel({ customer: customerId });
}

/** 新規予約を書いていて顧客がまだ未選択なら、上の検索欄を赤く強調する。 */
const needsCustomerSelection = computed(
    () => panelKind.value === "create" && reservationDraft.customer_id === null,
);

/**
 * 常時表示の顧客検索から顧客を選んだ時の挙動。
 * 新規予約を書いている最中なら、そのままフォームの顧客欄に入れる
 * （パネル内にもう一つ検索欄を置くと同じ機能が2つ並んで紛らわしいため）。
 * それ以外は従来どおり顧客詳細パネルを開く。
 */
function onCustomerSearchSelect(payload: {
    customerId: number;
    name: string;
    kana: string | null;
}): void {
    if (panelKind.value === "create") {
        reservationDraft.customer_id = payload.customerId;
        reservationDraft.customer_name = payload.name;
        reservationDraft.customer_kana = payload.kana;

        return;
    }

    openCustomerPanel(payload.customerId);
}

function openSearchPanel(): void {
    visitPanel({ panel: "search" });
}

function openCreatePanel(
    prefill: Partial<{
        customerId: number;
        serviceId: number;
        staffId: number;
        boothId: number;
        date: string;
        time: string;
    }> = {},
): void {
    visitPanel({
        panel: "create",
        pf_customer_id: prefill.customerId,
        pf_service_id: prefill.serviceId,
        pf_staff_id: prefill.staffId,
        pf_booth_id: prefill.boothId,
        pf_date: prefill.date,
        pf_time: prefill.time,
    });
}

function openBlockCreatePanel(
    prefill: Partial<{
        staffId: number;
        boothId: number;
        date: string;
        time: string;
    }> = {},
): void {
    visitPanel({
        panel: "block-create",
        bf_staff_id: prefill.staffId,
        bf_booth_id: prefill.boothId,
        bf_date: prefill.date,
        bf_time: prefill.time,
    });
}

function openBlockDetailPanel(blockId: number): void {
    visitPanel({ block: blockId });
}

/** 空きセルクリック → まず「予約を入れる／予定を入れる」を選ばせる（左パネル内・即座に開く）。 */
function openSlotChoicePanel(
    prefill: Partial<{
        staffId: number;
        boothId: number;
        date: string;
        time: string;
    }> = {},
): void {
    visitPanel({
        panel: "slot-choice",
        pf_staff_id: prefill.staffId,
        pf_booth_id: prefill.boothId,
        pf_date: prefill.date,
        pf_time: prefill.time,
        bf_staff_id: prefill.staffId,
        bf_booth_id: prefill.boothId,
        bf_date: prefill.date,
        bf_time: prefill.time,
    });
}

/** 新規予約パネル ⇄ 予定作成パネルの切替。draft（入力中身）はそのまま保持されるので、
 * ここで日付・時刻・担当・ブースだけ明示的に引き継ぐ（他の入力欄は draft 自身が覚えている）。 */
function onSwitchToBlock(): void {
    // 空き枠選択パネルから来た時は、クリックした枠（＝URLのprefill）をそのまま引き継ぐ。
    // draft は該当パネルがマウントされて初めて埋まるため、この時点ではまだ空になっている。
    if (panelKind.value === "slot-choice") {
        openBlockCreatePanel({
            staffId: props.block_create_prefill.staff_id ?? undefined,
            boothId: props.block_create_prefill.booth_id ?? undefined,
            date: props.block_create_prefill.date ?? undefined,
            time: props.block_create_prefill.time ?? undefined,
        });

        return;
    }

    openBlockCreatePanel({
        staffId: reservationDraft.staff_id ?? undefined,
        boothId: reservationDraft.booth_id ?? undefined,
        date: reservationDraft.date || undefined,
        time: reservationDraft.starts_at?.slice(11, 16) ?? undefined,
    });
}

function onSwitchToReservation(): void {
    if (panelKind.value === "slot-choice") {
        openCreatePanel({
            // 「メニューで空きを確認」中に選んだ枠なら、そのメニューを選択済みで開く（Task 11-30）。
            serviceId: previewServiceId.value ?? reservationDraft.service_id ?? undefined,
            staffId: props.create_prefill.staff_id ?? undefined,
            boothId: props.create_prefill.booth_id ?? undefined,
            date: props.create_prefill.date ?? undefined,
            time: props.create_prefill.time ?? undefined,
        });

        return;
    }

    openCreatePanel({
        staffId: blockDraft.staff_id ?? undefined,
        boothId: blockDraft.booth_id ?? undefined,
        date: blockDraft.work_date || undefined,
        time: blockDraft.start_at || undefined,
    });
}

function closePanel(): void {
    if (!panelOpen.value) {
        return;
    }

    panelHistoryStack.value = [];

    router.get("/admin/schedule", baseQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onHistoryNavigate(payload: {
    date: string;
    reservationId: number;
}): void {
    // 別日の台帳へ移動するため、直前の文脈に依存する「戻る」履歴は破棄する。
    panelHistoryStack.value = [];

    router.get(
        "/admin/schedule",
        {
            date: payload.date,
            view: "day",
            axis: axisMode.value,
            staff_id: staffId.value ?? undefined,
            reservation: payload.reservationId,
        },
        {
            preserveScroll: false,
        },
    );
}

// 通知クリック → 対象日へ移動＋対象予約をハイライト（§36）。パネルも合わせて開く。
function onNotificationSelect(payload: {
    date: string;
    reservationId: number;
}): void {
    onHistoryNavigate(payload);
}

function onRebook(payload: {
    customerId: number;
    serviceId: number;
    staffId: number | null;
}): void {
    // 「日付を跨いで変更」と同じく、そのメニューで空き枠を確認した状態にしておく
    // （他の日付へ移動しても、そのメニューの空き枠が表示されたままになる）。
    previewServiceId.value = payload.serviceId;
    openCreatePanel({
        customerId: payload.customerId,
        serviceId: payload.serviceId,
        staffId: payload.staffId ?? undefined,
    });
}

function onPanelHighlight(payload: { reservationId: number }): void {
    flashReservationCard(payload.reservationId);
}

/** 作成・追加・削除など、一連の流れが完了した時に呼ぶ。「戻る」履歴を空にする
 * （残っていると、完了後に無関係な直前の状態へ戻れてしまう）。 */
function clearPanelHistory(): void {
    panelHistoryStack.value = [];
}

function onReservationCreated(): void {
    // store() はサーバー側で admin.schedule.index へリダイレクトする（既存 store 再利用）。
    clearPanelHistory();
    resetReservationDraft(reservationDraft);
}

function onBlockCreated(): void {
    clearPanelHistory();
    resetBlockDraft(blockDraft);
}

function scrollCardIntoView(el: HTMLElement): void {
    const scroller = el.closest<HTMLElement>(".timeline-scroll");

    if (scroller !== null) {
        // 横タイムラインは入れ子スクロール。カード中心が中央に来るよう明示的に動かす。
        // （この環境では scrollTo(options) が無視されるため scrollLeft を直接設定する）
        const target =
            el.offsetLeft - scroller.clientWidth / 2 + el.offsetWidth / 2;
        const max = Math.max(0, scroller.scrollWidth - scroller.clientWidth);

        scroller.scrollLeft = Math.max(0, Math.min(target, max));
    }

    el.scrollIntoView({ block: "nearest" });
}

function flashReservationCard(reservationId: number): void {
    // Inertia のページ差し替え後、カードが実寸で描画されるまで待ってからスクロール＆強調。
    let attempts = 0;
    const tryFlash = (): void => {
        const el = document.querySelector<HTMLElement>(
            `.reservation-card[data-reservation-id="${reservationId}"]`,
        );
        const scroller = el?.closest<HTMLElement>(".timeline-scroll") ?? null;
        const ready =
            el !== null &&
            el.offsetWidth > 0 &&
            scroller !== null &&
            scroller.scrollWidth > scroller.clientWidth;

        if (!ready) {
            if (attempts++ < 30) {
                setTimeout(tryFlash, 120);
            }

            return;
        }

        scrollCardIntoView(el as HTMLElement);
        highlightReservationId.value = reservationId;

        if (highlightTimer !== null) {
            clearTimeout(highlightTimer);
        }

        highlightTimer = setTimeout(() => {
            highlightReservationId.value = null;
        }, 3200);
    };

    void nextTick(() => setTimeout(tryFlash, 80));
}

// パネル対象が変わったら（初回表示・履歴遷移・戻る/進む）該当カードへスクロール＆強調。
watch(
    () => props.focus_reservation_id,
    (id) => {
        if (id !== null) {
            flashReservationCard(id);
        }
    },
    { immediate: true },
);

function onWindowKeydown(event: KeyboardEvent): void {
    if (event.key !== "Escape") {
        return;
    }

    if (crossDateMove.value !== null) {
        if (pendingMove.value !== null) {
            cancelMove();
        } else {
            cancelCrossDateMove();
        }

        return;
    }

    // 再予約で日時を選んでいる最中の Esc は、パネルは閉じずに選択モードだけ解除する。
    if (slotPickActive.value) {
        releaseSlotPick();

        return;
    }

    if (panelOpen.value && pendingMove.value === null) {
        closePanel();
    }
}

onMounted(() => {
    window.addEventListener("keydown", onWindowKeydown);
    window.addEventListener("resize", recalcPanelMaxHeight);
    void nextTick(() => recalcPanelMaxHeight());
    clockTimer = setInterval(() => {
        clockTick.value = Date.now();
    }, 60000);
});
onBeforeUnmount(() => {
    window.removeEventListener("keydown", onWindowKeydown);
    window.removeEventListener("resize", recalcPanelMaxHeight);

    if (clockTimer !== null) {
        clearInterval(clockTimer);
        clockTimer = null;
    }

    if (highlightTimer !== null) {
        clearTimeout(highlightTimer);
    }

    if (timelineResizeObserver !== null) {
        timelineResizeObserver.disconnect();
        timelineResizeObserver = null;
    }
});

watch(boardPanelEl, (el) => {
    if (el !== null) {
        void nextTick(() => recalcPanelMaxHeight());
    }
});

// 日/週・軸・日付の切替や本日の集計の有無で台帳の上端・空きが変わるため、行高と左パネル高さを測り直す。
watch(
    [timelineShellEl, dailySummaryEl, () => displayRows.value.length, isNarrowScreen],
    () => {
        void nextTick(() => recalcPanelMaxHeight());
    },
);

// 性別表示は簡素に「男 / 女」のみ。未登録・その他は表示しない（推測しない）。
function genderLabel(gender: string | null): string | null {
    if (gender === "male") return "男";
    if (gender === "female") return "女";

    return null;
}

function genderClass(gender: string | null): string {
    return gender === "male" || gender === "female"
        ? `reservation-gender reservation-gender--${gender}`
        : "reservation-gender";
}

const statusLabel = reservationStatusLabel;

// カード右上のステータスは色だけに頼らず、アイコン＋tooltip/aria-label で伝える（§26）。
function statusIcon(status: string): string {
    const icons: Record<string, string> = {
        confirmed: "mdi-calendar-check-outline",
        completed: "mdi-check-circle-outline",
        no_show: "mdi-account-off-outline",
        pending_payment: "mdi-timer-sand",
        pending_external_sync: "mdi-sync",
        canceled: "mdi-close-circle-outline",
        expired: "mdi-clock-alert-outline",
    };

    return icons[status] ?? "mdi-information-outline";
}

/** メニュー色（HEX）から、カードの淡い背景色を作る。原色ベタ塗りを避ける（§22）。 */
function menuTintBackground(hex: string): string {
    const clean = hex.replace("#", "");
    const full =
        clean.length === 3
            ? clean
                  .split("")
                  .map((c) => c + c)
                  .join("")
            : clean;
    const value = Number.parseInt(full, 16);

    if (Number.isNaN(value)) {
        return "rgb(var(--v-theme-surface))";
    }

    const r = (value >> 16) & 255;
    const g = (value >> 8) & 255;
    const b = value & 255;

    // 透けて見えないよう、背景と合成済みの不透明色にする（白に薄く色を混ぜる）。
    const mix = (channel: number): number =>
        Math.round(255 * 0.88 + channel * 0.12);

    return `rgb(${mix(r)}, ${mix(g)}, ${mix(b)})`;
}

function navigate(): void {
    panelHistoryStack.value = [];
    const panelOverrides = currentPanelOverrides();
    router.get(
        "/admin/schedule",
        {
            date: date.value,
            staff_id: staffId.value ?? undefined,
            view: viewMode.value,
            axis: axisMode.value,
            ...panelOverrides,
            pf_date: undefined,
            pf_time: undefined,
            bf_date: undefined,
            bf_time: undefined,
        },
        { preserveState: true, replace: true },
    );
}

function movePeriod(direction: number): void {
    const next = new Date(`${date.value}T12:00:00`);
    next.setDate(
        next.getDate() + direction * (viewMode.value === "week" ? 7 : 1),
    );
    const year = next.getFullYear();
    const month = String(next.getMonth() + 1).padStart(2, "0");
    const day = String(next.getDate()).padStart(2, "0");
    date.value = `${year}-${month}-${day}`;
    navigate();
}

function todayIso(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
}

const isViewingToday = computed(() => date.value === todayIso());

function goToToday(): void {
    if (isViewingToday.value) {
        return;
    }
    date.value = todayIso();
    navigate();
}

// スタッフ名クリック → そのスタッフを選択した状態で勤務枠管理画面へ（§19）。
function goToStaffShifts(staffId: number): void {
    router.visit(`/admin/staff-shifts?staff_id=${staffId}`);
}

/** 空き枠クリック → 待機中の予約がなければ「予約／予定」の選択パネルを開く。 */
function onTrackClick(lane: ScheduleLane, event: MouseEvent): void {
    if (!canManage.value || viewMode.value !== "day") {
        return;
    }

    const trackEl = event.currentTarget as HTMLElement;
    const rect = trackEl.getBoundingClientRect();
    const rawMinute =
        openMinute.value + (event.clientX - rect.left) / pixelsPerMinute.value;
    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const snappedMinute = Math.min(
        Math.max(Math.round(rawMinute / unit) * unit, openMinute.value),
        closeMinute.value,
    );

    if (crossDateMove.value !== null) {
        const reservation = crossDateMoveReservation.value;

        if (reservation === null) {
            cancelCrossDateMove();

            return;
        }

        const durationMin =
            timeToMinute(reservation.ends_at.slice(11, 16)) -
            timeToMinute(reservation.starts_at.slice(11, 16));
        const originLaneId =
            lane.kind === "staff"
                ? reservation.staff_id
                : reservation.booth_id;

        // 移動先の日時を選んだら、「メニューで空きを確認」の絞り込みは自動で解除する。
        previewServiceId.value = null;

        pendingMove.value = {
            reservation,
            newStartMin: snappedMinute,
            newEndMin: snappedMinute + durationMin,
            laneChanged: lane.id !== originLaneId,
            newLaneId: lane.id,
            laneKind: lane.kind,
            targetDate: date.value,
        };

        return;
    }

    const slotPrefill = {
        staffId:
            lane.kind === "staff" && lane.id !== null ? lane.id : undefined,
        boothId:
            lane.kind === "booth" && lane.id !== null ? lane.id : undefined,
        date: date.value,
        time: minuteToLabel(snappedMinute),
    };

    // 「メニューで空きを確認」中の空き枠クリックは予約の操作なので、予約／予定の選択を挟まず
    // そのメニュー（＋クリックした担当/ブース）を選択済みで予約パネルを開く（Task 11-30）。
    if (shouldFillCreatePanelFromSlot() || previewServiceId.value !== null) {
        fillCreatePanelFromSlot(slotPrefill);

        return;
    }

    openSlotChoicePanel(slotPrefill);
}

function dayLabel(value: string): string {
    return new Intl.DateTimeFormat("ja-JP", {
        month: "numeric",
        day: "numeric",
        weekday: "short",
    }).format(new Date(`${value}T12:00:00`));
}

/** 現在時刻ラインの再計算トリガー。店舗で台帳を開きっぱなしにしても1分ごとに進むよう、
 * Date.now() を直接使わずこの ref を computed の依存にして定期的に更新する。 */
const clockTick = ref(Date.now());
let clockTimer: ReturnType<typeof setInterval> | null = null;

const currentTimeLeft = computed<number | null>(() => {
    if (viewMode.value !== "day") {
        return null;
    }

    const now = new Date(clockTick.value);
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");

    if (date.value !== `${year}-${month}-${day}`) {
        return null;
    }

    const minute = now.getHours() * 60 + now.getMinutes();

    if (minute < openMinute.value || minute > closeMinute.value) {
        return null;
    }

    return (minute - openMinute.value) * pixelsPerMinute.value;
});

/**
 * 「現在時刻」の横スクロール位置。
 * 営業時間の後半なら後ろ側までスクロールし、前半なら先頭のまま動かさない
 * （前半なのに中央寄せすると、まだ来ていない時間ばかりが見えて使いにくいため）。
 */
function scrollToNow(): void {
    const scroller = timelineScrollEl.value;

    if (scroller === null || currentTimeLeft.value === null) {
        return;
    }

    const max = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
    const isLaterHalf =
        currentTimeLeft.value > (closeMinute.value - openMinute.value) * pixelsPerMinute.value / 2;

    if (!isLaterHalf) {
        scroller.scrollLeft = 0;

        return;
    }

    const target = currentTimeLeft.value - scroller.clientWidth / 2;

    scroller.scrollLeft = Math.max(0, Math.min(target, max));
}

function setTimelineViewMode(mode: "now" | "fit"): void {
    timelineViewMode.value = mode;

    if (mode === "now") {
        void nextTick(() => scrollToNow());
    }
}

watch(timelineScrollEl, (el) => {
    if (timelineResizeObserver !== null) {
        timelineResizeObserver.disconnect();
        timelineResizeObserver = null;
    }

    if (el === null) {
        return;
    }

    timelineResizeObserver = new ResizeObserver((entries) => {
        const entry = entries[0];

        if (entry) {
            timelineScrollWidth.value = entry.contentRect.width;
        }
    });
    timelineResizeObserver.observe(el);

    if (timelineViewMode.value === "now") {
        void nextTick(() => scrollToNow());
    }
});

/** 時間軸を「1 時間ごとの見出し（中央寄せ）」として組み立てる。 */
interface HourBlock {
    minute: number;
    label: string;
    left: number;
    width: number;
}

const hourBlocks = computed<HourBlock[]>(() => {
    const blocks: HourBlock[] = [];
    const firstHour = Math.ceil(openMinute.value / 60) * 60;

    for (let minute = firstHour; minute < closeMinute.value; minute += 60) {
        const blockEnd = Math.min(minute + 60, closeMinute.value);
        blocks.push({
            minute,
            label: minuteToLabel(minute),
            left: (minute - openMinute.value) * pixelsPerMinute.value,
            width: (blockEnd - minute) * pixelsPerMinute.value,
        });
    }

    return blocks;
});

/** 30 分の細い目盛り（軸ヘッダー用）。 */
const minorTicks = computed<number[]>(() => {
    const ticks: number[] = [];

    for (
        let minute = openMinute.value;
        minute <= closeMinute.value;
        minute += 30
    ) {
        ticks.push(minute);
    }

    return ticks;
});

/** 10 分のごく薄い目盛り（軸ヘッダー用）。30 分位置と重なるものは除く。 */
const tenTicks = computed<number[]>(() => {
    const ticks: number[] = [];

    for (
        let minute = openMinute.value;
        minute <= closeMinute.value;
        minute += FINE_TICK_MINUTES
    ) {
        if ((minute - openMinute.value) % 30 !== 0) {
            ticks.push(minute);
        }
    }

    return ticks;
});

/**
 * 台帳トラックの 3 段階グリッド（1時間＝濃い / 30分＝中 / 10分＝ごく薄い）を
 * repeating-linear-gradient 1 枚で描く。DOM を増やさず 10 分刻みを可視化する（#9 / §19）。
 */
const gridStyle = computed<Record<string, string>>(() => {
    const ten = FINE_TICK_MINUTES * pixelsPerMinute.value;
    const half = 30 * pixelsPerMinute.value;
    const hour = 60 * pixelsPerMinute.value;
    // 線は各区間の「先頭」に置く。ヘッダーの時間ブロックが border-left（＝ブロック左端）
    // で線を描いているため、末尾に置くと 1px ずれて見える（§ヘッダーと縦線のずれ）。
    const line = (size: number, color: string): string =>
        `repeating-linear-gradient(to right, ${color} 0, ${color} 1px, transparent 1px, transparent ${size}px)`;

    return {
        backgroundImage: [
            line(ten, "rgb(18 25 60 / 4%)"),
            line(half, "rgb(18 25 60 / 9%)"),
            line(hour, "rgb(18 25 60 / 17%)"),
        ].join(", "),
    };
});

/** 予約枠1つぶんの分数（設定値。既定5分）。グリッド・目盛り・ガイドはすべてこれに揃える。 */
const slotUnitMinutes = computed(() =>
    Math.max(props.business_hours.slot_minutes, 1),
);

/**
 * 縦の罫線・細かい目盛りの単位は 10 分で固定する。予約枠は5分だが、5分ごとに
 * 線を引くと密すぎて逆に読めなくなるため。5分の精度はカーソルガイド側で示す。
 */
const FINE_TICK_MINUTES = 10;

/* ───────────── カーソル位置ガイド（今どの時間の上にいるか） ─────────────
 * 5分単位など細かい粒度だと、マウスがどの時刻を指しているのか見た目では分からない。
 * 予約枠と同じ単位にスナップした縦線＋時刻ラベルを出して、クリック前に確認できるようにする。 */
const hoverMinute = ref<number | null>(null);
/** ガイドを出すレーン（スタッフ／ブース行）。全行に出すと、どの行を指しているか逆に分かりにくい。 */
const hoverLaneKey = ref<string | null>(null);

function laneKey(lane: ScheduleLane): string {
    return `${lane.kind}-${lane.id ?? 'unassigned'}`;
}

function onTimelineHover(lane: ScheduleLane, event: MouseEvent): void {
    // ドラッグ中は移動先のゴーストが出るので、ガイドは邪魔になるため出さない。
    if (drag.value !== null || blockDrag.value !== null) {
        hoverMinute.value = null;
        hoverLaneKey.value = null;

        return;
    }

    hoverLaneKey.value = laneKey(lane);

    const canvas = event.currentTarget as HTMLElement;
    const rect = canvas.getBoundingClientRect();
    const raw = openMinute.value + (event.clientX - rect.left) / pixelsPerMinute.value;
    const unit = slotUnitMinutes.value;
    // 枠を帯で塗るので、四捨五入ではなく切り捨てて「今いる枠の開始」に合わせる。
    // 営業開始からの相対で刻むことで、グリッド線と必ず同じ位置に乗る。
    const slotStart =
        openMinute.value +
        Math.floor((raw - openMinute.value) / unit) * unit;

    hoverMinute.value = Math.min(
        Math.max(slotStart, openMinute.value),
        closeMinute.value - unit,
    );
}

function clearTimelineHover(): void {
    hoverMinute.value = null;
    hoverLaneKey.value = null;
}

const hoverLeft = computed<number | null>(() =>
    hoverMinute.value === null
        ? null
        : (hoverMinute.value - openMinute.value) * pixelsPerMinute.value,
);

/** カーソルがいる枠の幅＝予約枠1つぶん（5分）ちょうど。罫線は10分なので、はみ出すことはない。 */
const hoverWidth = computed<number>(
    () => slotUnitMinutes.value * pixelsPerMinute.value,
);

/**
 * 帯の濃さ。「全体表示」では5分が3px程度しかなく、薄いと見落とすため、
 * 細い時はほぼ塗りつぶしにする。拡大時は幅があるので薄くしてカードを隠さない。
 */
const hoverFill = computed<string>(() =>
    hoverWidth.value < 8
        ? 'rgba(var(--v-theme-accent), 0.9)'
        : 'rgba(var(--v-theme-accent), 0.22)',
);

const hoverLabel = computed<string>(() =>
    hoverMinute.value === null ? "" : minuteToLabel(hoverMinute.value),
);

/** その時間に「働いているスタッフ」の user_id 一覧（bookable レーンのみ）。 */
function workingLaneIdsAt(rangeStart: number, rangeEnd: number): Set<number> {
    const ids = new Set<number>();

    for (const lane of lanes.value) {
        if (lane.id === null) {
            continue;
        }

        if (axisMode.value === "booth") {
            ids.add(lane.id); // ブースは営業時間中つねに利用可
            continue;
        }

        const working = props.shifts.some(
            (shift) =>
                shift.staff_id === lane.id &&
                rangeStart >= timeToMinute(shift.start_at) &&
                rangeEnd <= timeToMinute(shift.end_at),
        );

        if (working) {
            ids.add(lane.id);
        }
    }

    return ids;
}

function laneBusyAt(
    laneId: number,
    rangeStart: number,
    rangeEnd: number,
): boolean {
    // 呼び出し元（unbookableSegments）は axis が 'staff'|'booth' のときだけ使う。
    const kind = axisMode.value === "booth" ? "booth" : "staff";

    return reservationsFor({ id: laneId, kind }, date.value).some(
        (reservation) => {
            const rs = timeToMinute(reservation.starts_at.slice(11, 16));
            const re = timeToMinute(reservation.ends_at.slice(11, 16));

            return rs < rangeEnd && re > rangeStart;
        },
    );
}

interface UnbookableSegment {
    left: number;
    width: number;
    kind: "closed" | "full";
}

/** 新規予約が入れられない時間帯（稼働外 / 満席）を帯で示す。 */
const unbookableSegments = computed<UnbookableSegment[]>(() => {
    // 「両方」表示はスタッフ・ブースという別種のリソースが混在するため、
    // 全体「満席」の帯は意味が一意に決まらない。誤解を避けて表示しない。
    if (viewMode.value !== "day" || axisMode.value === "both") {
        return [];
    }

    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const raw: Array<"closed" | "full" | null> = [];

    for (
        let minute = openMinute.value;
        minute < closeMinute.value;
        minute += unit
    ) {
        const segEnd = Math.min(minute + unit, closeMinute.value);
        const workingIds = workingLaneIdsAt(minute, segEnd);

        if (workingIds.size === 0) {
            raw.push("closed");
            continue;
        }

        const allBusy = [...workingIds].every((laneId) =>
            laneBusyAt(laneId, minute, segEnd),
        );
        raw.push(allBusy ? "full" : null);
    }

    const segments: UnbookableSegment[] = [];
    let runKind: "closed" | "full" | null = null;
    let runStartIndex = 0;

    const flush = (endIndex: number): void => {
        if (runKind === null) {
            return;
        }

        const left = runStartIndex * unit * pixelsPerMinute.value;
        const width = (endIndex - runStartIndex) * unit * pixelsPerMinute.value;
        segments.push({ left, width, kind: runKind });
    };

    raw.forEach((kind, index) => {
        if (kind !== runKind) {
            flush(index);
            runKind = kind;
            runStartIndex = index;
        }
    });
    flush(raw.length);

    return segments;
});

/* ───────────────── ドラッグ&ドロップ（時間／担当スタッフ・ブース／日付・§13-17, §27-33） ───────────────── */

const snapMinutes = computed(() =>
    Math.max(props.business_hours.slot_minutes, 5),
);

function isDraggable(reservation: ScheduleReservation): boolean {
    if (!canManage.value || viewMode.value !== "day") {
        return false;
    }

    if (reservation.status !== "confirmed") {
        return false;
    }

    // starts_at はサーバー（app.timezone=UTC）が返す素の日時文字列。'Z' を付けず
    // new Date() に渡すとブラウザのローカルタイムゾーンとして解釈されてしまい、
    // JST 環境では実時刻との比較が最大9時間ズレて「未来の予約なのにドラッグ不可」に
    // なるバグがあったため、明示的に UTC として解釈する。
    return (
        new Date(`${reservation.starts_at.replace(" ", "T")}Z`).getTime() >
        Date.now()
    );
}

interface LaneRect {
    laneId: number | null;
    top: number;
    bottom: number;
}

interface DragState {
    id: number;
    pointerId: number;
    startX: number;
    durationMin: number;
    baseStartMin: number;
    offsetMinutes: number;
    moved: boolean;
    originLaneId: number | null;
    /** 掴んだカードの軸（スタッフ/ブース）。「両方」表示では同じ予約が両軸に
     * 描画されるため、ドロップ先もこの軸のレーンだけに制限する（§26）。 */
    laneKind: "staff" | "booth";
    laneRects: LaneRect[];
    targetLaneId: number | null;
    dateOffsetDays: -1 | 0 | 1 | null;
    pointerX: number;
    pointerY: number;
}

const drag = ref<DragState | null>(null);

interface CrossDateMoveState {
    reservationId: number;
    reservation: ScheduleReservation;
}

const crossDateMove = ref<CrossDateMoveState | null>(null);
const crossDatePointer = reactive({ x: 0, y: 0 });
const cardContextMenuOpen = ref(false);
const cardContextMenuTarget = ref<HTMLElement>();
const cardContextMenuReservation = ref<ScheduleReservation | null>(null);

const crossDateMoveReservation = computed<ScheduleReservation | null>(() => {
    const state = crossDateMove.value;

    if (state === null) {
        return null;
    }

    return (
        props.reservations.find((r) => r.id === state.reservationId) ??
        state.reservation
    );
});

function onCrossDateMouseMove(event: MouseEvent): void {
    crossDatePointer.x = event.clientX;
    crossDatePointer.y = event.clientY;
}

watch(
    () => crossDateMove.value !== null || slotPickGhostVisible.value,
    (tracking) => {
        if (!tracking) {
            window.removeEventListener("mousemove", onCrossDateMouseMove);
        } else {
            window.addEventListener("mousemove", onCrossDateMouseMove);
        }
    },
    { flush: "sync" },
);

onBeforeUnmount(() => {
    window.removeEventListener("mousemove", onCrossDateMouseMove);
});

function onCardContextMenu(
    reservation: ScheduleReservation,
    event: MouseEvent,
): void {
    if (
        !isDraggable(reservation) ||
        crossDateMove.value !== null ||
        pendingMove.value !== null
    ) {
        cardContextMenuOpen.value = false;

        return;
    }

    cardContextMenuTarget.value = event.currentTarget as HTMLElement;
    cardContextMenuReservation.value = reservation;
    cardContextMenuOpen.value = true;
}

function startCrossDateMove(): void {
    const reservation = cardContextMenuReservation.value;

    if (reservation === null || !isDraggable(reservation)) {
        cardContextMenuOpen.value = false;

        return;
    }

    const rect = cardContextMenuTarget.value?.getBoundingClientRect();
    crossDatePointer.x = rect?.right ?? 0;
    crossDatePointer.y = rect?.top ?? 0;
    crossDateMove.value = {
        reservationId: reservation.id,
        reservation,
    };
    cardContextMenuOpen.value = false;

    if (previewServiceId.value === null) {
        previewServiceId.value = reservation.service_id;
    }
}

function cancelCrossDateMove(): void {
    crossDateMove.value = null;
    cardContextMenuOpen.value = false;
    cardContextMenuReservation.value = null;
}

/** ドラッグ中のゴーストカードに出す内容（§16）。 */
const draggedReservation = computed<ScheduleReservation | null>(() => {
    if (drag.value === null || !drag.value.moved) {
        return null;
    }

    return props.reservations.find((r) => r.id === drag.value?.id) ?? null;
});

interface PendingMove {
    reservation: ScheduleReservation;
    newStartMin: number;
    newEndMin: number;
    laneChanged: boolean;
    newLaneId: number | null;
    laneKind: "staff" | "booth";
    /** 変更後の日付（ISO）。ドラッグ中は前日/今日/翌日ボタンへのドロップで ±1日まで、
     * 確認ダイアログ内の「日付を変更」カレンダーで任意の日付まで変更できる（§4）。 */
    targetDate: string;
}

const pendingMove = ref<PendingMove | null>(null);
const moveSubmitting = ref(false);

/** D&D（予約・予定ブロック共通）がサーバー側で拒否された時のトースト。
 * サーバー拒否時はダイアログを閉じてカードを即座に元の位置へ戻し、
 * 理由だけこのトーストで伝える（再読込しないと直らない見た目のズレを防ぐ・§ D&D失敗時ロールバック）。 */
const dndErrorToast = ref<string | null>(null);

// クリックとドラッグの分離（§16, §33）。pointer がこの距離を超えて動いたら drag とみなし、
// その pointerup 直後の click では詳細パネルを開かない。
const DRAG_THRESHOLD_PX = 6;
let suppressNextClick = false;

// 前日／今日／翌日ボタンへドロップすると日付を変更できる（§30）。
const prevDayBtnEl = ref<HTMLElement | null>(null);
const todayBtnEl = ref<HTMLElement | null>(null);
const nextDayBtnEl = ref<HTMLElement | null>(null);

function dateDropTargets(): { el: HTMLElement; offset: -1 | 0 | 1 }[] {
    return [
        prevDayBtnEl.value
            ? { el: prevDayBtnEl.value, offset: -1 as const }
            : null,
        todayBtnEl.value ? { el: todayBtnEl.value, offset: 0 as const } : null,
        nextDayBtnEl.value
            ? { el: nextDayBtnEl.value, offset: 1 as const }
            : null,
    ].filter((v): v is { el: HTMLElement; offset: -1 | 0 | 1 } => v !== null);
}

function hitTestDateDrop(clientX: number, clientY: number): -1 | 0 | 1 | null {
    for (const target of dateDropTargets()) {
        const rect = target.el.getBoundingClientRect();

        if (
            clientX >= rect.left &&
            clientX <= rect.right &&
            clientY >= rect.top &&
            clientY <= rect.bottom
        ) {
            return target.offset;
        }
    }

    return null;
}

/** ドラッグ中／確認待ちのあいだ、そのカードに与える X 方向のオフセット（px）。 */
function dragOffsetXPx(reservation: ScheduleReservation): number {
    if (drag.value?.id === reservation.id) {
        return drag.value.offsetMinutes * pixelsPerMinute.value;
    }

    if (
        pendingMove.value?.reservation.id === reservation.id &&
        pendingMove.value.targetDate === reservation.starts_at.slice(0, 10)
    ) {
        return (
            (pendingMove.value.newStartMin -
                timeToMinute(reservation.starts_at.slice(11, 16))) *
            pixelsPerMinute.value
        );
    }

    return 0;
}

/** レーン（行）の並び順インデックス。ドラッグ中に別スタッフ／ブース行へ視覚的に追従させるため。 */
function laneRowIndex(laneId: number | null): number {
    return lanes.value.findIndex((lane) => lane.id === laneId);
}

/**
 * ドラッグでスタッフ／ブース行を跨いだ場合、時間移動と同じように
 * カードをうっすら縦方向にも追従させる（§16 のゴーストに加え、元カード自体も動かす）。
 */
function dragOffsetYPx(reservation: ScheduleReservation): number {
    if (drag.value?.id !== reservation.id || !drag.value.moved) {
        return 0;
    }

    if (drag.value.targetLaneId === drag.value.originLaneId) {
        return 0;
    }

    const fromIndex = laneRowIndex(drag.value.originLaneId);
    const toIndex = laneRowIndex(drag.value.targetLaneId);

    if (fromIndex === -1 || toIndex === -1) {
        return 0;
    }

    return (toIndex - fromIndex) * timelineTrackHeight.value;
}

/** ドラッグ中のカードが属する行だけ、他の行に重なって見えるよう一時的にクリップを外す。 */
function isDragOriginLane(laneId: number | null): boolean {
    return (
        drag.value !== null &&
        drag.value.moved &&
        drag.value.originLaneId === laneId
    );
}

/** ドラッグでホバー中のドロップ先レーン（自レーン以外）。左側レーンラベルの強調に使う（予約／予定共通・§17）。 */
function isDropTargetLane(laneId: number | null): boolean {
    const active =
        drag.value !== null && drag.value.moved
            ? drag.value
            : blockDrag.value !== null && blockDrag.value.moved
              ? blockDrag.value
              : null;

    return (
        active !== null &&
        active.targetLaneId === laneId &&
        active.targetLaneId !== active.originLaneId
    );
}

/**
 * ドラッグ中の「予約可能そう／不可そう」の見た目ヒント（§18）。
 * ここでは既に画面上にある予約・予定との時間帯重複だけを簡易チェックする
 * （勤務時間・メニュー対応可否まではクライアントで判定しない＝最終判定は必ずサーバー側）。
 */
function dropTargetValidity(laneId: number | null): "valid" | "invalid" | null {
    if (!isDropTargetLane(laneId)) {
        return null;
    }

    const active = drag.value?.moved ? drag.value : blockDrag.value;

    if (active === null) {
        return null;
    }

    const newStart = active.baseStartMin + active.offsetMinutes;
    const newEnd = newStart + active.durationMin;
    const kind: "staff" | "booth" =
        axisMode.value === "booth" ? "booth" : "staff";

    const overlapsReservation = reservationsFor(
        { id: laneId, kind },
        date.value,
    ).some((r) => {
        if (drag.value !== null && r.id === drag.value.id) {
            return false;
        }
        const rs = timeToMinute(r.starts_at.slice(11, 16));
        const re = timeToMinute(r.ends_at.slice(11, 16));

        return rs < newEnd && re > newStart;
    });
    const overlapsBlock = blocksFor({ id: laneId, kind }, date.value).some(
        (b) => {
            if (blockDrag.value !== null && b.id === blockDrag.value.id) {
                return false;
            }
            const bs = timeToMinute(b.start_at);
            const be = timeToMinute(b.end_at);

            return bs < newEnd && be > newStart;
        },
    );

    return overlapsReservation || overlapsBlock ? "invalid" : "valid";
}

const dateDropHoverOffset = ref<-1 | 0 | 1 | null>(null);

function onCardPointerDown(
    reservation: ScheduleReservation,
    lane: ScheduleLane,
    event: PointerEvent,
): void {
    if (
        !isDraggable(reservation) ||
        event.button !== 0 ||
        pendingMove.value !== null ||
        crossDateMove.value !== null
    ) {
        return;
    }

    const startMin = timeToMinute(reservation.starts_at.slice(11, 16));
    const endMin = timeToMinute(reservation.ends_at.slice(11, 16));
    const originLaneId = lane.id;

    // 「両方」表示では同じ予約がスタッフ・ブース両方のレーンに描画されるため、
    // ドロップ先も掴んだカードと同じ軸（kind）のレーンだけに絞る（§26）。
    const laneRects: LaneRect[] = lanes.value
        .filter((l) => l.kind === lane.kind)
        .map((l) => {
            const el = document.querySelector<HTMLElement>(
                `.timeline-lane-label[data-lane-id="${l.id ?? "unassigned"}"]`,
            );
            const rect = el?.getBoundingClientRect();

            return {
                laneId: l.id,
                top: rect?.top ?? 0,
                bottom: rect?.bottom ?? 0,
            };
        });

    drag.value = {
        id: reservation.id,
        pointerId: event.pointerId,
        startX: event.clientX,
        durationMin: endMin - startMin,
        baseStartMin: startMin,
        offsetMinutes: 0,
        moved: false,
        originLaneId,
        laneKind: lane.kind,
        laneRects,
        targetLaneId: originLaneId,
        dateOffsetDays: 0,
        pointerX: event.clientX,
        pointerY: event.clientY,
    };

    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onCardPointerMove(event: PointerEvent): void {
    const state = drag.value;

    if (state === null || event.pointerId !== state.pointerId) {
        return;
    }

    state.pointerX = event.clientX;
    state.pointerY = event.clientY;

    const rawMinutes = (event.clientX - state.startX) / pixelsPerMinute.value;
    const snapped =
        Math.round(rawMinutes / snapMinutes.value) * snapMinutes.value;

    // 開始が営業時間内、かつ終了が閉店を超えないよう丸める。
    const minStart = openMinute.value;
    const maxStart = closeMinute.value - state.durationMin;
    const clampedStart = Math.min(
        Math.max(state.baseStartMin + snapped, minStart),
        maxStart,
    );
    const offset = clampedStart - state.baseStartMin;

    if (offset !== state.offsetMinutes) {
        state.offsetMinutes = offset;
    }

    // 別レーン（別スタッフ／別ブース）へのホバー判定（§29）。
    const hit = state.laneRects.find(
        (rect) => event.clientY >= rect.top && event.clientY <= rect.bottom,
    );

    if (hit !== undefined) {
        state.targetLaneId = hit.laneId;
    }

    // 前日／今日／翌日ボタンへのホバー判定（§30）。
    dateDropHoverOffset.value = hitTestDateDrop(event.clientX, event.clientY);

    if (
        Math.abs(event.clientX - state.startX) > DRAG_THRESHOLD_PX ||
        state.targetLaneId !== state.originLaneId ||
        dateDropHoverOffset.value !== null
    ) {
        state.moved = true;
    }
}

function onCardPointerUp(
    reservation: ScheduleReservation,
    event: PointerEvent,
): void {
    const state = drag.value;

    if (state === null || event.pointerId !== state.pointerId) {
        return;
    }

    try {
        (event.currentTarget as HTMLElement).releasePointerCapture(
            event.pointerId,
        );
    } catch {
        /* noop */
    }

    const dateOffsetDays = hitTestDateDrop(event.clientX, event.clientY) ?? 0;
    dateDropHoverOffset.value = null;

    if (state.moved) {
        // しきい値を超えて動いた → 直後の click（詳細パネル）は無視する。
        suppressNextClick = true;

        const laneChanged = state.targetLaneId !== state.originLaneId;
        const newStartMin =
            dateOffsetDays !== 0
                ? state.baseStartMin
                : state.baseStartMin + state.offsetMinutes;

        if (state.offsetMinutes !== 0 || laneChanged || dateOffsetDays !== 0) {
            pendingMove.value = {
                reservation,
                newStartMin,
                newEndMin: newStartMin + state.durationMin,
                laneChanged,
                newLaneId: state.targetLaneId,
                laneKind: state.laneKind,
                targetDate: shiftDateBy(
                    reservation.starts_at.slice(0, 10),
                    dateOffsetDays,
                ),
            };
        }
    }

    drag.value = null;
}

function onCardClick(reservation: ScheduleReservation): void {
    // ドラッグ直後・時間変更の確認中はパネルを開かない（§16, §33）。
    if (suppressNextClick || pendingMove.value !== null) {
        suppressNextClick = false;

        return;
    }

    openReservationPanel(reservation.id);
}

function cancelMove(): void {
    pendingMove.value = null;
    moveSubmitting.value = false;
    cancelCrossDateMove();
}

function shiftDateBy(iso: string, days: number): string {
    const [y, m, d] = iso.split("-").map(Number);
    const next = new Date(y, m - 1, d);
    next.setDate(next.getDate() + days);

    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
}

function pendingLaneName(
    laneId: number | null,
    kind: "staff" | "booth",
): string {
    if (laneId === null) {
        return kind === "staff" ? "担当なし" : "ブース未割当";
    }

    return (
        lanes.value.find(
            (lane) => lane.kind === kind && lane.id === laneId,
        )?.display_name ?? ""
    );
}

function confirmMove(): void {
    const move = pendingMove.value;

    if (move === null) {
        return;
    }

    const startsAt = `${move.targetDate} ${minuteToLabel(move.newStartMin)}:00`;
    const body: Record<string, string | number | null> = {
        starts_at: startsAt,
        version: move.reservation.version,
    };

    // レーンを変更した場合のみ送る（未指定なら現状維持。§27, §29）。
    // 更新するのは掴んだカードと同じ軸（laneKind）のみ（§26）。
    if (move.laneChanged) {
        if (move.laneKind === "staff") {
            body.staff_id = move.newLaneId;
        } else {
            body.booth_id = move.newLaneId;
        }
    }

    moveSubmitting.value = true;

    router.put(
        `/admin/schedule/reservations/${move.reservation.id}/time`,
        body,
        {
            preserveScroll: true,
            preserveState: true,
            // 予約の競合エラーは「reservation」バッグで返るため、指定して平らな形で受け取る。
            errorBag: "reservation",
            onError: (errors) => {
                dndErrorToast.value =
                    firstErrorMessage(errors) ??
                    MESSAGES.reservation.moveConflict;
                // 失敗時はダイアログを閉じてカードを即座に元の位置へ戻す（再読込不要）。
                pendingMove.value = null;
                moveSubmitting.value = false;
            },
            onSuccess: () => {
                pendingMove.value = null;
                moveSubmitting.value = false;
                cancelCrossDateMove();
            },
            onFinish: () => {
                moveSubmitting.value = false;
            },
        },
    );
}

function pendingBeforeLabel(): string {
    if (pendingMove.value === null) {
        return "";
    }
    const r = pendingMove.value.reservation;

    return `${r.starts_at.slice(0, 10)} ${r.starts_at.slice(11, 16)}〜${r.ends_at.slice(11, 16)}`;
}

function pendingAfterLabel(): string {
    if (pendingMove.value === null) {
        return "";
    }
    const move = pendingMove.value;

    return `${move.targetDate} ${minuteToLabel(move.newStartMin)}〜${minuteToLabel(move.newEndMin)}`;
}

/* ─────────── 予定ブロックの D&D（時間・担当・日付・§40。予約よりシンプル） ─────────── */

interface BlockDragState {
    id: number;
    pointerId: number;
    startX: number;
    durationMin: number;
    baseStartMin: number;
    offsetMinutes: number;
    moved: boolean;
    originLaneId: number | null;
    laneKind: "staff" | "booth";
    laneRects: LaneRect[];
    targetLaneId: number | null;
}

const blockDrag = ref<BlockDragState | null>(null);

interface PendingBlockMove {
    block: ScheduleBlock;
    newStartMin: number;
    newEndMin: number;
    laneChanged: boolean;
    newLaneId: number | null;
    laneKind: "staff" | "booth";
    /** 変更後の日付（ISO）。確認ダイアログ内の「日付を変更」カレンダーで任意の日付へ変更できる（§6）。 */
    targetDate: string;
}

const pendingBlockMove = ref<PendingBlockMove | null>(null);
const blockMoveSubmitting = ref(false);

function isBlockDraggable(): boolean {
    return canManage.value && viewMode.value === "day";
}

function blockDragOffsetXPx(block: ScheduleBlock): number {
    if (blockDrag.value?.id === block.id) {
        return blockDrag.value.offsetMinutes * pixelsPerMinute.value;
    }

    return 0;
}

/** 予約カードと同じく、予定ブロックも別スタッフ／ブース行へドラッグ中は縦にも追従させる。 */
function blockDragOffsetYPx(block: ScheduleBlock): number {
    if (blockDrag.value?.id !== block.id || !blockDrag.value.moved) {
        return 0;
    }

    if (blockDrag.value.targetLaneId === blockDrag.value.originLaneId) {
        return 0;
    }

    const fromIndex = laneRowIndex(blockDrag.value.originLaneId);
    const toIndex = laneRowIndex(blockDrag.value.targetLaneId);

    if (fromIndex === -1 || toIndex === -1) {
        return 0;
    }

    return (toIndex - fromIndex) * timelineTrackHeight.value;
}

function isBlockDragOriginLane(laneId: number | null): boolean {
    return (
        blockDrag.value !== null &&
        blockDrag.value.moved &&
        blockDrag.value.originLaneId === laneId
    );
}

function onBlockPointerDown(
    block: ScheduleBlock,
    lane: ScheduleLane,
    event: PointerEvent,
): void {
    if (
        !isBlockDraggable() ||
        event.button !== 0 ||
        pendingBlockMove.value !== null
    ) {
        return;
    }

    const startMin = timeToMinute(block.start_at);
    const endMin = timeToMinute(block.end_at);
    const originLaneId = lane.id;

    // 予約カードと同様、ドロップ先も掴んだブロックと同じ軸のレーンだけに絞る（§26）。
    const laneRects: LaneRect[] = lanes.value
        .filter((l) => l.kind === lane.kind)
        .map((l) => {
            const el = document.querySelector<HTMLElement>(
                `.timeline-lane-label[data-lane-id="${l.id ?? "unassigned"}"]`,
            );
            const rect = el?.getBoundingClientRect();

            return {
                laneId: l.id,
                top: rect?.top ?? 0,
                bottom: rect?.bottom ?? 0,
            };
        });

    blockDrag.value = {
        id: block.id,
        pointerId: event.pointerId,
        startX: event.clientX,
        durationMin: endMin - startMin,
        baseStartMin: startMin,
        offsetMinutes: 0,
        moved: false,
        originLaneId,
        laneKind: lane.kind,
        laneRects,
        targetLaneId: originLaneId,
    };

    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onBlockPointerMove(event: PointerEvent): void {
    const state = blockDrag.value;

    if (state === null || event.pointerId !== state.pointerId) {
        return;
    }

    const rawMinutes = (event.clientX - state.startX) / pixelsPerMinute.value;
    const snapped =
        Math.round(rawMinutes / snapMinutes.value) * snapMinutes.value;
    const minStart = openMinute.value;
    const maxStart = closeMinute.value - state.durationMin;
    const clampedStart = Math.min(
        Math.max(state.baseStartMin + snapped, minStart),
        maxStart,
    );
    state.offsetMinutes = clampedStart - state.baseStartMin;

    const hit = state.laneRects.find(
        (rect) => event.clientY >= rect.top && event.clientY <= rect.bottom,
    );

    if (hit !== undefined) {
        state.targetLaneId = hit.laneId;
    }

    dateDropHoverOffset.value = hitTestDateDrop(event.clientX, event.clientY);

    if (
        Math.abs(event.clientX - state.startX) > DRAG_THRESHOLD_PX ||
        state.targetLaneId !== state.originLaneId ||
        dateDropHoverOffset.value !== null
    ) {
        state.moved = true;
    }
}

function onBlockPointerUp(block: ScheduleBlock, event: PointerEvent): void {
    const state = blockDrag.value;

    if (state === null || event.pointerId !== state.pointerId) {
        return;
    }

    try {
        (event.currentTarget as HTMLElement).releasePointerCapture(
            event.pointerId,
        );
    } catch {
        /* noop */
    }

    const dateOffsetDays = hitTestDateDrop(event.clientX, event.clientY) ?? 0;
    dateDropHoverOffset.value = null;

    if (state.moved) {
        suppressNextClick = true;
        const laneChanged = state.targetLaneId !== state.originLaneId;
        const newStartMin =
            dateOffsetDays !== 0
                ? state.baseStartMin
                : state.baseStartMin + state.offsetMinutes;

        if (state.offsetMinutes !== 0 || laneChanged || dateOffsetDays !== 0) {
            pendingBlockMove.value = {
                block,
                newStartMin,
                newEndMin: newStartMin + state.durationMin,
                laneChanged,
                newLaneId: state.targetLaneId,
                laneKind: state.laneKind,
                targetDate: shiftDateBy(block.date, dateOffsetDays),
            };
        }
    }

    blockDrag.value = null;
}

function onBlockClick(block: ScheduleBlock): void {
    if (suppressNextClick || pendingBlockMove.value !== null) {
        suppressNextClick = false;

        return;
    }

    openBlockDetailPanel(block.id);
}

function cancelBlockMove(): void {
    pendingBlockMove.value = null;
    blockMoveSubmitting.value = false;
}

function pendingBlockBeforeLabel(): string {
    if (pendingBlockMove.value === null) return "";
    const b = pendingBlockMove.value.block;

    return `${b.date} ${b.start_at}〜${b.end_at}`;
}

function pendingBlockAfterLabel(): string {
    if (pendingBlockMove.value === null) return "";
    const move = pendingBlockMove.value;

    return `${move.targetDate} ${minuteToLabel(move.newStartMin)}〜${minuteToLabel(move.newEndMin)}`;
}

function pendingBlockLaneChangeLabel(): string | null {
    const move = pendingBlockMove.value;

    if (move === null || !move.laneChanged) {
        return null;
    }

    const originLaneId =
        move.laneKind === "staff" ? move.block.staff_id : move.block.booth_id;

    return `${pendingLaneName(originLaneId, move.laneKind)} → ${pendingLaneName(move.newLaneId, move.laneKind)}`;
}

function confirmBlockMove(): void {
    const move = pendingBlockMove.value;

    if (move === null) {
        return;
    }

    const body: Record<string, string | number | null> = {
        work_date: move.targetDate,
        start_at: minuteToLabel(move.newStartMin),
        end_at: minuteToLabel(move.newEndMin),
    };

    if (move.laneChanged) {
        if (move.laneKind === "staff") {
            body.staff_id = move.newLaneId;
            body.booth_id = null;
        } else {
            body.booth_id = move.newLaneId;
            body.staff_id = null;
        }
    }

    blockMoveSubmitting.value = true;

    router.put(`/admin/schedule/blocks/${move.block.id}/time`, body, {
        preserveScroll: true,
        preserveState: true,
        errorBag: "reservation",
        onError: (errors) => {
            dndErrorToast.value =
                firstErrorMessage(errors) ??
                MESSAGES.schedule.blockMoveConflict;
            // 失敗時はダイアログを閉じてカードを即座に元の位置へ戻す（再読込不要）。
            pendingBlockMove.value = null;
            blockMoveSubmitting.value = false;
        },
        onSuccess: () => {
            pendingBlockMove.value = null;
            blockMoveSubmitting.value = false;
        },
        onFinish: () => {
            blockMoveSubmitting.value = false;
        },
    });
}

function pendingLaneChangeLabel(): string | null {
    const move = pendingMove.value;

    if (move === null || !move.laneChanged) {
        return null;
    }

    const originLaneId =
        move.laneKind === "staff"
            ? move.reservation.staff_id
            : move.reservation.booth_id;

    return `${pendingLaneName(originLaneId, move.laneKind)} → ${pendingLaneName(move.newLaneId, move.laneKind)}`;
}

/* ─────────── メニュー別「本当に予約できる開始時刻」プレビュー（§11） ─────────── */

interface PreviewSlot {
    starts_at: string;
    available_staff_ids: number[];
    /** その時刻に空いているブース（with_booths=1 で取得）。 */
    available_booth_ids?: number[];
}

const previewServiceId = ref<number | null>(null);
const previewSlots = ref<PreviewSlot[]>([]);
const previewLoading = ref(false);
let previewRequestId = 0;

const previewService = computed<MenuOption | null>(
    () =>
        props.menu_options.find((m) => m.id === previewServiceId.value) ?? null,
);

async function fetchPreview(): Promise<void> {
    const requestId = ++previewRequestId;
    const serviceId = previewServiceId.value;

    if (serviceId === null || viewMode.value !== "day") {
        previewSlots.value = [];
        previewLoading.value = false;

        return;
    }

    previewLoading.value = true;

    try {
        const params = new URLSearchParams({
            service_id: String(serviceId),
            date: date.value,
            // ブースが全部埋まっている時間は「空き」にしない。ブース行はそのブースが空いている時だけ空き。
            with_booths: "1",
        });
        const response = await fetch(
            `/admin/reservations/availability?${params.toString()}`,
            {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
            },
        );

        const slots = response.ok ? ((await response.json()) as PreviewSlot[]) : [];

        if (requestId === previewRequestId) {
            previewSlots.value = slots;
        }
    } catch {
        if (requestId === previewRequestId) {
            previewSlots.value = [];
        }
    } finally {
        if (requestId === previewRequestId) {
            previewLoading.value = false;
        }
    }
}

watch(
    () => [previewServiceId.value, date.value, viewMode.value] as const,
    () => {
        void fetchPreview();
    },
);

/** 新規予約の通知音（設定 > 通知設定）。サーバーの値が不正なら既定値にする。 */
const notificationSound = computed(() => {
    const sound = props.notification_sound;

    return {
        enabled: sound?.enabled ?? true,
        type: isNotificationSoundType(sound?.type)
            ? sound.type
            : DEFAULT_NOTIFICATION_SOUND,
        volume: sound?.volume ?? DEFAULT_NOTIFICATION_VOLUME,
        repeat: isNotificationRepeatMode(sound?.repeat)
            ? sound.repeat
            : DEFAULT_NOTIFICATION_REPEAT,
    };
});

/** 予約可能な開始「分」の集合。 */
const previewStartMinutes = computed<Set<number>>(() => {
    const set = new Set<number>();

    for (const slot of previewSlots.value) {
        set.add(timeToMinute(slot.starts_at.slice(11, 16)));
    }

    return set;
});

/** 開始「分」→ その時刻から開始できるスタッフ user_id 集合。 */
const previewStaffByMinute = computed<Map<number, Set<number>>>(() => {
    const map = new Map<number, Set<number>>();

    for (const slot of previewSlots.value) {
        map.set(
            timeToMinute(slot.starts_at.slice(11, 16)),
            new Set(slot.available_staff_ids),
        );
    }

    return map;
});

/** 開始「分」→ その時刻に空いているブース id 集合。 */
const previewBoothsByMinute = computed<Map<number, Set<number>>>(() => {
    const map = new Map<number, Set<number>>();

    for (const slot of previewSlots.value) {
        map.set(
            timeToMinute(slot.starts_at.slice(11, 16)),
            new Set(slot.available_booth_ids ?? []),
        );
    }

    return map;
});

/**
 * 選択メニューについて、レーンを「開始できる」帯と「開始できない」帯に分けてまとめる。
 * 判定はサーバー（AvailabilityService）が返した開始可能リストに基づく（§11・フロント推測なし）。
 * 「空いている」を緑、「空いていない」を灰で塗り分け、一目で分かるようにする。
 */
function menuSegments(lane: ScheduleLane): {
    available: ShadeSegment[];
    blocked: ShadeSegment[];
} {
    if (previewServiceId.value === null || previewService.value === null) {
        return { available: [], blocked: [] };
    }

    const laneId = lane.id;
    const service = previewService.value;
    const unit = Math.max(props.business_hours.slot_minutes, 5);
    const staffAxisWithStaff =
        lane.kind === "staff" && service.requires_staff && laneId !== null;
    const available: ShadeSegment[] = [];
    const blocked: ShadeSegment[] = [];
    let runStart: number | null = null;
    let runIsAvailable = false;

    const flush = (endMinute: number): void => {
        if (runStart === null) {
            return;
        }
        (runIsAvailable ? available : blocked).push({
            left: (runStart - openMinute.value) * pixelsPerMinute.value,
            width: (endMinute - runStart) * pixelsPerMinute.value,
        });
        runStart = null;
    };

    for (
        let minute = openMinute.value;
        minute < closeMinute.value;
        minute += unit
    ) {
        // サーバーは「担当（必要なら）と空きブースが揃う時刻」だけを返す。
        // スタッフ行はそのスタッフが空いているか、ブース行はそのブースが空いているかで判定する。
        const canStart =
            minute + service.duration_min <= closeMinute.value &&
            (staffAxisWithStaff
                ? (previewStaffByMinute.value
                      .get(minute)
                      ?.has(laneId as number) ?? false)
                : lane.kind === "booth" && laneId !== null
                  ? (previewBoothsByMinute.value
                        .get(minute)
                        ?.has(laneId) ?? false)
                  : previewStartMinutes.value.has(minute));

        if (runStart === null) {
            runStart = minute;
            runIsAvailable = canStart;
        } else if (canStart !== runIsAvailable) {
            flush(minute);
            runStart = minute;
            runIsAvailable = canStart;
        }
    }

    flush(closeMinute.value);

    return { available, blocked };
}
</script>

<template>
    <Head title="ブッキングボード" />

    <!-- 元々タイトル（予約台帳／10:00〜22:00）があった行。日付移動・日付選択・メニュー空き確認をここに配置する。
         サイドバーアイコンより上、画面の一番左から表示する（幅いっぱい）。 -->
    <div class="schedule-toolbar schedule-topbar mb-1">
        <div class="toolbar-period" role="group" aria-label="表示期間を移動">
            <v-tooltip
                :text="viewMode === 'week' ? '前週' : '前日'"
                location="bottom"
            >
                <template #activator="{ props: tip }">
                    <v-btn
                        v-bind="tip"
                        :ref="
                            (el: any) => {
                                prevDayBtnEl = el?.$el ?? null;
                            }
                        "
                        icon="mdi-chevron-left"
                        variant="outlined"
                        :aria-label="viewMode === 'week' ? '前週' : '前日'"
                        :class="{
                            'toolbar-daydrop--hover':
                                dateDropHoverOffset === -1,
                        }"
                        @click="movePeriod(-1)"
                    />
                </template>
            </v-tooltip>
            <v-btn
                v-if="viewMode === 'day'"
                :ref="
                    (el: any) => {
                        todayBtnEl = el?.$el ?? null;
                    }
                "
                variant="flat"
                color="primary"
                class="toolbar-today"
                :class="{
                    'toolbar-today--current': isViewingToday,
                    'toolbar-daydrop--hover': dateDropHoverOffset === 0,
                }"
                aria-label="今日"
                @click="goToToday"
            >
                今日
            </v-btn>
            <v-tooltip
                :text="viewMode === 'week' ? '翌週' : '翌日'"
                location="bottom"
            >
                <template #activator="{ props: tip }">
                    <v-btn
                        v-bind="tip"
                        :ref="
                            (el: any) => {
                                nextDayBtnEl = el?.$el ?? null;
                            }
                        "
                        icon="mdi-chevron-right"
                        variant="outlined"
                        :aria-label="viewMode === 'week' ? '翌週' : '翌日'"
                        :class="{
                            'toolbar-daydrop--hover': dateDropHoverOffset === 1,
                        }"
                        @click="movePeriod(1)"
                    />
                </template>
            </v-tooltip>
        </div>
        <DateField
            v-model="date"
            class="toolbar-field toolbar-date"
            label="表示日"
            density="compact"
            :clearable="false"
            @update:model-value="navigate"
        />
        <v-select
            v-if="viewMode === 'day'"
            v-model="previewServiceId"
            class="toolbar-field toolbar-staff"
            :items="menu_options"
            item-title="name"
            item-value="id"
            label="メニューで空きを確認"
            density="compact"
            clearable
            hide-details
            :loading="previewLoading"
            :menu-props="{ minWidth: 360, maxWidth: 520 }"
            data-testid="preview-service"
        >
            <!-- メニュー名は省略せず全文を出す（長い名前は折り返す。Task 11-30） -->
            <template #item="{ props: itemProps, item }">
                <v-list-item v-bind="itemProps" :title="undefined" class="preview-service-item">
                    <span class="preview-service-name">{{ item.raw.name }}</span>
                </v-list-item>
            </template>
            <template #selection="{ item }">
                <span class="preview-service-selection" :title="item.raw.name">{{ item.raw.name }}</span>
            </template>
        </v-select>
    </div>

    <v-menu
        v-model="cardContextMenuOpen"
        :target="cardContextMenuTarget"
        :close-on-content-click="true"
        location="bottom start"
    >
        <v-list density="compact">
            <v-list-item
                v-if="
                    cardContextMenuReservation &&
                    isDraggable(cardContextMenuReservation)
                "
                prepend-icon="mdi-calendar-arrow-right"
                title="日付を跨いで変更する"
                @click="startCrossDateMove"
            />
        </v-list>
    </v-menu>

    <div
        v-if="crossDateMoveReservation"
        class="cross-date-ghost"
        :style="{
            left: `${crossDatePointer.x + 14}px`,
            top: `${crossDatePointer.y + 14}px`,
        }"
    >
        <span>
            {{ crossDateMoveReservation.customer_name }} ／
            {{ crossDateMoveReservation.service_name }}
        </span>
        <button
            type="button"
            class="cross-date-ghost__close"
            aria-label="日付跨ぎ移動をキャンセル"
            @click="cancelCrossDateMove"
        >
            ×
        </button>
    </div>

    <div
        v-if="slotPickGhostVisible && crossDateMove === null"
        class="cross-date-ghost"
        :style="{
            left: `${crossDatePointer.x + 14}px`,
            top: `${crossDatePointer.y + 14}px`,
        }"
    >
        <span>{{ slotPickLabel }}</span>
    </div>

    <!-- 日付を跨いで変更中の解除ボタン（再予約の解除バーと同じ形・別色）。 -->
    <div
        v-if="crossDateMoveReservation"
        class="slot-pick-bar slot-pick-bar--move"
        role="status"
    >
        <v-icon icon="mdi-calendar-arrow-right" size="16" />
        <span class="slot-pick-bar__label"
            >{{ crossDateMoveReservation.customer_name }}様 ／
            {{ crossDateMoveReservation.service_name }} の移動先を選択中</span
        >
        <button
            type="button"
            class="slot-pick-bar__release"
            aria-label="日付跨ぎ移動を解除"
            @click="cancelCrossDateMove"
        >
            <v-icon icon="mdi-close" size="14" />
            <span>解除</span>
        </button>
    </div>

    <!-- 再予約（引用）の枠選択モード中の解除ボタン。マウスに追従するラベルの×は
         近づくと一緒に動いて押せないため、画面下に固定で出す。 -->
    <div
        v-if="slotPickActive && crossDateMove === null"
        class="slot-pick-bar"
        role="status"
    >
        <v-icon icon="mdi-calendar-cursor" size="16" />
        <span class="slot-pick-bar__label">{{ slotPickLabel }} の日時を選択中</span>
        <button
            type="button"
            class="slot-pick-bar__release"
            aria-label="枠選択を解除"
            @click="releaseSlotPick"
        >
            <v-icon icon="mdi-close" size="14" />
            <span>解除</span>
        </button>
    </div>

    <div
        ref="boardLayoutEl"
        class="board-layout"
        :class="{
            'board-layout--panel': panelOpen,
            'board-layout--cross-date-move':
                crossDateMove !== null || slotPickGhostVisible,
        }"
    >
        <!-- パネル開閉・よく使う操作のアイコンレール。上のツールバー行の下から始まる。 -->
        <nav class="panel-rail" aria-label="ブッキングボードのパネル操作">
            <v-tooltip
                :text="panelCollapsed ? 'パネルを開く' : 'パネルを閉じる'"
                location="right"
            >
                <template #activator="{ props: tip }">
                    <button
                        v-bind="tip"
                        type="button"
                        class="panel-rail__btn"
                        :aria-label="
                            panelCollapsed ? 'パネルを開く' : 'パネルを閉じる'
                        "
                        @click="togglePanelCollapsed"
                    >
                        <v-icon
                            :icon="
                                panelCollapsed
                                    ? 'mdi-chevron-right'
                                    : 'mdi-chevron-left'
                            "
                            size="19"
                        />
                    </button>
                </template>
            </v-tooltip>
            <v-tooltip text="新規予約" location="right">
                <template #activator="{ props: tip }">
                    <button
                        v-bind="tip"
                        type="button"
                        class="panel-rail__btn"
                        aria-label="新規予約"
                        @click="railOpenReservation"
                    >
                        <v-icon icon="mdi-calendar-plus-outline" size="19" />
                    </button>
                </template>
            </v-tooltip>
            <v-tooltip text="顧客検索" location="right">
                <template #activator="{ props: tip }">
                    <button
                        v-bind="tip"
                        type="button"
                        class="panel-rail__btn"
                        aria-label="顧客検索"
                        @click="railOpenCustomerSearch"
                    >
                        <v-icon icon="mdi-account-search-outline" size="19" />
                    </button>
                </template>
            </v-tooltip>
        </nav>

        <div
            v-if="panelVisible"
            ref="boardPanelEl"
            class="board-layout__panel"
            :style="isNarrowScreen ? undefined : { height: panelMaxHeight }"
        >
            <!-- 顧客検索はPeak Manager同様、他に何を表示していても常に一番上に固定表示する。 -->
            <PanelCustomerSearchBar
                ref="searchBarEl"
                :can-search="canSearchCustomers"
                :highlight="needsCustomerSelection"
                @select="onCustomerSearchSelect"
            />

            <ReservationDetailPanel
                v-if="
                    panelKind === 'detail-reservation' ||
                    panelKind === 'detail-customer'
                "
                :reservation-id="panelReservationId"
                :customer-id="panelCustomerId"
                :reference-date="date"
                :can-go-back="canGoBackPanel"
                @close="closePanel"
                @back="goBackPanel"
                @navigate="onHistoryNavigate"
                @create="openCreatePanel()"
                @rebook="onRebook"
                @highlight="onPanelHighlight"
                @view-customer="(p) => openCustomerPanel(p.customerId)"
            />
            <CustomerSearchPanel
                v-else-if="panelKind === 'search'"
                :can-search="canSearchCustomers"
                :can-create="canManage"
                :can-go-back="canGoBackPanel"
                @close="closePanel"
                @back="goBackPanel"
                @create="openCreatePanel()"
            />
            <NewReservationPanel
                v-else-if="panelKind === 'create'"
                :key="createPanelKey"
                :services="menu_options"
                :popular-service-ids="popular_service_ids"
                :staff="staff_options"
                :booths="booth_options"
                :prefill="create_prefill"
                :draft="reservationDraft"
                :return-query="returnQuery"
                :can-go-back="canGoBackPanel"
                @close="closePanel"
                @back="goBackPanel"
                :after-create="onReservationCreated"
                @switch-to-block="onSwitchToBlock"
            />
            <ScheduleBlockCreatePanel
                v-else-if="panelKind === 'block-create'"
                :staff="staff_options"
                :business-hours="business_hours"
                :prefill="block_create_prefill"
                :draft="blockDraft"
                :return-query="returnQuery"
                :can-go-back="canGoBackPanel"
                @close="closePanel"
                @back="goBackPanel"
                :after-create="onBlockCreated"
                @switch-to-reservation="onSwitchToReservation"
            />
            <ScheduleBlockDetailPanel
                v-else-if="panelKind === 'block-detail' && activeBlock"
                :block="activeBlock"
                :staff="staff_options"
                :booths="booth_options"
                :business-hours="business_hours"
                :return-query="returnQuery"
                :can-go-back="canGoBackPanel"
                @close="closePanel"
                @back="goBackPanel"
                @deleted="clearPanelHistory"
            />
            <SlotChoicePanel
                v-else-if="panelKind === 'slot-choice'"
                :date="create_prefill.date ?? date"
                :time="create_prefill.time"
                :staff-name="slotChoiceStaffName"
                :can-go-back="canGoBackPanel"
                @close="closePanel"
                @back="goBackPanel"
                @choose-reservation="onSwitchToReservation"
                @choose-block="onSwitchToBlock"
            />
        </div>

        <div ref="boardMainEl" class="board-layout__main">

            <v-card class="mb-2">
                <v-card-text class="schedule-toolbar">
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
                            <v-btn value="both">両方</v-btn>
                        </v-btn-toggle>
                    </div>

                    <!-- 出勤予定がないため台帳に出していないスタッフ（勤務枠・店舗カレンダーが正本）。 -->
                    <v-tooltip
                        v-if="axisMode !== 'booth' && offStaff.length > 0"
                        location="bottom"
                        :text="offStaff.map((member) => member.display_name).join('、')"
                    >
                        <template #activator="{ props: tip }">
                            <span
                                v-bind="tip"
                                class="toolbar-off-staff"
                                data-testid="off-staff-count"
                            >
                                <v-icon icon="mdi-account-off-outline" size="16" />
                                {{ MESSAGES.schedule.offStaffLabel }}
                                {{ offStaff.length }}名
                            </span>
                        </template>
                    </v-tooltip>

                    <v-btn-toggle
                        v-if="viewMode === 'day' && lanes.length > 0"
                        :model-value="timelineViewMode"
                        class="toolbar-mode--right"
                        mandatory
                        color="primary"
                        variant="outlined"
                        density="compact"
                        aria-label="時間軸の表示切り替え"
                        @update:model-value="setTimelineViewMode"
                    >
                        <v-btn
                            value="now"
                            prepend-icon="mdi-crosshairs-gps"
                            size="small"
                        >
                            現在時刻
                        </v-btn>
                        <v-btn
                            value="fit"
                            prepend-icon="mdi-arrow-expand-horizontal"
                            size="small"
                        >
                            全体表示
                        </v-btn>
                    </v-btn-toggle>
                </v-card-text>
            </v-card>

            <v-alert v-if="lanes.length === 0" type="info" variant="tonal">
                {{
                    axisMode === "booth"
                        ? MESSAGES.schedule.noVisibleBooths
                        : offStaff.length > 0
                          ? MESSAGES.schedule.noWorkingStaff
                          : MESSAGES.schedule.noBookableStaff
                }}
            </v-alert>

            <template v-else>
                <div
                    v-if="viewMode === 'day' && previewServiceId !== null"
                    class="schedule-legend"
                >
                    <span class="schedule-legend__item">
                        <span
                            class="schedule-legend__swatch is-menu-available"
                            aria-hidden="true"
                        />予約可能枠
                    </span>
                    <span class="schedule-legend__item">
                        <span
                            class="schedule-legend__swatch is-menu-blocked"
                            aria-hidden="true"
                        />予約不可
                    </span>
                    <span class="schedule-legend__item">
                        <span
                            class="schedule-legend__swatch is-closed"
                            aria-hidden="true"
                        />出勤者なし
                    </span>
                    <span class="schedule-legend__item">
                        <span
                            class="schedule-legend__swatch is-full"
                            aria-hidden="true"
                        />満枠
                    </span>
                </div>

                <v-card class="schedule-card">
                    <div
                        v-if="viewMode === 'day'"
                        ref="timelineShellEl"
                        class="timeline-shell"
                    >
                        <div class="timeline-lane-column">
                            <div class="timeline-corner">
                                {{
                                    axisMode === "booth" ? "ブース" : "スタッフ"
                                }}
                            </div>
                            <template
                                v-for="(row, rowIndex) in displayRows"
                                :key="
                                    row.kind === 'header'
                                        ? `h-${row.sectionKind}`
                                        : `${row.lane.kind}-${row.lane.id ?? 'unassigned'}`
                                "
                            >
                                <div
                                    v-if="row.kind === 'header'"
                                    class="timeline-section-header"
                                    :style="{
                                        height: `${sectionHeaderHeight}px`,
                                    }"
                                >
                                    <v-icon :icon="row.icon" size="14" />
                                    <span>{{ row.label }}</span>
                                </div>
                                <button
                                    v-else-if="
                                        row.lane.kind === 'staff' &&
                                        row.lane.id !== null
                                    "
                                    type="button"
                                    class="timeline-lane-label timeline-lane-label--link"
                                    :class="{
                                        'timeline-lane-label--drop':
                                            isDropTargetLane(row.lane.id),
                                        'timeline-lane-label--drop-valid':
                                            dropTargetValidity(row.lane.id) ===
                                            'valid',
                                        'timeline-lane-label--drop-invalid':
                                            dropTargetValidity(row.lane.id) ===
                                            'invalid',
                                    }"
                                    :data-lane-id="row.lane.id ?? 'unassigned'"
                                    :style="{
                                        height: `${timelineTrackHeight}px`,
                                    }"
                                    :title="`${row.lane.display_name} の勤務枠を開く`"
                                    @click="goToStaffShifts(row.lane.id)"
                                >
                                    <span
                                        class="timeline-lane-dot"
                                        :style="{
                                            backgroundColor: row.lane.color,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="timeline-lane-name"
                                        :title="row.lane.off_duty ? MESSAGES.schedule.offDutyTitle : undefined"
                                    >
                                        <span class="timeline-lane-name__text">{{
                                            row.lane.display_name
                                        }}</span>
                                        <span
                                            v-if="row.lane.off_duty"
                                            class="lane-off-duty"
                                            data-testid="lane-off-duty"
                                            >{{ MESSAGES.schedule.offDutyBadge }}</span
                                        >
                                    </span>
                                    <v-icon
                                        icon="mdi-calendar-clock-outline"
                                        size="14"
                                        class="timeline-lane-label__icon"
                                    />
                                </button>
                                <div
                                    v-else
                                    class="timeline-lane-label"
                                    :class="{
                                        'timeline-lane-label--unassigned':
                                            row.lane.id === null,
                                        'timeline-lane-label--drop':
                                            isDropTargetLane(row.lane.id),
                                        'timeline-lane-label--drop-valid':
                                            dropTargetValidity(row.lane.id) ===
                                            'valid',
                                        'timeline-lane-label--drop-invalid':
                                            dropTargetValidity(row.lane.id) ===
                                            'invalid',
                                    }"
                                    :data-lane-id="row.lane.id ?? 'unassigned'"
                                    :style="{
                                        height: `${timelineTrackHeight}px`,
                                    }"
                                    :title="row.lane.display_name"
                                >
                                    <span
                                        class="timeline-lane-dot"
                                        :style="{
                                            backgroundColor: row.lane.color,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="timeline-lane-name"
                                        :title="row.lane.off_duty ? MESSAGES.schedule.offDutyTitle : undefined"
                                    >
                                        <span class="timeline-lane-name__text">{{
                                            row.lane.display_name
                                        }}</span>
                                        <span
                                            v-if="row.lane.off_duty"
                                            class="lane-off-duty"
                                            data-testid="lane-off-duty"
                                            >{{ MESSAGES.schedule.offDutyBadge }}</span
                                        >
                                    </span>
                                </div>
                            </template>
                        </div>

                        <div
                            ref="timelineScrollEl"
                            class="timeline-scroll"
                            tabindex="0"
                            aria-label="時間軸。横方向にスクロールできます。"
                        >
                            <div
                                class="timeline-canvas"
                                :style="{ width: `${timelineWidth}px` }"
                            >

                                <div class="timeline-axis">
                                    <div
                                        v-for="block in hourBlocks"
                                        :key="block.minute"
                                        class="timeline-hour"
                                        :style="{
                                            left: `${block.left}px`,
                                            width: `${block.width}px`,
                                        }"
                                    >
                                        <span class="timeline-hour__label">{{
                                            block.label
                                        }}</span>
                                    </div>
                                    <div
                                        v-for="tick in tenTicks"
                                        :key="`tt-${tick}`"
                                        class="timeline-minor-tick timeline-minor-tick--ten"
                                        :style="{
                                            left: `${(tick - openMinute) * pixelsPerMinute}px`,
                                        }"
                                    />
                                    <div
                                        v-for="tick in minorTicks"
                                        :key="`mt-${tick}`"
                                        class="timeline-minor-tick"
                                        :style="{
                                            left: `${(tick - openMinute) * pixelsPerMinute}px`,
                                        }"
                                    />
                                </div>

                                <div
                                    class="timeline-unbookable"
                                    aria-hidden="true"
                                >
                                    <div
                                        v-for="(
                                            segment, index
                                        ) in unbookableSegments"
                                        :key="`ub-${index}`"
                                        class="timeline-unbookable__seg"
                                        :class="`is-${segment.kind}`"
                                        :style="{
                                            left: `${segment.left}px`,
                                            width: `${segment.width}px`,
                                        }"
                                    />
                                </div>

                                <template
                                    v-for="(row, rowIndex) in displayRows"
                                    :key="
                                        row.kind === 'header'
                                            ? `th-${row.sectionKind}`
                                            : `${row.lane.kind}-${row.lane.id ?? 'unassigned'}`
                                    "
                                >
                                    <div
                                        v-if="row.kind === 'header'"
                                        class="timeline-track-section-header"
                                        :style="{
                                            height: `${sectionHeaderHeight}px`,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <div
                                        v-else
                                        class="timeline-track"
                                        :class="{
                                            'timeline-track--drag-origin':
                                                isDragOriginLane(row.lane.id) ||
                                                isBlockDragOriginLane(
                                                    row.lane.id,
                                                ),
                                        }"
                                        :style="[
                                            {
                                                height: `${timelineTrackHeight}px`,
                                            },
                                            gridStyle,
                                        ]"
                                    >
                                        <!-- カーソルがいる枠のガイド。指している行にだけ出す。 -->
                                        <div
                                            v-if="
                                                hoverLeft !== null &&
                                                hoverLaneKey ===
                                                    laneKey(row.lane)
                                            "
                                            class="hover-time-line"
                                            :style="{
                                                left: `${hoverLeft}px`,
                                                width: `${hoverWidth}px`,
                                                background: hoverFill,
                                            }"
                                            aria-hidden="true"
                                        >
                                            <span
                                                class="hover-time-line__label"
                                                >{{ hoverLabel }}</span
                                            >
                                        </div>

                                        <template
                                            v-if="row.lane.kind === 'staff'"
                                        >
                                            <div
                                                v-for="(
                                                    segment, index
                                                ) in nonWorkingSegments(
                                                    row.lane.id,
                                                )"
                                                :key="`shade-${index}`"
                                                class="non-working"
                                                :style="{
                                                    left: `${segment.left}px`,
                                                    width: `${segment.width}px`,
                                                }"
                                            />
                                        </template>
                                        <div
                                            v-for="(
                                                segment, index
                                            ) in menuSegments(row.lane)
                                                .available"
                                            :key="`menu-ok-${index}`"
                                            class="menu-available"
                                            :style="{
                                                left: `${segment.left}px`,
                                                width: `${segment.width}px`,
                                            }"
                                            :title="`${previewService?.name ?? ''}はこの時間から開始できます`"
                                        />
                                        <div
                                            v-for="(
                                                segment, index
                                            ) in menuSegments(row.lane).blocked"
                                            :key="`menu-ng-${index}`"
                                            class="menu-blocked"
                                            :style="{
                                                left: `${segment.left}px`,
                                                width: `${segment.width}px`,
                                            }"
                                            :title="cannotStartAtTimeMessage(previewService?.name ?? '')"
                                        />
                                        <div
                                            class="timeline-empty-click"
                                            aria-hidden="true"
                                            @click="
                                                onTrackClick(row.lane, $event)
                                            "
                                            @mousemove="
                                                onTimelineHover(row.lane, $event)
                                            "
                                            @mouseleave="clearTimelineHover"
                                        />
                                        <button
                                            v-for="reservation in reservationsFor(
                                                row.lane,
                                                date,
                                            )"
                                            :key="reservation.id"
                                            type="button"
                                            class="reservation-card"
                                            :data-reservation-id="
                                                reservation.id
                                            "
                                            :class="{
                                                'reservation-card--draggable':
                                                    isDraggable(reservation),
                                                'reservation-card--dragging':
                                                    drag?.id ===
                                                        reservation.id &&
                                                    drag?.moved,
                                                'reservation-card--pending':
                                                    pendingMove?.reservation
                                                        .id === reservation.id,
                                                'reservation-card--selected':
                                                    panelReservationId ===
                                                    reservation.id,
                                                'reservation-card--flash':
                                                    highlightReservationId ===
                                                    reservation.id,
                                            }"
                                            :style="[
                                                reservationStyle(reservation),
                                                {
                                                    borderLeftColor:
                                                        reservation.service_color,
                                                    backgroundColor:
                                                        menuTintBackground(
                                                            reservation.service_color,
                                                        ),
                                                },
                                                dragOffsetXPx(reservation) !==
                                                    0 ||
                                                dragOffsetYPx(reservation) !== 0
                                                    ? {
                                                          transform: `translate(${dragOffsetXPx(reservation)}px, ${dragOffsetYPx(reservation)}px)`,
                                                      }
                                                    : {},
                                            ]"
                                            @pointerdown="
                                                onCardPointerDown(
                                                    reservation,
                                                    row.lane,
                                                    $event,
                                                )
                                            "
                                            @pointermove="
                                                onCardPointerMove($event)
                                            "
                                            @pointerup="
                                                onCardPointerUp(
                                                    reservation,
                                                    $event,
                                                )
                                            "
                                            @click="onCardClick(reservation)"
                                            @contextmenu.prevent="
                                                onCardContextMenu(
                                                    reservation,
                                                    $event,
                                                )
                                            "
                                        >
                                            <!-- 終了後インターバル（予約の後ろの別区間。次の予約はここから後） -->
                                            <span
                                                v-if="(reservation.buffer_min ?? 0) > 0"
                                                class="reservation-buffer"
                                                :style="bufferStyle(reservation)"
                                                :title="MESSAGES.schedule.bufferSegment.replace('{min}', String(reservation.buffer_min))"
                                                aria-hidden="true"
                                            />
                                            <span
                                                v-if="
                                                    drag?.id ===
                                                        reservation.id &&
                                                    drag?.moved
                                                "
                                                class="reservation-dragtip"
                                            >
                                                {{
                                                    minuteToLabel(
                                                        drag.baseStartMin +
                                                            drag.offsetMinutes,
                                                    )
                                                }}〜{{
                                                    minuteToLabel(
                                                        drag.baseStartMin +
                                                            drag.offsetMinutes +
                                                            drag.durationMin,
                                                    )
                                                }}
                                            </span>

                                            <!-- 行1：性別・顧客名 …… 状態アイコン -->
                                            <span class="reservation-topline">
                                                <span
                                                    class="reservation-nameline"
                                                >
                                                    <span
                                                        v-if="
                                                            genderLabel(
                                                                reservation.customer_gender,
                                                            )
                                                        "
                                                        :class="
                                                            genderClass(
                                                                reservation.customer_gender,
                                                            )
                                                        "
                                                        :title="
                                                            reservation.customer_gender ===
                                                            'male'
                                                                ? '男性'
                                                                : '女性'
                                                        "
                                                        >{{
                                                            genderLabel(
                                                                reservation.customer_gender,
                                                            )
                                                        }}</span
                                                    >
                                                    <span
                                                        class="reservation-customer"
                                                        :title="
                                                            reservation.customer_name
                                                        "
                                                    >
                                                        {{
                                                            reservation.customer_name
                                                        }}
                                                    </span>
                                                </span>
                                                <v-tooltip
                                                    :text="
                                                        statusLabel(
                                                            reservation.status,
                                                        )
                                                    "
                                                    location="top"
                                                >
                                                    <template
                                                        #activator="{
                                                            props: tip,
                                                        }"
                                                    >
                                                        <v-icon
                                                            v-bind="tip"
                                                            :icon="
                                                                statusIcon(
                                                                    reservation.status,
                                                                )
                                                            "
                                                            size="15"
                                                            class="reservation-status-icon"
                                                            :style="{
                                                                color: `rgb(var(--v-theme-${statusColor(reservation.status)}))`,
                                                            }"
                                                            :aria-label="
                                                                statusLabel(
                                                                    reservation.status,
                                                                )
                                                            "
                                                        />
                                                    </template>
                                                </v-tooltip>
                                            </span>

                                            <!-- 行2：（新規のみ）新規バッジ／時刻 -->
                                            <span class="reservation-timeline">
                                                <span
                                                    v-if="
                                                        reservation.is_new_customer
                                                    "
                                                    class="reservation-badge reservation-badge--new"
                                                    >新</span
                                                >
                                                <span class="reservation-time">
                                                    {{
                                                        reservation.starts_at.slice(
                                                            11,
                                                            16,
                                                        )
                                                    }}–{{
                                                        serviceEndLabel(reservation)
                                                    }}
                                                </span>
                                            </span>

                                            <!-- 行3：（指名ありのみ）指名バッジ／メニュー -->
                                            <span
                                                class="reservation-service-line"
                                            >
                                                <v-tooltip
                                                    v-if="
                                                        reservation.is_staff_requested
                                                    "
                                                    text="指名予約"
                                                    location="bottom"
                                                >
                                                    <template
                                                        #activator="{
                                                            props: tip,
                                                        }"
                                                    >
                                                        <span
                                                            v-bind="tip"
                                                            class="reservation-badge reservation-badge--nomination"
                                                        >
                                                            <v-icon
                                                                icon="mdi-hand-pointing-right"
                                                                size="10"
                                                            />指名
                                                        </span>
                                                    </template>
                                                </v-tooltip>
                                                <span
                                                    class="reservation-service"
                                                    :title="
                                                        reservation.service_name
                                                    "
                                                >
                                                    {{
                                                        reservation.service_name
                                                    }}
                                                </span>
                                            </span>
                                        </button>

                                        <!-- 予定ブロック（休憩・他業務等）。予約カードとは明確に区別する（§27-46） -->
                                        <button
                                            v-for="block in blocksFor(
                                                row.lane,
                                                date,
                                            )"
                                            :key="`block-${block.id}`"
                                            type="button"
                                            :title="`${block.title ?? block.type_label} ${block.start_at}–${block.end_at}`"
                                            class="schedule-block"
                                            :class="[
                                                `schedule-block--${blockColor(block.type)}`,
                                                {
                                                    'schedule-block--draggable':
                                                        isBlockDraggable(),
                                                    'schedule-block--dragging':
                                                        blockDrag?.id ===
                                                            block.id &&
                                                        blockDrag?.moved,
                                                    'schedule-block--pending':
                                                        pendingBlockMove?.block
                                                            .id === block.id,
                                                },
                                            ]"
                                            :style="[
                                                blockStyle(block),
                                                blockDragOffsetXPx(block) !==
                                                    0 ||
                                                blockDragOffsetYPx(block) !== 0
                                                    ? {
                                                          transform: `translate(${blockDragOffsetXPx(block)}px, ${blockDragOffsetYPx(block)}px)`,
                                                      }
                                                    : {},
                                            ]"
                                            @pointerdown="
                                                onBlockPointerDown(
                                                    block,
                                                    row.lane,
                                                    $event,
                                                )
                                            "
                                            @pointermove="
                                                onBlockPointerMove($event)
                                            "
                                            @pointerup="
                                                onBlockPointerUp(block, $event)
                                            "
                                            @click="onBlockClick(block)"
                                        >
                                            <span
                                                class="schedule-block__head"
                                            >
                                                <v-icon
                                                    :icon="blockIcon(block.type)"
                                                    size="12"
                                                />
                                                <span
                                                    class="schedule-block__label"
                                                    >{{
                                                        block.title ??
                                                        block.type_label
                                                    }}</span
                                                >
                                            </span>
                                            <span class="schedule-block__time"
                                                >{{ block.start_at }}–{{
                                                    block.end_at
                                                }}</span
                                            >
                                        </button>
                                    </div>
                                </template>

                                <div
                                    v-if="currentTimeLeft !== null"
                                    class="current-time-line"
                                    :style="{ left: `${currentTimeLeft}px` }"
                                    aria-label="現在時刻"
                                />
                            </div>
                        </div>
                    </div>

                    <div v-else class="week-board-wrap">
                        <div
                            class="week-board"
                            :style="{
                                gridTemplateColumns: `148px repeat(${days.length}, minmax(120px, 1fr))`,
                            }"
                        >
                            <div class="week-board__corner" />
                            <div
                                v-for="day in days"
                                :key="`head-${day}`"
                                class="week-board__daycol"
                                :class="{
                                    'week-board__daycol--today':
                                        day === todayIso(),
                                }"
                            >
                                {{ dayLabel(day) }}
                            </div>

                            <template
                                v-for="lane in lanes"
                                :key="`${lane.kind}-${lane.id ?? 'unassigned'}`"
                            >
                                <div class="week-board__lanename">
                                    <span
                                        class="timeline-lane-dot"
                                        :style="{ backgroundColor: lane.color }"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="timeline-lane-name"
                                        :title="lane.off_duty ? MESSAGES.schedule.offDutyTitle : undefined"
                                    >
                                        <span class="timeline-lane-name__text">{{
                                            lane.display_name
                                        }}</span>
                                        <span
                                            v-if="lane.off_duty"
                                            class="lane-off-duty"
                                            >{{ MESSAGES.schedule.offDutyBadge }}</span
                                        >
                                    </span>
                                </div>

                                <div
                                    v-for="day in days"
                                    :key="`${lane.kind}-${lane.id ?? 'unassigned'}-${day}`"
                                    class="week-board__cell"
                                    :class="{
                                        'week-board__cell--today':
                                            day === todayIso(),
                                    }"
                                    role="button"
                                    tabindex="0"
                                    :aria-label="`${dayLabel(day)} ${lane.display_name} の空き枠へ予約または予定を追加`"
                                    @click="onWeekCellClick(lane, day, $event)"
                                    @keydown.enter="
                                        onWeekCellClick(
                                            lane,
                                            day,
                                            $event as unknown as MouseEvent,
                                        )
                                    "
                                >
                                    <div
                                        v-for="(
                                            rect, idx
                                        ) in weekNonWorkingRects(lane, day)"
                                        :key="`nw-${idx}`"
                                        class="week-board__nonworking"
                                        :style="weekRectStyle(rect)"
                                        aria-hidden="true"
                                    />

                                    <button
                                        v-for="block in blocksFor(lane, day)"
                                        :key="`b-${block.id}`"
                                        type="button"
                                        class="week-board__block"
                                        :style="
                                            weekRectStyle(
                                                weekBarRect(
                                                    timeToMinute(
                                                        block.start_at,
                                                    ),
                                                    timeToMinute(block.end_at),
                                                ),
                                            )
                                        "
                                        :title="weekBlockTooltip(block)"
                                        @click.stop="
                                            openBlockDetailPanel(block.id)
                                        "
                                    >
                                        <v-icon
                                            :icon="blockIcon(block.type)"
                                            size="10"
                                        />
                                    </button>

                                    <button
                                        v-for="reservation in reservationsFor(
                                            lane,
                                            day,
                                        )"
                                        :key="`r-${reservation.id}`"
                                        type="button"
                                        class="week-board__bar"
                                        :class="{
                                            'week-board__bar--selected':
                                                panelReservationId ===
                                                reservation.id,
                                        }"
                                        :style="{
                                            ...weekRectStyle(
                                                weekBarRect(
                                                    timeToMinute(
                                                        reservation.starts_at.slice(
                                                            11,
                                                            16,
                                                        ),
                                                    ),
                                                    timeToMinute(
                                                        reservation.ends_at.slice(
                                                            11,
                                                            16,
                                                        ),
                                                    ),
                                                ),
                                            ),
                                            background: menuTintBackground(
                                                reservation.service_color,
                                            ),
                                            borderLeftColor:
                                                reservation.service_color,
                                        }"
                                        :title="
                                            weekReservationTooltip(reservation)
                                        "
                                        @click.stop="
                                            openReservationPanel(reservation.id)
                                        "
                                    >
                                        <span class="week-board__bar-name">{{
                                            reservation.customer_name
                                        }}</span>
                                    </button>

                                    <span
                                        v-if="reservationsFor(lane, day).length"
                                        class="week-board__count"
                                    >
                                        {{
                                            reservationsFor(lane, day).length
                                        }}件
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>
                </v-card>

                <!-- 本日の集計（§23-24, §48）。台帳の下に置き、上部は操作だけに集中させる。巨大なKPIカードは並べない。 -->
                <div v-if="summary" ref="dailySummaryEl">
                <v-card
                    variant="outlined"
                    class="daily-summary mt-2"
                >
                    <div class="daily-summary__title">本日の集計</div>
                    <div class="daily-summary__row">
                        <div class="daily-summary__item">
                            <span class="daily-summary__value">{{
                                summary.total
                            }}</span>
                            <span class="daily-summary__label">予約</span>
                        </div>
                        <div class="daily-summary__item">
                            <span class="daily-summary__value">{{
                                summary.completed
                            }}</span>
                            <span class="daily-summary__label">来店完了</span>
                        </div>
                        <div class="daily-summary__item">
                            <span class="daily-summary__value">{{
                                summary.new_customers
                            }}</span>
                            <span class="daily-summary__label">新規</span>
                        </div>
                        <div class="daily-summary__item">
                            <span class="daily-summary__value">{{
                                summary.repeat_customers
                            }}</span>
                            <span class="daily-summary__label">リピーター</span>
                        </div>
                        <div class="daily-summary__item">
                            <span class="daily-summary__value">{{
                                summary.canceled
                            }}</span>
                            <span class="daily-summary__label">キャンセル</span>
                        </div>
                        <div class="daily-summary__item">
                            <span class="daily-summary__value">{{
                                summary.no_show
                            }}</span>
                            <span class="daily-summary__label"
                                >無断キャンセル</span
                            >
                        </div>
                        <div
                            class="daily-summary__divider"
                            aria-hidden="true"
                        />
                        <div
                            v-if="summary.revenue !== null"
                            class="daily-summary__item daily-summary__item--revenue"
                        >
                            <span class="daily-summary__value"
                                >¥{{
                                    summary.revenue.toLocaleString("ja-JP")
                                }}</span
                            >
                            <span class="daily-summary__label">売上</span>
                        </div>
                    </div>
                </v-card>
                </div>
            </template>
        </div>
        <!-- /.board-layout__main -->
    </div>
    <!-- /.board-layout -->

    <div
        v-if="panelVisible"
        class="board-layout__scrim"
        aria-hidden="true"
        @click="panelCollapsed = true"
    />

    <!-- D&D 時間変更の確認（誤操作防止・§14） -->
    <v-dialog
        :model-value="pendingMove !== null"
        max-width="420"
        @update:model-value="
            (v) => {
                if (!v) cancelMove();
            }
        "
    >
        <v-card v-if="pendingMove">
            <v-card-title class="text-subtitle-1 font-weight-bold"
                >{{ MESSAGES.reservation.confirmMove }}</v-card-title
            >
            <v-card-text>
                <div class="mb-3">
                    <div class="font-weight-medium">
                        {{ pendingMove.reservation.customer_name }}
                    </div>
                    <div class="text-body-2 text-medium-emphasis">
                        {{ pendingMove.reservation.service_name }}
                    </div>
                </div>
                <div class="ark-move-compare">
                    <div>
                        <div class="text-caption text-medium-emphasis">
                            変更前
                        </div>
                        <div class="text-body-1">
                            {{ pendingBeforeLabel() }}
                        </div>
                    </div>
                    <v-icon icon="mdi-arrow-right" class="mx-2" />
                    <div>
                        <div class="text-caption text-medium-emphasis">
                            変更後
                        </div>
                        <div class="text-body-1 font-weight-bold text-primary">
                            {{ pendingAfterLabel() }}
                        </div>
                    </div>
                </div>
                <div v-if="pendingLaneChangeLabel()" class="text-body-2 mt-3">
                    <v-icon
                        icon="mdi-account-switch-outline"
                        size="16"
                        class="mr-1"
                    />
                    {{ pendingLaneChangeLabel() }}
                </div>
                <!-- 任意の日付へ変更（§4）。前日/今日/翌日ボタンへのドロップは既存どおり ±1日。 -->
                <v-menu :close-on-content-click="false" location="bottom start">
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            variant="text"
                            size="small"
                            color="accent"
                            prepend-icon="mdi-calendar-edit-outline"
                            class="mt-3"
                        >
                            日付を変更（{{ dayLabel(pendingMove.targetDate) }}）
                        </v-btn>
                    </template>
                    <v-card>
                        <ArkCalendar v-model="pendingMove.targetDate" />
                    </v-card>
                </v-menu>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn
                    variant="text"
                    :disabled="moveSubmitting"
                    @click="cancelMove"
                    >キャンセル</v-btn
                >
                <v-btn
                    color="primary"
                    variant="flat"
                    :loading="moveSubmitting"
                    @click="confirmMove"
                >
                    変更する
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- 予定ブロックD&Dの確認（§40） -->
    <v-dialog
        :model-value="pendingBlockMove !== null"
        max-width="420"
        @update:model-value="
            (v) => {
                if (!v) cancelBlockMove();
            }
        "
    >
        <v-card v-if="pendingBlockMove">
            <v-card-title class="text-subtitle-1 font-weight-bold"
                >{{ MESSAGES.schedule.confirmBlockMove }}</v-card-title
            >
            <v-card-text>
                <div class="mb-3">
                    <div class="font-weight-medium">
                        {{ pendingBlockMove.block.type_label }}
                    </div>
                    <div
                        v-if="pendingBlockMove.block.title"
                        class="text-body-2 text-medium-emphasis"
                    >
                        {{ pendingBlockMove.block.title }}
                    </div>
                </div>
                <div class="ark-move-compare">
                    <div>
                        <div class="text-caption text-medium-emphasis">
                            変更前
                        </div>
                        <div class="text-body-1">
                            {{ pendingBlockBeforeLabel() }}
                        </div>
                    </div>
                    <v-icon icon="mdi-arrow-right" class="mx-2" />
                    <div>
                        <div class="text-caption text-medium-emphasis">
                            変更後
                        </div>
                        <div class="text-body-1 font-weight-bold text-primary">
                            {{ pendingBlockAfterLabel() }}
                        </div>
                    </div>
                </div>
                <div
                    v-if="pendingBlockLaneChangeLabel()"
                    class="text-body-2 mt-3"
                >
                    <v-icon
                        icon="mdi-account-switch-outline"
                        size="16"
                        class="mr-1"
                    />
                    {{ pendingBlockLaneChangeLabel() }}
                </div>
                <!-- 任意の日付へ変更（§6）。前日/今日/翌日ボタンへのドロップは既存どおり ±1日。 -->
                <v-menu :close-on-content-click="false" location="bottom start">
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            variant="text"
                            size="small"
                            color="accent"
                            prepend-icon="mdi-calendar-edit-outline"
                            class="mt-3"
                        >
                            日付を変更（{{
                                dayLabel(pendingBlockMove.targetDate)
                            }}）
                        </v-btn>
                    </template>
                    <v-card>
                        <ArkCalendar v-model="pendingBlockMove.targetDate" />
                    </v-card>
                </v-menu>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn
                    variant="text"
                    :disabled="blockMoveSubmitting"
                    @click="cancelBlockMove"
                    >キャンセル</v-btn
                >
                <v-btn
                    color="primary"
                    variant="flat"
                    :loading="blockMoveSubmitting"
                    @click="confirmBlockMove"
                >
                    変更する
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- D&D（予約・予定ブロック共通）がサーバー側で拒否された時のエラー通知。
         カードは既に元の位置へ戻っているので、ここでは理由だけ伝えればよい。 -->
    <v-snackbar
        :model-value="dndErrorToast !== null"
        color="error"
        location="bottom"
        timeout="6000"
        @update:model-value="
            (v) => {
                if (!v) dndErrorToast = null;
            }
        "
    >
        {{ dndErrorToast }}
        <template #actions>
            <v-btn variant="text" @click="dndErrorToast = null">閉じる</v-btn>
        </template>
    </v-snackbar>

    <!-- D&D中のゴーストカード（§16） -->
    <div
        v-if="draggedReservation && drag"
        class="drag-ghost"
        :style="{ left: `${drag.pointerX}px`, top: `${drag.pointerY}px` }"
        aria-hidden="true"
    >
        <div class="drag-ghost__name">
            {{ draggedReservation.customer_name }}
        </div>
        <div class="drag-ghost__time">
            {{ minuteToLabel(drag.baseStartMin + drag.offsetMinutes) }}–{{
                minuteToLabel(
                    drag.baseStartMin + drag.offsetMinutes + drag.durationMin,
                )
            }}
        </div>
    </div>

    <!-- オンライン予約通知（§34-37） -->
    <ScheduleNotifications
        :sound="notificationSound"
        @select="onNotificationSelect"
    />
</template>

<style scoped>
/* ── 顧客・予約詳細パネル（左スライド） ── */
.board-layout {
    display: flex;
    align-items: flex-start;
    /* 台帳カードの左端の枠線が左パネルに密着して見えなくなるため、必ず隙間を空ける。 */
    gap: var(--ark-space-2);
}

.board-layout__main {
    flex: 1 1 0;
    min-width: 0;
}

/* パネル開閉レール。上のツールバー行の下から始まる、幅44px・ARKブルー。 */
.panel-rail {
    display: flex;
    flex: 0 0 48px;
    width: 48px;
    flex-direction: column;
    align-items: center;
    gap: var(--ark-space-2);
    padding: var(--ark-space-3) 0;
    position: sticky;
    top: var(--ark-space-4);
    align-self: stretch;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
    border-radius: var(--ark-radius);
}

.panel-rail__btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border: 0;
    border-radius: var(--ark-radius);
    background: none;
    color: rgba(var(--v-theme-primary), 0.75);
    cursor: pointer;
}

.panel-rail__btn:hover {
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
}

.panel-rail__btn:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

/* 幅は Peak Manager の左パネル参考（1023px 以下でレスポンシブに 100%）。
   顧客詳細のタブ切替（顧客／履歴／今後の予約）分だけ 312px から少し広げた。 */
.board-layout__panel {
    flex: 0 0 352px;
    width: 352px;
    min-width: 0;
    align-self: flex-start;
    /* 高さは「本日の集計」に合わせず、画面内で使える最下部まで伸ばす（script 側で 100dvh 基準の calc を設定）。
       中身が少ない時は panel-shell 側が伸びて埋め、長い時はパネル内スクロールにする。
       極端に低い画面でも操作できるよう最小高さを持たせる。 */
    min-height: 420px;
    /* 幅はどのパネル種別でも必ず同じにする（中身のnowrap要素等で広がらないようoverflow/min-widthで固定）。 */
    height: calc(100vh - 120px);
    display: flex;
    /* 顧客検索バー（常時表示）＋ 下に切り替わるパネル本体、を縦に積む。 */
    flex-direction: column;
    /* 横方向の広がりは既存どおり防ぎつつ、検索結果ドロップダウンは下にはみ出して表示できるようにする。 */
    overflow: hidden;
}

/* 検索バーの下の、切り替わるパネル本体（各PanelShell）が残り高さを埋めるようにする。 */
.board-layout__panel > :deep(.panel-shell) {
    flex: 1 1 auto;
    min-height: 0;
    height: auto;
}

.board-layout__scrim {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2400;
    background: rgb(18 25 60 / 32%);
}

/* 中間幅：Peak Manager の 1023px ブレークポイントまでは幅は固定のまま、台帳側を狭める。 */
@media (max-width: 1279px) and (min-width: 1024px) {
    .board-layout__panel {
        flex-basis: 320px;
        width: 320px;
    }
}

/* iPad 縦・狭い画面（Peak Manager と同じ 1023px 以下）：パネルは幅100%のオーバーレイ（台帳を潰さない・§15） */
@media (max-width: 1023px) {
    .board-layout__panel {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 2500;
        flex: none;
        width: 100%;
        max-height: none;
        top: 0;
        box-shadow: 0 0 40px rgb(18 25 60 / 28%);
    }

    .board-layout__scrim {
        display: block;
    }

    /* パネルが幅100%のオーバーレイになっても隠れないよう、レールは固定表示で最前面に。 */
    .panel-rail {
        position: fixed;
        top: 50%;
        left: 0;
        transform: translateY(-50%);
        z-index: 2600;
        border-radius: 0 var(--ark-radius) var(--ark-radius) 0;
        box-shadow: 0 4px 16px rgb(18 25 60 / 24%);
    }
}

.schedule-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--ark-space-5) var(--ark-space-6);
    padding: var(--ark-space-2) var(--ark-space-4) !important;
}

/* v-container の既定パディング(24px)を打ち消して、画面の一番左（x=0）から表示する。 */
.schedule-topbar,
.board-layout {
    margin-left: calc(-1 * var(--ark-space-5));
}

/* 元タイトル位置の行。カードに乗せず単独で置くため、ここだけ独自にモダンな見た目を付ける。 */
.schedule-topbar {
    /* ヘッダー直下の余白（v-containerの既定パディング24px）も詰める。 */
    margin-top: calc(-1 * var(--ark-space-4));
    background: rgb(var(--v-theme-surface));
    border-radius: var(--ark-radius);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.06);
    box-shadow: var(--ark-shadow-1);
}

.schedule-topbar .toolbar-staff {
    flex: 0 1 360px;
}

.preview-service-name {
    display: block;
    white-space: normal;
    line-height: 1.35;
    font-size: 0.875rem;
}

.preview-service-selection {
    display: block;
    overflow: hidden;
    max-width: 100%;
    white-space: normal;
    line-height: 1.25;
    font-size: 0.8125rem;
}

.toolbar-period {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: var(--ark-space-2);
    padding-right: var(--ark-space-5);
    border-right: 1px solid rgba(var(--v-theme-on-surface), 0.1);
}

.toolbar-period :deep(.v-btn) {
    height: 40px;
    border-radius: 999px;
}

.toolbar-period :deep(.v-btn--icon) {
    width: 40px;
}

.toolbar-field {
    flex: 0 1 auto;
}

/* DateField内部(v-text-field)はスコープ属性が付かないため :deep() で当てる。 */
:deep(.toolbar-date) {
    width: 208px;
    flex: 0 0 208px;
}

:deep(.toolbar-date input) {
    min-width: 0;
}

.toolbar-staff {
    flex: 1 1 230px;
    min-width: 220px;
}

.toolbar-mode {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: var(--ark-space-2);
}

.toolbar-mode--right {
    margin-left: auto;
}

.toolbar-label {
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    opacity: 0.7;
    white-space: nowrap;
}

.toolbar-toggle :deep(.v-btn) {
    min-width: 48px;
    padding-inline: 12px;
}

.toolbar-today {
    font-weight: 800;
    font-size: 1rem;
    padding-inline: var(--ark-space-5);
}

.toolbar-today :deep(.v-btn__content) {
    color: #ffffff;
}

.toolbar-today--current {
    box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.35);
}

.toolbar-daydrop--hover {
    box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.6) inset;
}

.daily-summary {
    padding: var(--ark-space-3) var(--ark-space-4);
}

.daily-summary__title {
    margin-bottom: var(--ark-space-2);
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: rgb(var(--v-theme-primary));
}

.daily-summary__row {
    display: flex;
    align-items: stretch;
    flex-wrap: wrap;
    gap: var(--ark-space-5);
}

.daily-summary__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    flex: 0 0 auto;
}

.daily-summary__value {
    font-size: 1.0625rem;
    font-weight: 700;
    line-height: 1.1;
    color: rgb(var(--v-theme-on-surface));
    font-variant-numeric: tabular-nums;
}

.daily-summary__label {
    font-size: 0.6875rem;
    color: rgba(var(--v-theme-on-surface), 0.74);
    white-space: nowrap;
}

.daily-summary__divider {
    flex: 0 0 auto;
    width: 1px;
    align-self: stretch;
    background: rgba(var(--v-theme-on-surface), 0.12);
}

.daily-summary__item--revenue .daily-summary__value {
    color: rgb(var(--v-theme-primary));
}

.schedule-card {
    max-width: 100%;
    overflow: hidden;
}

.timeline-shell {
    display: grid;
    grid-template-columns: 140px minmax(0, 1fr);
    max-width: 100%;
    overflow: hidden;
}

.timeline-lane-column {
    position: sticky;
    left: 0;
    z-index: 5;
    border-right: 1px solid #d9dee5;
    background: rgb(var(--v-theme-surface));
}

.timeline-corner,
.timeline-lane-label {
    display: flex;
    align-items: center;
}

.timeline-corner {
    height: 44px;
    padding-inline: var(--ark-space-4);
    border-bottom: 1px solid #d9dee5;
    color: rgb(var(--v-theme-primary));
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
}

.timeline-lane-label {
    position: relative;
    gap: var(--ark-space-2);
    min-width: 0;
    padding-inline: var(--ark-space-3);
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.8125rem;
    font-weight: 700;
    border-bottom: 1px solid #d9dee5;
}

.timeline-lane-label span:last-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* スタッフ名（＋勤務予定外バッジ）。行が高い日も名前は1行で省略表示する。 */
.timeline-lane-name {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    min-width: 0;
}

.timeline-lane-name__text {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* 休み設定なのに予約・予定が入っているスタッフ行（予約を隠さず、状態だけ知らせる）。 */
.lane-off-duty {
    padding: 0 6px;
    border-radius: 999px;
    background: rgb(var(--v-theme-warning), 0.14);
    color: rgb(151 90 0);
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1.6;
    white-space: nowrap;
}

.toolbar-off-staff {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 10px;
    border-radius: 999px;
    background: rgba(var(--v-theme-on-surface), 0.05);
    color: rgba(var(--v-theme-on-surface), 0.7);
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    cursor: default;
}

.timeline-lane-label--unassigned {
    background: rgb(var(--v-theme-background));
    font-weight: 500;
}

.timeline-lane-dot {
    flex: 0 0 auto;
    width: 4px;
    height: 28px;
    border: 0;
    border-radius: 999px;
    box-shadow: none;
}

/* スタッフ行はボタン化し、クリックで勤務枠へ（§19）。見た目は通常のラベル行と揃える。 */
.timeline-lane-label--link {
    width: 100%;
    border: 0;
    border-radius: 0;
    background: none;
    cursor: pointer;
    text-align: left;
}

.timeline-lane-label--link:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}

.timeline-lane-label--link:hover .timeline-lane-label__icon {
    opacity: 1;
}

.timeline-lane-label__icon {
    flex: 0 0 auto;
    margin-left: auto;
    opacity: 0;
    color: rgb(var(--v-theme-primary));
    transition: opacity 0.12s ease;
}

/* D&D で別レーンにホバー中（§17, §29） */
.timeline-lane-label--drop {
    background: rgba(var(--v-theme-primary), 0.14) !important;
    outline: 1px dashed rgb(var(--v-theme-primary));
}

/* 見た目のヒントのみ。最終判定は必ずサーバー側（§18） */
.timeline-lane-label--drop-valid {
    background: rgba(var(--v-theme-accent), 0.16) !important;
    outline: 2px solid rgb(var(--v-theme-accent));
}

.timeline-lane-label--drop-invalid {
    background: rgba(var(--v-theme-error), 0.12) !important;
    outline: 2px solid rgb(var(--v-theme-error));
}

/* 「両方」表示：横幅いっぱいの「スタッフ／ブース」見出し行（§25） */
.timeline-section-header {
    display: flex;
    align-items: center;
    gap: var(--ark-space-1);
    height: 28px;
    padding: 0 var(--ark-space-2);
    background: rgba(var(--v-theme-primary), 0.08);
    border-top: 1px solid rgba(var(--v-theme-primary), 0.25);
    border-bottom: 1px solid rgba(var(--v-theme-primary), 0.25);
    font-size: 0.6875rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: rgb(var(--v-theme-primary));
}

.timeline-track-section-header {
    height: 28px;
    background: rgba(var(--v-theme-primary), 0.05);
    border-top: 1px solid rgba(var(--v-theme-primary), 0.25);
    border-bottom: 1px solid rgba(var(--v-theme-primary), 0.25);
}

/* 空き枠クリックで新規予約（§17）。カード（z-index:2）の下に敷く透明レイヤー。 */
.timeline-empty-click {
    position: absolute;
    inset: 0;
    z-index: 1;
    cursor: pointer;
}

.board-layout--cross-date-move .timeline-empty-click {
    cursor: crosshair;
}

.cross-date-ghost {
    position: fixed;
    z-index: 2600;
    display: inline-flex;
    align-items: center;
    max-width: min(360px, calc(100vw - 32px));
    gap: var(--ark-space-2);
    padding: 6px 8px 6px 12px;
    border: 1px solid rgba(var(--v-theme-primary), 0.35);
    border-radius: 999px;
    background: rgba(var(--v-theme-surface), 0.9);
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.75rem;
    font-weight: 700;
    box-shadow: var(--ark-shadow-2);
    pointer-events: none;
    backdrop-filter: blur(4px);
}

.slot-pick-bar {
    position: fixed;
    left: 50%;
    bottom: var(--ark-space-4);
    z-index: 2600;
    display: inline-flex;
    align-items: center;
    gap: var(--ark-space-2);
    max-width: min(560px, calc(100vw - 32px));
    padding: 6px 6px 6px 14px;
    border-radius: 999px;
    background: rgb(var(--v-theme-primary));
    color: rgb(var(--v-theme-on-primary));
    font-size: 0.8rem;
    font-weight: 700;
    box-shadow: var(--ark-shadow-2);
    transform: translateX(-50%);
}

.slot-pick-bar--move {
    background: rgb(var(--v-theme-warning));
    color: rgb(var(--v-theme-on-warning));
}

.slot-pick-bar__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.slot-pick-bar__release {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 2px;
    padding: 4px 10px;
    border: 0;
    border-radius: 999px;
    background: rgb(255 255 255 / 0.18);
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.slot-pick-bar__release:hover {
    background: rgb(255 255 255 / 0.3);
}

.cross-date-ghost > span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cross-date-ghost__close {
    display: grid;
    flex: 0 0 22px;
    width: 22px;
    height: 22px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: rgba(var(--v-theme-on-surface), 0.08);
    color: inherit;
    cursor: pointer;
    font: inherit;
    line-height: 1;
    pointer-events: auto;
    place-items: center;
}

.timeline-scroll {
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    background: rgb(var(--v-theme-surface));
}

.timeline-scroll:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

.timeline-canvas {
    position: relative;
    min-width: 100%;
}

.timeline-axis {
    position: relative;
    height: 44px;
    border-bottom: 1px solid #d9dee5;
    background: rgb(var(--v-theme-background));
}

/* 1 時間ぶんの見出しを中央寄せで表示（＝時間セルを結合して中央揃え） */
.timeline-hour {
    position: absolute;
    top: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-left: 1px solid #d9dee5;
}

.timeline-hour__label {
    font-size: 0.8125rem;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    font-variant-numeric: tabular-nums;
}

.timeline-minor-tick {
    position: absolute;
    bottom: 0;
    width: 1px;
    height: 6px;
    background: #c7cdd6;
}

.timeline-minor-tick--ten {
    height: 3px;
    background: #e1e5eb;
}

/* 予約できない時間帯の帯（軸のすぐ下） */
.timeline-unbookable {
    position: absolute;
    left: 0;
    right: 0;
    top: 44px;
    height: 10px;
    z-index: 4;
    pointer-events: none;
}

.timeline-unbookable__seg {
    position: absolute;
    top: 0;
    height: 10px;
}

.timeline-unbookable__seg.is-closed {
    background-image: repeating-linear-gradient(
        45deg,
        rgba(var(--v-theme-secondary), 0.28),
        rgba(var(--v-theme-secondary), 0.28) 4px,
        transparent 4px,
        transparent 8px
    );
}

.timeline-unbookable__seg.is-full {
    background: rgba(var(--v-theme-warning), 0.5);
}

.timeline-track {
    position: relative;
    overflow: hidden;
    background: rgb(var(--v-theme-surface));
    border-bottom: 1px solid #d9dee5;
}

/* ドラッグ中の元カードが別スタッフ／ブース行へ視覚的に追従できるよう、
   ドラッグ元の行だけ一時的にクリップを外し、他行より前面に出す（時間移動と同じ「うっすら動く」体験）。 */
.timeline-track--drag-origin {
    z-index: 6;
    overflow: visible;
}

.timeline-grid-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1px;
    background: #d9dee5;
    opacity: 0.7;
}

.schedule-legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--ark-space-4);
    margin-bottom: var(--ark-space-2);
    padding-inline: var(--ark-space-1);
}

.schedule-legend__item {
    display: inline-flex;
    font-size: 0.78rem;
    color: rgb(var(--v-theme-on-surface));
    align-items: center;
    gap: 6px;
}

.schedule-legend__swatch {
    width: 22px;
    height: 12px;
    border-radius: 3px;
    border: 1px solid #d9dee5;
}

.schedule-legend__swatch.is-menu-available {
    background: rgba(var(--v-theme-success), 0.16);
    border-top: 3px solid rgb(var(--v-theme-success));
    box-shadow: inset 0 0 0 1px rgba(var(--v-theme-success), 0.25);
}

.schedule-legend__swatch.is-menu-blocked {
    background: repeating-linear-gradient(
        -45deg,
        rgb(var(--v-theme-on-surface), 0.08),
        rgb(var(--v-theme-on-surface), 0.08) 3px,
        rgb(var(--v-theme-on-surface), 0.16) 3px,
        rgb(var(--v-theme-on-surface), 0.16) 6px
    );
}

.schedule-legend__swatch.is-closed {
    background-image: repeating-linear-gradient(
        45deg,
        rgba(var(--v-theme-secondary), 0.28),
        rgba(var(--v-theme-secondary), 0.28) 4px,
        transparent 4px,
        transparent 8px
    );
}

.schedule-legend__swatch.is-full {
    background: rgba(var(--v-theme-warning), 0.5);
}

.non-working {
    position: absolute;
    top: 0;
    bottom: 0;
    background: repeating-linear-gradient(
        -45deg,
        rgb(var(--v-theme-on-surface), 0.035),
        rgb(var(--v-theme-on-surface), 0.035) 6px,
        rgb(var(--v-theme-on-surface), 0.075) 6px,
        rgb(var(--v-theme-on-surface), 0.075) 12px
    );
}

/* 選択メニューがその時間から開始できるセル（§11）。緑で一目で分かるようにする。 */
.menu-available {
    position: absolute;
    top: 0;
    bottom: 0;
    z-index: 1;
    background: rgba(var(--v-theme-success), 0.14);
    border-top: 3px solid rgb(var(--v-theme-success));
    box-shadow: inset 0 0 0 1px rgba(var(--v-theme-success), 0.18);
    box-sizing: border-box;
    pointer-events: none;
}

/* 選択メニューがその時間から開始できないセル（§11）。斜線でしっかり分かるようにする（淡め）。 */
.menu-blocked {
    position: absolute;
    top: 0;
    bottom: 0;
    z-index: 1;
    background: repeating-linear-gradient(
        -45deg,
        rgb(var(--v-theme-on-surface), 0.04),
        rgb(var(--v-theme-on-surface), 0.04) 6px,
        rgb(var(--v-theme-on-surface), 0.1) 6px,
        rgb(var(--v-theme-on-surface), 0.1) 12px
    );
    pointer-events: none;
}

/* 終了後インターバル：予約カードの右端に、施術と区別できる斜線の区間として描く（Task 11-29）。 */
.reservation-buffer {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    border-left: 1px dashed rgba(15, 23, 42, 0.25);
    background: repeating-linear-gradient(-45deg, rgba(255, 255, 255, 0.85) 0 3px, rgba(148, 163, 184, 0.35) 3px 6px);
}

.reservation-card {
    position: absolute;
    z-index: 2;
    top: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    overflow: hidden;
    gap: 2px;
    box-sizing: border-box;
    min-width: 0;
    padding: 3px var(--ark-space-2);
    border: 1px solid #d9dee5;
    border-left-width: 5px;
    border-radius: var(--ark-radius);
    background: rgb(var(--v-theme-surface));
    color: rgb(var(--v-theme-on-surface));
    cursor: pointer;
    text-align: left;
    line-height: 1.25;
    box-shadow: var(--ark-shadow-1);
}

.reservation-card:hover {
    filter: brightness(0.97);
    box-shadow: var(--ark-shadow-2);
}

.reservation-card:disabled {
    cursor: default;
}

.reservation-card:disabled:hover {
    filter: none;
    box-shadow: none;
}

.reservation-card:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

.reservation-card--draggable {
    cursor: grab;
    touch-action: none;
}

.reservation-card--dragging {
    cursor: grabbing;
    z-index: 20;
    box-shadow: 0 10px 28px rgb(18 25 60 / 26%);
    opacity: 0.45;
}

.reservation-card--pending {
    z-index: 19;
    outline: 2px dashed rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

/* パネルで選択中の予約 */
.reservation-card--selected {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

/* 来店履歴から遷移した直後の一時ハイライト（数秒で戻る・§9） */
.reservation-card--flash {
    z-index: 18;
    animation: rdp-flash 3.2s ease-out;
}

@keyframes rdp-flash {
    0%,
    45% {
        outline: 3px solid rgb(var(--v-theme-warning));
        outline-offset: -1px;
        box-shadow: 0 0 0 6px rgba(var(--v-theme-warning), 0.3);
    }

    100% {
        outline: 3px solid rgba(var(--v-theme-warning), 0);
        outline-offset: -1px;
        box-shadow: 0 0 0 6px rgba(var(--v-theme-warning), 0);
    }
}

.reservation-dragtip {
    position: absolute;
    top: 2px;
    right: 2px;
    z-index: 2;
    padding: 1px 6px;
    border-radius: 999px;
    background: rgb(var(--v-theme-primary));
    color: #fff;
    font-size: 0.6875rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.ark-move-compare {
    display: flex;
    align-items: center;
    padding: var(--ark-space-3);
    background: rgba(var(--v-theme-on-surface), 0.04);
    border-radius: var(--ark-radius);
}

.reservation-topline {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    gap: var(--ark-space-1);
}

.reservation-nameline {
    display: flex;
    align-items: center;
    gap: 3px;
    min-width: 0;
    overflow: hidden;
}

.reservation-status-icon {
    flex: 0 0 auto;
}

.reservation-timeline {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    min-width: 0;
}

.reservation-time {
    flex: 0 0 auto;
    font-size: 0.6875rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}

.reservation-customer,
.reservation-service {
    display: block;
    overflow: hidden;
    width: 100%;
    min-width: 0;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reservation-customer {
    font-size: 0.75rem;
    font-weight: 700;
}

.reservation-badges {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    gap: 4px;
}

.reservation-badge {
    flex: 0 0 auto;
    padding: 0 4px;
    border-radius: var(--ark-radius-sm);
    font-size: 0.625rem;
    font-weight: 800;
    line-height: 1.6;
    letter-spacing: 0.02em;
}

.reservation-badge--new {
    background: rgb(var(--v-theme-error));
    color: #fff;
}

.reservation-badge--nomination {
    display: inline-flex;
    align-items: center;
    gap: 1px;
    background: rgba(var(--v-theme-primary), 0.14);
    color: rgb(var(--v-theme-primary));
}

.reservation-service-line {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    min-width: 0;
}

.reservation-gender {
    flex: 0 0 auto;
    padding: 0 4px;
    border-radius: var(--ark-radius-sm);
    font-size: 0.625rem;
    font-weight: 800;
    line-height: 1.6;
}

.reservation-gender--male {
    color: rgb(var(--v-theme-info));
    background: rgba(var(--v-theme-info), 0.12);
}

.reservation-gender--female {
    color: rgb(var(--v-theme-error));
    background: rgba(var(--v-theme-error), 0.1);
}

.reservation-service {
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.6875rem;
    opacity: 0.72;
}

.reservation-service-line .reservation-service {
    flex: 1 1 auto;
    width: auto;
    min-width: 0;
}

/* 予定ブロック（休憩・他業務等）。予約カードとは明確に色・レイアウトを分ける（§27-46） */
.schedule-block {
    position: absolute;
    top: 0;
    bottom: 0;
    z-index: 2;
    /* 短い予定でも「種類」と「時間」の両方が読めるよう2段組みにする
       （1行だと幅が足りず開始時刻しか見えなかった）。 */
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 1px;
    padding: 0 6px;
    border: 1px dashed rgba(var(--v-theme-on-surface), 0.28);
    border-radius: var(--ark-radius-sm);
    background: rgba(var(--v-theme-on-surface), 0.06);
    color: rgba(var(--v-theme-on-surface), 0.8);
    font-size: 0.6875rem;
    font-weight: 700;
    overflow: hidden;
    white-space: nowrap;
    cursor: pointer;
    transition: transform 0.05s linear;
}

.schedule-block--warning {
    background: rgba(var(--v-theme-warning), 0.1);
    border-color: rgba(var(--v-theme-warning), 0.4);
    color: rgb(var(--v-theme-warning));
}

.schedule-block--info {
    background: rgba(var(--v-theme-info), 0.1);
    border-color: rgba(var(--v-theme-info), 0.4);
    color: rgb(var(--v-theme-info));
}

.schedule-block--success {
    background: rgba(var(--v-theme-success), 0.1);
    border-color: rgba(var(--v-theme-success), 0.4);
    color: rgb(var(--v-theme-success));
}

.schedule-block--accent {
    background: rgba(var(--v-theme-accent), 0.1);
    border-color: rgba(var(--v-theme-accent), 0.4);
    color: rgb(var(--v-theme-accent));
}

.schedule-block--secondary {
    background: rgba(var(--v-theme-secondary), 0.1);
    border-color: rgba(var(--v-theme-secondary), 0.4);
    color: rgb(var(--v-theme-secondary));
}

.schedule-block__head {
    display: flex;
    align-items: center;
    gap: 3px;
    max-width: 100%;
    min-width: 0;
}

.schedule-block__time {
    flex: 0 0 auto;
    font-size: 0.625rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    opacity: 0.9;
}

.schedule-block__label {
    overflow: hidden;
    text-overflow: ellipsis;
}

.schedule-block--draggable {
    cursor: grab;
}

.schedule-block--dragging {
    z-index: 5;
    opacity: 0.45;
    box-shadow: 0 4px 14px rgb(18 25 60 / 22%);
}

.schedule-block--pending {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 1px;
}

/* D&D中のゴーストカード（§16） */
.drag-ghost {
    position: fixed;
    z-index: 2700;
    transform: translate(14px, -50%);
    padding: var(--ark-space-2) var(--ark-space-3);
    background: rgb(var(--v-theme-primary));
    color: #fff;
    border-radius: var(--ark-radius);
    box-shadow: 0 10px 28px rgb(18 25 60 / 32%);
    pointer-events: none;
    white-space: nowrap;
}

.drag-ghost__name {
    font-size: 0.8125rem;
    font-weight: 800;
}

.drag-ghost__time {
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
    opacity: 0.9;
}

/* カーソル位置ガイド。線ではなく「今いる枠」を縦帯で塗る（どの枠かひと目で分かる）。
   現在時刻線（navy の細い実線）とは形が違うので取り違えない。 */
.hover-time-line {
    position: absolute;
    z-index: 3;
    top: 0;
    bottom: 0;
    box-sizing: border-box;
    /* 塗りの濃さは幅に応じて hoverFill で切り替える（細い時はほぼ塗りつぶし）。 */
    pointer-events: none;
}

/* 行の中に出すので、時刻は行の上端に小さく添える。 */
.hover-time-line__label {
    position: absolute;
    top: 1px;
    left: 50%;
    transform: translateX(-50%);
    padding: 0 5px;
    border-radius: 999px;
    background: rgb(var(--v-theme-accent));
    color: #ffffff;
    font-size: 0.5625rem;
    font-weight: 800;
    line-height: 1.6;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.current-time-line {
    position: absolute;
    z-index: 4;
    top: 44px;
    bottom: 0;
    width: 2px;
    background: rgb(var(--v-theme-primary));
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.75);
    pointer-events: none;
}

/* 週表示（§2-3）：スタッフ×日付のグリッド。各セルは営業時間を100%とした帯グラフ。 */
.week-board-wrap {
    overflow-x: auto;
}

.week-board {
    display: grid;
    min-width: 780px;
    border: 1px solid #d9dee5;
    border-radius: var(--ark-radius);
    overflow: hidden;
    background: rgb(var(--v-theme-surface));
}

.week-board__corner,
.week-board__daycol,
.week-board__lanename {
    background: rgb(var(--v-theme-background));
    border-bottom: 1px solid #d9dee5;
    border-right: 1px solid #d9dee5;
}

.week-board__corner {
    position: sticky;
    left: 0;
    z-index: 2;
}

.week-board__daycol {
    padding: var(--ark-space-2) var(--ark-space-1);
    text-align: center;
    font-size: 0.75rem;
    font-weight: 700;
    color: rgb(var(--v-theme-on-surface));
}

.week-board__daycol--today {
    background: rgb(var(--v-theme-primary));
    color: #fff;
}

.week-board__lanename {
    position: sticky;
    left: 0;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: var(--ark-space-2);
    padding: var(--ark-space-2) var(--ark-space-3);
    font-size: 0.75rem;
    font-weight: 700;
    min-width: 0;
}

.week-board__cell {
    position: relative;
    height: 64px;
    border-bottom: 1px solid #d9dee5;
    border-right: 1px solid #d9dee5;
    background: rgb(var(--v-theme-surface));
    cursor: pointer;
    overflow: hidden;
}

.week-board__cell:hover {
    background: rgba(var(--v-theme-primary), 0.04);
}

.week-board__cell--today {
    background: rgba(var(--v-theme-primary), 0.03);
}

.week-board__nonworking {
    position: absolute;
    top: 0;
    bottom: 0;
    z-index: 0;
    background-image: repeating-linear-gradient(
        45deg,
        rgba(var(--v-theme-secondary), 0.16),
        rgba(var(--v-theme-secondary), 0.16) 3px,
        transparent 3px,
        transparent 6px
    );
    pointer-events: none;
}

.week-board__bar {
    position: absolute;
    z-index: 1;
    top: 4px;
    height: 16px;
    padding: 0 3px;
    border: 0;
    border-left: 3px solid transparent;
    border-radius: 3px;
    font-size: 0.5625rem;
    font-weight: 700;
    line-height: 16px;
    text-align: left;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    cursor: pointer;
}

.week-board__bar:nth-of-type(n + 2) {
    top: 22px;
}

.week-board__bar--selected {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -1px;
}

.week-board__bar-name {
    pointer-events: none;
}

.week-board__block {
    position: absolute;
    z-index: 1;
    top: 40px;
    height: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 1px dashed rgba(var(--v-theme-on-surface), 0.4);
    border-radius: 3px;
    background: rgba(var(--v-theme-on-surface), 0.06);
    color: rgba(var(--v-theme-on-surface), 0.78);
    cursor: pointer;
}

.week-board__count {
    position: absolute;
    right: 3px;
    bottom: 2px;
    z-index: 1;
    font-size: 0.5625rem;
    color: rgba(var(--v-theme-on-surface), 0.68);
    pointer-events: none;
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
        gap: var(--ark-space-1);
    }

    .toolbar-toggle {
        display: flex;
        width: 100%;
    }

    .toolbar-toggle :deep(.v-btn) {
        flex: 1 1 50%;
    }

    .timeline-shell {
        grid-template-columns: 96px minmax(0, 1fr);
    }

    .timeline-corner,
    .timeline-lane-label {
        padding-inline: var(--ark-space-2);
    }
}

/* ───────────── スマホ（1023px 以下）で台帳を実際に使えるようにする ───────────── */
@media (max-width: 1023px) {
    /* ツールバーの各行が縦に伸びすぎるのを抑える。 */
    .schedule-toolbar {
        gap: var(--ark-space-2) var(--ark-space-3) !important;
        padding: var(--ark-space-2) !important;
    }

    .schedule-topbar,
    .board-layout {
        margin-left: 0;
    }

    .toolbar-period {
        padding-right: 0;
        border-right: 0;
    }

    .toolbar-label {
        display: none;
    }

    /* レールは画面中央に浮かせると台帳の操作を塞ぐため、下端の横並びバーにする。 */
    .panel-rail {
        top: auto;
        bottom: 0;
        left: 0;
        right: 0;
        width: 100%;
        height: 52px;
        transform: none;
        flex: none;
        flex-direction: row;
        justify-content: center;
        gap: var(--ark-space-5);
        padding: 0;
        border-radius: 0;
        border-left: 0;
        border-right: 0;
        box-shadow: 0 -4px 16px rgb(18 25 60 / 18%);
    }

    /* 下端のレールに隠れないよう、本文の下に逃げ場を作る。 */
    .board-layout__main {
        padding-bottom: 60px;
    }

    /* 予約カードは狭い画面だと文字が潰れるので、最小限の情報を優先する。 */
    .reservation-service-line {
        display: none;
    }

    /* 集計は横スクロールさせず折り返す。 */
    .daily-summary__row {
        flex-wrap: wrap;
        gap: var(--ark-space-3) var(--ark-space-4);
    }

    .daily-summary__divider {
        display: none;
    }
}
</style>
