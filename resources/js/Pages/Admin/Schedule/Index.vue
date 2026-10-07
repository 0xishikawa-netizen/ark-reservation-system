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
import type { ComponentPublicInstance } from "vue";
import AdminLayout from "@/layouts/AdminLayout.vue";
import { DateField } from "@/components/ark";
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
import ScheduleMoveConfirmDialog from "@/components/admin/schedule/ScheduleMoveConfirmDialog.vue";
import ScheduleReservationCardBody from "@/components/admin/schedule/ScheduleReservationCardBody.vue";
import { useReservationDrag } from "@/components/admin/schedule/useReservationDrag";
import { useBlockDrag } from "@/components/admin/schedule/useBlockDrag";
import { useScheduleShading } from "@/components/admin/schedule/useScheduleShading";
import { useTimelineGuides } from "@/components/admin/schedule/useTimelineGuides";
import { useMenuPreview } from "@/components/admin/schedule/useMenuPreview";
import ScheduleDailySummary from "@/components/admin/schedule/ScheduleDailySummary.vue";
import ScheduleDragGhost from "@/components/admin/schedule/ScheduleDragGhost.vue";
import {
    DEFAULT_NOTIFICATION_REPEAT,
    DEFAULT_NOTIFICATION_SOUND,
    DEFAULT_NOTIFICATION_VOLUME,
    isNotificationRepeatMode,
    isNotificationSoundType,
} from "@/composables/notificationSound";
import {
    popPanelHistoryStack,
    pushPanelHistoryStack,
} from "@/composables/panelHistory";
import {
    createEmptyBlockDraft,
    createEmptyReservationDraft,
    resetBlockDraft,
    resetReservationDraft,
} from "@/composables/reservationDraft";
import { cannotStartAtTimeMessage, MESSAGES } from "@/constants/messages";
import {
    timeToMinute,
    minuteToLabel,
    menuTintBackground,
    todayIso,
    dayLabel,
    laneKey,
    blockIcon,
    blockColor,
} from "@/components/admin/schedule/scheduleFormat";
import type {
    ScheduleView,
    ScheduleAxis,
    ScheduleLane,
    Staff,
    OffStaff,
    StaffOption,
    Shift,
    ScheduleReservation,
    ScheduleBlock,
    BusinessHours,
    Booth,
    DateRange,
    Filters,
    MenuOption,
    BoothOption,
    DailySummary,
    DisplayRow,
    BlockDragState,
    DragState,
    DragClickGuard,
} from "@/components/admin/schedule/types";

defineOptions({ layout: AdminLayout });

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

/** 左パネルの高さの下限（極端に低い画面でも検索・入力ができるように）。 */
const PANEL_MIN_HEIGHT = 480;

// 左パネルの高さ。右側（ツールバー〜台帳〜本日の集計）の下端にぴったり揃える。
// ブース表示などで台帳が縦に長くなっても、紺の背景が「本日の集計」の下端まで伸びる。
// 中身の方が長い時はパネル内でスクロールする。
const boardPanelEl = ref<HTMLElement | null>(null);
const boardMainEl = ref<HTMLElement | null>(null);
const boardLayoutEl = ref<HTMLElement | null>(null);

const componentRootElement = (el: Element | ComponentPublicInstance | null): HTMLElement | null => {
    if (el instanceof Element) {
        return el instanceof HTMLElement ? el : null;
    }

    const rootElement: unknown = el?.$el;

    return rootElement instanceof HTMLElement ? rootElement : null;
};
const panelMaxHeight = ref("calc(100dvh - 140px)");

/** 要素の上端のページ内位置（スクロール量に依存しない）。 */
function documentTop(el: HTMLElement): number {
    return el.getBoundingClientRect().top + window.scrollY;
}

/**
 * 左パネルの高さ：最低は画面の下端まで、「本日の集計」がそれより下にある時は集計の下端まで。
 * 上端・下端の位置は画面幅や集計の折り返しで変わるため毎回実測する。
 * あわせて日表示の行高の計算に使う「台帳に使える縦の空き」も更新する。
 */
