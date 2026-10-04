// 予約台帳（Schedule/Index.vue）で使う型。

export type ScheduleView = "day" | "week";
export type ScheduleAxis = "staff" | "booth" | "both";

export interface ScheduleLane {
    id: number | null;
    display_name: string;
    color: string;
    sort_order: number;
    kind: "staff" | "booth";
    /** 勤務予定外（休みだが予約・予定がある）スタッフ行。 */
    off_duty?: boolean;
}

export interface Staff {
    user_id: number;
    display_name: string;
    color: string;
    sort_order: number;
    /** 対象期間に勤務枠がある（false は休みなのに予約・予定が入っている等の矛盾データで表示しているスタッフ）。 */
    is_working?: boolean;
}

export interface OffStaff {
    user_id: number;
    display_name: string;
}

export interface StaffOption {
    user_id: number;
    display_name: string;
    color: string;
}

export interface Shift {
    staff_id: number;
    work_date: string;
    start_at: string;
    end_at: string;
}

export interface ScheduleReservation {
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
    staff_gender_preference: "male" | "female" | null;
}

export interface ScheduleBlock {
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

export interface BusinessHours {
    open: string;
    close: string;
    slot_minutes: number;
}

export interface Booth {
    id: number;
    name: string;
    sort_order: number;
}

export interface DateRange {
    start: string;
    end: string;
}

export interface Filters {
    date: string;
    staff_id: number | null;
    view: ScheduleView;
    axis: ScheduleAxis;
}

export interface ShadeSegment {
    left: number;
    width: number;
}

export interface MenuOption {
    id: number;
    name: string;
    duration_min: number;
    requires_staff: boolean;
    staff_ids: number[];
    price: number;
    category: string | null;
    color: string;
}

export interface BoothOption {
    id: number;
    name: string;
}

export interface DailySummary {
    total: number;
    completed: number;
    new_customers: number;
    repeat_customers: number;
    canceled: number;
    no_show: number;
    revenue: number | null;
}

export type DisplayRow =
    | {
          kind: "header";
          sectionKind: "staff" | "booth";
          label: string;
          icon: string;
      }
    | { kind: "lane"; lane: ScheduleLane };

export interface LaneRect {
    laneId: number | null;
    top: number;
    bottom: number;
}

export interface DragState {
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

export interface CrossDateMoveState {
    reservationId: number;
    reservation: ScheduleReservation;
}

export interface PendingMove {
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

export interface BlockDragState {
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

export interface PendingBlockMove {
    block: ScheduleBlock;
    newStartMin: number;
    newEndMin: number;
    laneChanged: boolean;
    newLaneId: number | null;
    laneKind: "staff" | "booth";
    /** 変更後の日付（ISO）。確認ダイアログ内の「日付を変更」カレンダーで任意の日付へ変更できる（§6）。 */
    targetDate: string;
}

/** 予約・予定ブロックのドラッグ直後に起きる click を 1 回だけ無視するための共有フラグ。 */
export interface DragClickGuard {
    suppress: boolean;
}
