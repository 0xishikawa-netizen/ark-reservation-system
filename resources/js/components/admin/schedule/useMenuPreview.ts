import { computed, ref, watch, type ComputedRef, type Ref } from "vue";
import { timeToMinute } from "./scheduleFormat";
import type { MenuOption, ScheduleLane, ScheduleView, ShadeSegment } from "./types";

/* メニュー別「本当に予約できる開始時刻」プレビュー（§11） */

interface PreviewSlot {
    starts_at: string;
    available_staff_ids: number[];
    /** その時刻に空いているブース（with_booths=1 で取得）。 */
    available_booth_ids?: number[];
}

interface MenuPreviewOptions {
    menuOptions: () => MenuOption[];
    slotMinutes: () => number;
    date: Ref<string>;
    viewMode: Ref<ScheduleView>;
    openMinute: ComputedRef<number>;
    closeMinute: ComputedRef<number>;
    pixelsPerMinute: ComputedRef<number>;
}

export function useMenuPreview(options: MenuPreviewOptions) {
    const previewServiceId = ref<number | null>(null);
    const previewSlots = ref<PreviewSlot[]>([]);
    const previewLoading = ref(false);
    let previewRequestId = 0;

    const previewService = computed<MenuOption | null>(
        () =>
            options.menuOptions().find((m) => m.id === previewServiceId.value) ?? null,
    );

    async function fetchPreview(): Promise<void> {
        const requestId = ++previewRequestId;
        const serviceId = previewServiceId.value;

        if (serviceId === null || options.viewMode.value !== "day") {
            previewSlots.value = [];
            previewLoading.value = false;

            return;
        }

        previewLoading.value = true;

        try {
            const params = new URLSearchParams({
                service_id: String(serviceId),
                date: options.date.value,
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

            const slots = response.ok
                ? ((await response.json()) as PreviewSlot[])
                : [];

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
        () => [previewServiceId.value, options.date.value, options.viewMode.value] as const,
        () => {
            void fetchPreview();
        },
    );

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
        const unit = Math.max(options.slotMinutes(), 5);
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
                left: (runStart - options.openMinute.value) * options.pixelsPerMinute.value,
                width: (endMinute - runStart) * options.pixelsPerMinute.value,
            });
            runStart = null;
        };

        for (
            let minute = options.openMinute.value;
            minute < options.closeMinute.value;
            minute += unit
        ) {
            // サーバーは「担当（必要なら）と空きブースが揃う時刻」だけを返す。
            // スタッフ行はそのスタッフが空いているか、ブース行はそのブースが空いているかで判定する。
            const canStart =
                minute + service.duration_min <= options.closeMinute.value &&
                (staffAxisWithStaff
                    ? (previewStaffByMinute.value
                          .get(minute)
                          ?.has(laneId as number) ?? false)
                    : lane.kind === "booth" && laneId !== null
                      ? (previewBoothsByMinute.value.get(minute)?.has(laneId) ??
                        false)
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

        flush(options.closeMinute.value);

        return { available, blocked };
    }

    return { previewServiceId, previewLoading, previewService, menuSegments };
}