function recalcPanelMaxHeight(): void {
    if (typeof window === "undefined") {
        return;
    }

    // 最低の高さ＝画面の下端まで（ページ下余白 24px を残す）。台帳が短い日でもパネルが画面いっぱいに安定する。
    // 「本日の集計」（集計が無い週表示などは右側の台帳全体）の下端の方が下にある時は、そこまで伸ばして揃える。
    const panelTopEl = boardPanelEl.value ?? boardLayoutEl.value;

    if (panelTopEl !== null) {
        const top = documentTop(panelTopEl);
        const screenHeight = window.innerHeight - top - 24;
        const bottomEl = dailySummaryEl.value ?? boardMainEl.value;
        const summaryHeight = bottomEl !== null
            ? bottomEl.getBoundingClientRect().bottom + window.scrollY - top
            : 0;
        panelMaxHeight.value = `${Math.round(Math.max(screenHeight, summaryHeight, PANEL_MIN_HEIGHT))}px`;
    }

    const shell = timelineShellEl.value;

    if (shell === null) {
        availableTrackSpace.value = 0;

        return;
    }

    const summaryHeight =
        dailySummaryEl.value !== null
            ? dailySummaryEl.value.offsetHeight + 8
            : 0;
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
const { previewServiceId, previewLoading, previewService, menuSegments } =
    useMenuPreview({
        menuOptions: () => props.menu_options,
        slotMinutes: () => props.business_hours.slot_minutes,
        date,
        viewMode,
        openMinute,
        closeMinute,
        pixelsPerMinute,
    });

const timelineWidth = computed(() =>
    Math.max(durationMinutes.value * pixelsPerMinute.value, 1),
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
    const laneRows = displayRows.value.filter(
        (row) => row.kind === "lane",
    ).length;

    return scheduleTrackHeight(
        laneRows,
        displayRows.value.length - laneRows,
        sectionHeaderHeight,
        availableTrackSpace.value,
        isNarrowScreen.value,
    );
});

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
    return minuteToLabel(
        timeToMinute(reservation.ends_at.slice(11, 16)) -
            (reservation.buffer_min ?? 0),
    );
}

/** カード内でインターバル区間（右端）を描く幅。 */
function bufferStyle(reservation: ScheduleReservation): Record<string, string> {
    return {
        width: `${(reservation.buffer_min ?? 0) * pixelsPerMinute.value}px`,
    };
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

const {
    nonWorkingSegments,
    weekBarRect,
    weekBlockTooltip,
    weekNonWorkingRects,
    weekRectStyle,
    weekReservationTooltip,
} = useScheduleShading({
    closeMinute,
    date,
    openMinute,
    pixelsPerMinute,
    props,
    serviceEndLabel,
});


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
        panelKind.value === "create" &&
        props.create_prefill.customer_id !== null,
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
            serviceId:
                previewServiceId.value ??
                reservationDraft.service_id ??
                undefined,
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
    [
        timelineShellEl,
        dailySummaryEl,
        () => displayRows.value.length,
        isNarrowScreen,
    ],
    () => {
        void nextTick(() => recalcPanelMaxHeight());
    },
);

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
            lane.kind === "staff" ? reservation.staff_id : reservation.booth_id;

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
        currentTimeLeft.value >
        ((closeMinute.value - openMinute.value) * pixelsPerMinute.value) / 2;

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

/* ドラッグ中の状態は、時間軸ガイドとドラッグ処理の両方が参照するためここで作る。 */
const blockDrag = ref<BlockDragState | null>(null);
const drag = ref<DragState | null>(null);
const dragClickGuard: DragClickGuard = { suppress: false };

const {
    clearTimelineHover,
    gridStyle,
    hourBlocks,
    hoverFill,
    hoverLabel,
    hoverLaneKey,
    hoverLeft,
    hoverWidth,
    minorTicks,
    onTimelineHover,
    tenTicks,
    unbookableSegments,
} = useTimelineGuides({
    drag,
    axisMode,
    blockDrag,
    closeMinute,
    date,
    lanes,
    openMinute,
    pixelsPerMinute,
    props,
    reservationsFor,
    viewMode,
});

/* ───────────────── ドラッグ&ドロップ（予約・予定ブロック） ───────────────── */

