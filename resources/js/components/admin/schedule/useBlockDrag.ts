import { ref, type Ref, type ComputedRef } from "vue";
import { router } from "@inertiajs/vue3";
import { firstErrorMessage } from "@/composables/inertiaErrors";
import { MESSAGES } from "@/constants/messages";
import { timeToMinute, minuteToLabel, shiftDateBy } from "./scheduleFormat";
import type {
    ScheduleView,
    ScheduleLane,
    ScheduleBlock,
    LaneRect,
    PendingMove,
    BlockDragState,
    PendingBlockMove,
    DragClickGuard,
} from "./types";
import { DRAG_THRESHOLD_PX } from "./useReservationDrag";

/**
 * 予定ブロックのドラッグ&ドロップ（時間・担当・日付・§40。予約よりシンプル）。
 * 予約側のドラッグと共有する状態（日付ボタンへのドロップ・エラー通知など）は引数で受け取る。
 */
export interface BlockDragContext {
    canManage: ComputedRef<boolean>;
    closeMinute: ComputedRef<number>;
    lanes: ComputedRef<ScheduleLane[]>;
    openBlockDetailPanel: (blockId: number) => void;
    openMinute: ComputedRef<number>;
    pixelsPerMinute: ComputedRef<number>;
    timelineTrackHeight: ComputedRef<number>;
    viewMode: Ref<ScheduleView>;
    blockDrag: Ref<BlockDragState | null>;
    dragClickGuard: DragClickGuard;
    dateDropHoverOffset: Ref<-1 | 0 | 1 | null>;
    dndErrorToast: Ref<string | null>;
    hitTestDateDrop: (clientX: number, clientY: number) => -1 | 0 | 1 | null;
    laneRowIndex: (laneId: number | null) => number;
    pendingLaneName: (laneId: number | null, laneKind: "staff" | "booth") => string;
    pendingMove: Ref<PendingMove | null>;
    snapMinutes: ComputedRef<number>;
}

export function useBlockDrag(ctx: BlockDragContext) {
    const {
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
    } = ctx;

    /* ─────────── 予定ブロックの D&D（時間・担当・日付・§40。予約よりシンプル） ─────────── */

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
            dragClickGuard.suppress = true;
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
        if (dragClickGuard.suppress || pendingBlockMove.value !== null) {
            dragClickGuard.suppress = false;

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

    return {
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
    };
}
