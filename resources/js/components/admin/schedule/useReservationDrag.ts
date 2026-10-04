import { ref, computed, reactive, watch, onBeforeUnmount, type Ref, type ComputedRef } from "vue";
import { router } from "@inertiajs/vue3";
import { firstErrorMessage } from "@/composables/inertiaErrors";
import { MESSAGES } from "@/constants/messages";
import { timeToMinute, minuteToLabel, shiftDateBy } from "./scheduleFormat";
import type {
    ScheduleView,
    ScheduleAxis,
    ScheduleLane,
    ScheduleReservation,
    ScheduleBlock,
    BusinessHours,
    LaneRect,
    DragState,
    CrossDateMoveState,
    PendingMove,
    BlockDragState,
    DragClickGuard,
} from "./types";

/**
 * 予約カードのドラッグ&ドロップ（時間／担当スタッフ・ブース／日付の変更・§13-17, §27-33）。
 * 台帳ページの状態（レーン・時間軸・パネル操作）は引数で受け取り、ドラッグ中の状態と操作を返す。
 */
export const DRAG_THRESHOLD_PX = 6;

export interface ReservationDragContext {
    axisMode: Ref<ScheduleAxis>;
    blocksFor: (lane: { id: number | null; kind: "staff" | "booth" }, day: string) => ScheduleBlock[];
    canManage: ComputedRef<boolean>;
    closeMinute: ComputedRef<number>;
    date: Ref<string>;
    lanes: ComputedRef<ScheduleLane[]>;
    openMinute: ComputedRef<number>;
    openReservationPanel: (reservationId: number) => void;
    pixelsPerMinute: ComputedRef<number>;
    previewServiceId: Ref<number | null>;
    props: { business_hours: BusinessHours; reservations: ScheduleReservation[] };
    reservationsFor: (lane: { id: number | null; kind: "staff" | "booth" }, day: string) => ScheduleReservation[];
    slotPickGhostVisible: ComputedRef<boolean>;
    timelineTrackHeight: ComputedRef<number>;
    viewMode: Ref<ScheduleView>;
    blockDrag: Ref<BlockDragState | null>;
    drag: Ref<DragState | null>;
    dragClickGuard: DragClickGuard;
}

export function useReservationDrag(ctx: ReservationDragContext) {
    const {
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
        drag,
        dragClickGuard,
    } = ctx;

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

    const pendingMove = ref<PendingMove | null>(null);
    const moveSubmitting = ref(false);

    /** D&D（予約・予定ブロック共通）がサーバー側で拒否された時のトースト。
     * サーバー拒否時はダイアログを閉じてカードを即座に元の位置へ戻し、
     * 理由だけこのトーストで伝える（再読込しないと直らない見た目のズレを防ぐ・§ D&D失敗時ロールバック）。 */
    const dndErrorToast = ref<string | null>(null);

    // クリックとドラッグの分離（§16, §33）。pointer がこの距離を超えて動いたら drag とみなし、
    // その pointerup 直後の click では詳細パネルを開かない。

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
            dragClickGuard.suppress = true;

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
        if (dragClickGuard.suppress || pendingMove.value !== null) {
            dragClickGuard.suppress = false;

            return;
        }

        openReservationPanel(reservation.id);
    }

    function cancelMove(): void {
        pendingMove.value = null;
        moveSubmitting.value = false;
        cancelCrossDateMove();
    }

    function pendingLaneName(
        laneId: number | null,
        kind: "staff" | "booth",
    ): string {
        if (laneId === null) {
            return kind === "staff" ? "担当なし" : "ブース未割当";
        }

        return (
            lanes.value.find((lane) => lane.kind === kind && lane.id === laneId)
                ?.display_name ?? ""
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

    return {
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
    };
}