const {
    cancelCrossDateMove,
    cancelMove,
    cardContextMenuOpen,
    cardContextMenuReservation,
    cardContextMenuTarget,
    confirmMove,
    crossDateMove,
    crossDateMoveReservation,
    crossDatePointer,
    dateDropHoverOffset,
    dndErrorToast,
    dragOffsetXPx,
    dragOffsetYPx,
    draggedReservation,
    dropTargetValidity,
    hitTestDateDrop,
    isDragOriginLane,
    isDraggable,
    isDropTargetLane,
    laneRowIndex,
    moveSubmitting,
    nextDayBtnEl,
    onCardClick,
    onCardContextMenu,
    onCardPointerDown,
    onCardPointerMove,
    onCardPointerUp,
    pendingAfterLabel,
    pendingBeforeLabel,
    pendingLaneName,
    pendingMove,
    prevDayBtnEl,
    snapMinutes,
    startCrossDateMove,
    todayBtnEl,
} = useReservationDrag({
    axisMode,
    blocksFor,
    canManage,
    closeMinute,
    date,
    lanes,
    openMinute,
    openReservationPanel,
    pixelsPerMinute,
    previewServiceId,
    props,
    reservationsFor,
    slotPickGhostVisible,
    timelineTrackHeight,
    viewMode,
    blockDrag,
    dragClickGuard,
    drag,
});

