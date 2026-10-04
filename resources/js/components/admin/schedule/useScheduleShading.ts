import { type Ref, type ComputedRef } from "vue";
import { timeToMinute } from "./scheduleFormat";
import type {
    ScheduleLane,
    Shift,
    ScheduleReservation,
    ScheduleBlock,
    BusinessHours,
    ShadeSegment,
} from "./types";

/**
 * 週表示（§2-3）の帯グラフ用の位置計算と、スタッフの勤務外（非稼働）帯の計算。
 * 日表示のタイムラインをそのまま7日ぶん並べると横に広くなりすぎるため、1日ぶんの営業時間を100%とする帯で表す。
 */
interface WeekRect {
    leftPct: number;
    widthPct: number;
}

export interface UseScheduleShadingContext {
    closeMinute: ComputedRef<number>;
    date: Ref<string>;
    openMinute: ComputedRef<number>;
    pixelsPerMinute: ComputedRef<number>;
    props: { shifts: Shift[]; business_hours: BusinessHours };
    serviceEndLabel: (reservation: ScheduleReservation) => string;
}

export function useScheduleShading(ctx: UseScheduleShadingContext) {
    const {
        closeMinute,
        date,
        openMinute,
        pixelsPerMinute,
        props,
        serviceEndLabel,
    } = ctx;

    /* ───────────── 週表示（§2-3）─────────────
     * 日表示のタイムラインをそのまま7日ぶん並べると横に広くなりすぎるため、
     * スタッフ（または ブース）を行、日付を列とした「1日ぶんの営業時間を100%とする
     * コンパクトな帯グラフ」で1週間を俯瞰できるようにする。クリック時に開くパネル・
     * 予約詳細・空き枠からの新規予約/予定追加は日表示と同じ関数をそのまま再利用する
     * （業務ロジックの重複実装はしない）。 */

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

    return {
        nonWorkingSegments,
        weekBarRect,
        weekBlockTooltip,
        weekNonWorkingRects,
        weekRectStyle,
        weekReservationTooltip,
    };
}
