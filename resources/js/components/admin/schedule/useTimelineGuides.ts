import { ref, computed, type Ref, type ComputedRef } from "vue";
import { timeToMinute, minuteToLabel, laneKey } from "./scheduleFormat";
import type {
    ScheduleView,
    ScheduleAxis,
    ScheduleLane,
    Staff,
    Shift,
    ScheduleReservation,
    ScheduleBlock,
    BusinessHours,
    BlockDragState,
    DragState,
} from "./types";

/** 1時間あたりの分数。 */
const MINUTES_PER_HOUR = 60;
/** 30分目盛りの間隔。 */
const HALF_HOUR_MINUTES = 30;
/** 細線目盛りの間隔。 */
const FINE_TICK_MINUTES = 10;
/** 予約枠単位の最小値。 */
const MIN_SLOT_UNIT_MINUTES = 1;
/** グリッド線の幅。 */
const GRID_LINE_WIDTH_PX = 1;
/** カーソル枠を濃く表示する幅のしきい値。 */
const COMPACT_HOVER_WIDTH_PX = 8;
/** 予約不可帯を集計する最小刻み。 */
const MIN_UNBOOKABLE_UNIT_MINUTES = 5;

/**
 * 時間軸の目盛り・グリッド、カーソル位置のガイド、新規予約を入れられない時間帯（稼働外／満席）の帯。
 */
interface HourBlock {
    minute: number;
    label: string;
    left: number;
    width: number;
}

interface UnbookableSegment {
    left: number;
    width: number;
    kind: "closed" | "full";
}

export interface UseTimelineGuidesContext {
    axisMode: Ref<ScheduleAxis>;
    blockDrag: Ref<BlockDragState | null>;
    drag: Ref<DragState | null>;
    closeMinute: ComputedRef<number>;
    date: Ref<string>;
    lanes: ComputedRef<ScheduleLane[]>;
    openMinute: ComputedRef<number>;
    pixelsPerMinute: ComputedRef<number>;
    props: { business_hours: BusinessHours; shifts: Shift[]; staff: Staff[]; reservations: ScheduleReservation[]; blocks: ScheduleBlock[] };
    reservationsFor: (lane: { id: number | null; kind: "staff" | "booth" }, day: string) => ScheduleReservation[];
    viewMode: Ref<ScheduleView>;
}

export function useTimelineGuides(ctx: UseTimelineGuidesContext) {
    const {
        axisMode,
        blockDrag,
        drag,
        closeMinute,
        date,
        lanes,
        openMinute,
        pixelsPerMinute,
        props,
        reservationsFor,
        viewMode,
    } = ctx;

    /** 時間軸を「1 時間ごとの見出し（中央寄せ）」として組み立てる。 */

    const hourBlocks = computed<HourBlock[]>(() => {
        const blocks: HourBlock[] = [];
        const firstHour = Math.ceil(openMinute.value / MINUTES_PER_HOUR) * MINUTES_PER_HOUR;

        for (let minute = firstHour; minute < closeMinute.value; minute += MINUTES_PER_HOUR) {
            const blockEnd = Math.min(minute + MINUTES_PER_HOUR, closeMinute.value);
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
            minute += HALF_HOUR_MINUTES
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
            if ((minute - openMinute.value) % HALF_HOUR_MINUTES !== 0) {
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
        const half = HALF_HOUR_MINUTES * pixelsPerMinute.value;
        const hour = MINUTES_PER_HOUR * pixelsPerMinute.value;
        // 線は各区間の「先頭」に置く。ヘッダーの時間ブロックが border-left（＝ブロック左端）
        // で線を描いているため、末尾に置くと 1px ずれて見える（§ヘッダーと縦線のずれ）。
        const line = (size: number, color: string): string =>
            `repeating-linear-gradient(to right, ${color} 0, ${color} ${GRID_LINE_WIDTH_PX}px, transparent ${GRID_LINE_WIDTH_PX}px, transparent ${size}px)`;

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
        Math.max(props.business_hours.slot_minutes, MIN_SLOT_UNIT_MINUTES),
    );

    /**
     * 縦の罫線・細かい目盛りの単位は 10 分で固定する。予約枠は5分だが、5分ごとに
     * 線を引くと密すぎて逆に読めなくなるため。5分の精度はカーソルガイド側で示す。
     */
    /* ───────────── カーソル位置ガイド（今どの時間の上にいるか） ─────────────
     * 5分単位など細かい粒度だと、マウスがどの時刻を指しているのか見た目では分からない。
     * 予約枠と同じ単位にスナップした縦線＋時刻ラベルを出して、クリック前に確認できるようにする。 */
    const hoverMinute = ref<number | null>(null);
    /** ガイドを出すレーン（スタッフ／ブース行）。全行に出すと、どの行を指しているか逆に分かりにくい。 */
    const hoverLaneKey = ref<string | null>(null);

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
        const raw =
            openMinute.value + (event.clientX - rect.left) / pixelsPerMinute.value;
        const unit = slotUnitMinutes.value;
        // 枠を帯で塗るので、四捨五入ではなく切り捨てて「今いる枠の開始」に合わせる。
        // 営業開始からの相対で刻むことで、グリッド線と必ず同じ位置に乗る。
        const slotStart =
            openMinute.value + Math.floor((raw - openMinute.value) / unit) * unit;

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
        hoverWidth.value < COMPACT_HOVER_WIDTH_PX
            ? "rgba(var(--v-theme-accent), 0.9)"
            : "rgba(var(--v-theme-accent), 0.22)",
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


    /** 新規予約が入れられない時間帯（稼働外 / 満席）を帯で示す。 */
    const unbookableSegments = computed<UnbookableSegment[]>(() => {
        // 「両方」表示はスタッフ・ブースという別種のリソースが混在するため、
        // 全体「満席」の帯は意味が一意に決まらない。誤解を避けて表示しない。
        if (viewMode.value !== "day" || axisMode.value === "both") {
            return [];
        }

        const unit = Math.max(props.business_hours.slot_minutes, MIN_UNBOOKABLE_UNIT_MINUTES);
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

    return {
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
    };
}