const {
    blockDragOffsetXPx,
    blockDragOffsetYPx,
    blockMoveSubmitting,
    cancelBlockMove,
    confirmBlockMove,
    isBlockDragOriginLane,
    isBlockDraggable,
    onBlockClick,
    onBlockPointerDown,
    onBlockPointerMove,
    onBlockPointerUp,
    pendingBlockAfterLabel,
    pendingBlockBeforeLabel,
    pendingBlockLaneChangeLabel,
    pendingBlockMove,
    pendingLaneChangeLabel,
} = useBlockDrag({
    canManage,
    closeMinute,
    lanes,
    openBlockDetailPanel,
    openMinute,
    pixelsPerMinute,
    timelineTrackHeight,
    viewMode,
    blockDrag,
    dragClickGuard,
    dateDropHoverOffset,
    dndErrorToast,
    hitTestDateDrop,
    laneRowIndex,
    pendingLaneName,
    pendingMove,
    snapMinutes,
});

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
                            (el: Element | ComponentPublicInstance | null) => {
                                prevDayBtnEl = componentRootElement(el);
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
                    (el: Element | ComponentPublicInstance | null) => {
                        todayBtnEl = componentRootElement(el);
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
                            (el: Element | ComponentPublicInstance | null) => {
                                nextDayBtnEl = componentRootElement(el);
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
                <v-list-item
                    v-bind="itemProps"
                    :title="undefined"
                    class="preview-service-item"
                >
                    <span class="preview-service-name">{{
                        item.raw.name
                    }}</span>
                </v-list-item>
            </template>
            <template #selection="{ item }">
                <span
                    class="preview-service-selection"
                    :title="item.raw.name"
                    >{{ item.raw.name }}</span
                >
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
        <span class="slot-pick-bar__label"
            >{{ slotPickLabel }} の日時を選択中</span
        >
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
                        :text="
                            offStaff
                                .map((member) => member.display_name)
                                .join('、')
                        "
                    >
                        <template #activator="{ props: tip }">
                            <span
                                v-bind="tip"
                                class="toolbar-off-staff"
                                data-testid="off-staff-count"
                            >
                                <v-icon
                                    icon="mdi-account-off-outline"
                                    size="16"
                                />
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
                                        :title="
                                            row.lane.off_duty
                                                ? MESSAGES.schedule.offDutyTitle
                                                : undefined
                                        "
                                    >
                                        <span
                                            class="timeline-lane-name__text"
                                            >{{ row.lane.display_name }}</span
                                        >
                                        <span
                                            v-if="row.lane.off_duty"
                                            class="lane-off-duty"
                                            data-testid="lane-off-duty"
                                            >{{
                                                MESSAGES.schedule.offDutyBadge
                                            }}</span
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
                                        :title="
                                            row.lane.off_duty
                                                ? MESSAGES.schedule.offDutyTitle
                                                : undefined
                                        "
                                    >
                                        <span
                                            class="timeline-lane-name__text"
                                            >{{ row.lane.display_name }}</span
                                        >
                                        <span
                                            v-if="row.lane.off_duty"
                                            class="lane-off-duty"
                                            data-testid="lane-off-duty"
                                            >{{
                                                MESSAGES.schedule.offDutyBadge
                                            }}</span
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
                                            :title="
                                                cannotStartAtTimeMessage(
                                                    previewService?.name ?? '',
                                                )
                                            "
                                        />
                                        <div
                                            class="timeline-empty-click"
                                            aria-hidden="true"
                                            @click="
                                                onTrackClick(row.lane, $event)
                                            "
                                            @mousemove="
                                                onTimelineHover(
                                                    row.lane,
                                                    $event,
                                                )
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
                                            <ScheduleReservationCardBody
                                                :reservation="reservation"
                                                :end-label="serviceEndLabel(reservation)"
                                                :buffer-style="bufferStyle(reservation)"
                                                :drag-label="
                                                    drag?.id === reservation.id && drag?.moved
                                                        ? `${minuteToLabel(drag.baseStartMin + drag.offsetMinutes)}〜${minuteToLabel(drag.baseStartMin + drag.offsetMinutes + drag.durationMin)}`
                                                        : null
                                                "
                                            />
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
                                            <span class="schedule-block__head">
                                                <v-icon
                                                    :icon="
                                                        blockIcon(block.type)
                                                    "
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
                                        :title="
                                            lane.off_duty
                                                ? MESSAGES.schedule.offDutyTitle
                                                : undefined
                                        "
                                    >
                                        <span
                                            class="timeline-lane-name__text"
                                            >{{ lane.display_name }}</span
                                        >
                                        <span
                                            v-if="lane.off_duty"
                                            class="lane-off-duty"
                                            >{{
                                                MESSAGES.schedule.offDutyBadge
                                            }}</span
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
                    <ScheduleDailySummary
                        :summary="summary"
                        :title="isViewingToday
                            ? MESSAGES.schedule.dailySummary.todayTitle
                            : MESSAGES.schedule.dailySummary.dateTitle.replace('{date}', dayLabel(date))"
                    />
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
    <ScheduleMoveConfirmDialog
        :open="pendingMove !== null"
        :title="MESSAGES.reservation.confirmMove"
        :name="pendingMove?.reservation.customer_name ?? ''"
        :subtitle="pendingMove?.reservation.service_name"
        :before-label="pendingMove ? pendingBeforeLabel() : ''"
        :after-label="pendingMove ? pendingAfterLabel() : ''"
        :lane-change-label="pendingMove ? pendingLaneChangeLabel() : null"
        :target-date="pendingMove?.targetDate ?? ''"
        :submitting="moveSubmitting"
        @update:target-date="(v) => { if (pendingMove) pendingMove.targetDate = v; }"
        @cancel="cancelMove"
        @confirm="confirmMove"
    />

    <!-- 予定ブロックD&Dの確認（§40） -->
    <ScheduleMoveConfirmDialog
        :open="pendingBlockMove !== null"
        :title="MESSAGES.schedule.confirmBlockMove"
        :name="pendingBlockMove?.block.type_label ?? ''"
        :subtitle="pendingBlockMove?.block.title"
        :before-label="pendingBlockMove ? pendingBlockBeforeLabel() : ''"
        :after-label="pendingBlockMove ? pendingBlockAfterLabel() : ''"
        :lane-change-label="pendingBlockMove ? pendingBlockLaneChangeLabel() : null"
        :target-date="pendingBlockMove?.targetDate ?? ''"
        :submitting="blockMoveSubmitting"
        @update:target-date="(v) => { if (pendingBlockMove) pendingBlockMove.targetDate = v; }"
        @cancel="cancelBlockMove"
        @confirm="confirmBlockMove"
    />

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
    <ScheduleDragGhost
        v-if="draggedReservation && drag"
        :name="draggedReservation.customer_name"
        :x="drag.pointerX"
        :y="drag.pointerY"
        :start-min="drag.baseStartMin + drag.offsetMinutes"
        :duration-min="drag.durationMin"
    />

    <!-- オンライン予約通知（§34-37） -->
    <ScheduleNotifications
        :sound="notificationSound"
        @select="onNotificationSelect"
    />
</template>

<style scoped src="@/components/admin/schedule/styles/panel.css"></style>
<style scoped src="@/components/admin/schedule/styles/toolbar.css"></style>
<style scoped src="@/components/admin/schedule/styles/timeline.css"></style>
<style scoped src="@/components/admin/schedule/styles/reservation.css"></style>
<style scoped src="@/components/admin/schedule/styles/block-dnd.css"></style>
<style scoped src="@/components/admin/schedule/styles/week-mobile.css"></style>
