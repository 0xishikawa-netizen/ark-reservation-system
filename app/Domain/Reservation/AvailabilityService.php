<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Domain\Business\StoreCalendarService;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Models\Booth;
use App\Models\Service;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AvailabilityService
{
    private readonly SlotKey $slotKey;

    public function __construct(private readonly StoreCalendarService $storeCalendar)
    {
        $this->slotKey = SlotKey::fromSettings();
    }

    /**
     * @param  bool  $withBooths  true のとき、ブース未指定でも「空いているブースが1つ以上ある」
     *                            開始時刻だけを返し、各時刻に available_booth_ids を付ける
     *                            （台帳の「メニューで空きを確認」でブースも考慮するため）。
     * @return list<array{starts_at: string, ends_at: string, available_staff_ids: list<int>, available_booth_ids?: list<int>}>
     */
    public function openStartTimes(
        int $serviceId,
        ?int $staffId,
        ?int $boothId,
        CarbonImmutable $date,
        int $bufferMin = 0,
        bool $withBooths = false,
    ): array {
        $service = Service::query()->findOrFail($serviceId);
        // 着替え等のバッファも枠として押さえるため、空き判定は施術時間＋バッファで行う。
        $occupiedMin = $service->duration_min + max($bufferMin, 0);

        if (! $service->is_active) {
            return [];
        }

        $staffPool = $this->staffPool($service, $staffId);

        if (($staffId !== null || $service->requires_staff) && $staffPool === []) {
            return [];
        }

        if ($boothId !== null && ! Booth::query()->whereKey($boothId)->where('is_active', true)->exists()) {
            return [];
        }

        $calendarDay = $this->storeCalendar->resolve($date);

        if ($calendarDay['status'] === StoreCalendarDay::STATUS_CLOSED || $calendarDay['opens_at'] === null || $calendarDay['closes_at'] === null) {
            return [];
        }

        $open = $this->businessTime($date, $calendarDay['opens_at']);
        $close = $this->businessTime($date, $calendarDay['closes_at']);

        if ($close->lessThanOrEqualTo($open)) {
            return [];
        }

        // ブースも考慮する時の候補ブース。指定があればそのブースだけ、なければ有効な全ブース。
        $boothPool = $boothId !== null
            ? [$boothId]
            : ($withBooths
                ? Booth::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                    ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()
                : []);

        if ($withBooths && $boothPool === []) {
            return [];
        }

        $shiftsByStaff = $this->shiftsByStaff($staffPool, $date);
        $occupiedByResource = $this->occupiedByResource($staffPool, $boothPool, $date);
        $blockedRangesByResource = $this->blockedRangesByResource($staffPool, $boothPool, $date);
        $firstCandidate = $open;

        while ($firstCandidate->lessThan($close) && ! $this->slotKey->isBoundary($firstCandidate)) {
            $firstCandidate = $firstCandidate->addMinute();
        }

        $results = [];

        for (
            $startsAt = $firstCandidate;
            $startsAt->addMinutes($occupiedMin)->lessThanOrEqualTo($close);
            $startsAt = $startsAt->addMinutes($this->slotKey->slotMinutes())
        ) {
            $endsAt = $startsAt->addMinutes($occupiedMin);
            $slots = $this->slotKey->occupiedSlots($startsAt, $endsAt, false);
            $availableStaffIds = [];

            foreach ($staffPool as $candidateStaffId) {
                if (! $this->isWithinShift(
                    $startsAt,
                    $endsAt,
                    $shiftsByStaff[$candidateStaffId] ?? [],
                )) {
                    continue;
                }

                if ($this->hasOccupiedSlot(
                    ResourceType::Staff,
                    $candidateStaffId,
                    $slots,
                    $occupiedByResource,
                )) {
                    continue;
                }

                // 予定ブロック（休憩・ミーティング等）と重なる時間は予約不可（§34-35）。
                if ($this->hasBlockOverlap(
                    ResourceType::Staff,
                    $candidateStaffId,
                    $startsAt,
                    $endsAt,
                    $blockedRangesByResource,
                )) {
                    continue;
                }

                $availableStaffIds[] = $candidateStaffId;
            }

            if ($service->requires_staff && $availableStaffIds === []) {
                continue;
            }

            if ($staffId !== null && $availableStaffIds !== [$staffId]) {
                continue;
            }

            $availableBoothIds = [];

            foreach ($boothPool as $candidateBoothId) {
                if ($this->hasOccupiedSlot(
                    ResourceType::Booth,
                    $candidateBoothId,
                    $slots,
                    $occupiedByResource,
                ) || $this->hasBlockOverlap(
                    ResourceType::Booth,
                    $candidateBoothId,
                    $startsAt,
                    $endsAt,
                    $blockedRangesByResource,
                )) {
                    continue;
                }

                $availableBoothIds[] = $candidateBoothId;
            }

            // ブースを指定された時、またはブースも考慮する時は、空きブースが無ければ不可。
            if ($boothPool !== [] && $availableBoothIds === []) {
                continue;
            }

            $result = [
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'available_staff_ids' => $staffId !== null || ! $service->requires_staff
                    ? []
                    : $availableStaffIds,
            ];

            if ($withBooths) {
                $result['available_booth_ids'] = $availableBoothIds;
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * 指定した開始時刻ちょうどで空いている最初のブースを返す（メニュー選択時の自動割当用）。
     * あくまで画面側の初期提案であり、最終的な二重予約防止は既存の
     * ReservationService::create()/reschedule() が改めて検証する。
     */
    public function firstAvailableBooth(int $serviceId, CarbonImmutable $startsAt): ?int
    {
        $service = Service::query()->findOrFail($serviceId);

        if (! $service->is_active) {
            return null;
        }

        $endsAt = $startsAt->addMinutes($service->duration_min);
        $boothIds = Booth::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');

        foreach ($boothIds as $boothId) {
            $hasReservationConflict = DB::table('reservations')
                ->where('booth_id', $boothId)
                ->whereIn('status', [
                    ReservationStatus::PendingPayment->value,
                    ReservationStatus::PendingExternalSync->value,
                    ReservationStatus::Confirmed->value,
                ])
                ->where('starts_at', '<', $endsAt->format('Y-m-d H:i:s'))
                ->where('ends_at', '>', $startsAt->format('Y-m-d H:i:s'))
                ->exists();

            if ($hasReservationConflict) {
                continue;
            }

            $hasBlockConflict = DB::table('staff_schedule_blocks')
                ->where('booth_id', $boothId)
                ->whereDate('work_date', $startsAt->toDateString())
                ->whereTime('start_at', '<', $endsAt->format('H:i:s'))
                ->whereTime('end_at', '>', $startsAt->format('H:i:s'))
                ->exists();

            if ($hasBlockConflict) {
                continue;
            }

            return (int) $boothId;
        }

        return null;
    }

    /**
     * 期間内の日ごとに「その日に出勤予定のスタッフ」を返す。
     * 予約可能判定（openStartTimes）と同じ正本を使う: 勤務枠（staff_shifts。通常シフトの自動生成・特別勤務とも
     * ここに入る）が1つ以上あり、かつ店舗カレンダーで休業日・営業時間なしではない日だけを出勤とみなす。
     * ブッキングボードのスタッフ行の表示判定はこれを使い、予約可能判定と別の独自ロジックにしない。
     *
     * @param  list<int>  $staffIds
     * @return array<string, list<int>> 'Y-m-d' => スタッフID（期間内の全日付をキーに持つ）
     */
    public function workingStaffIdsByDate(array $staffIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendar = $this->storeCalendar->resolveRange($from, $to);
        $working = array_fill_keys(array_keys($calendar), []);

        if ($staffIds === []) {
            return $working;
        }

        $shifts = StaffShift::query()
            ->whereIn('staff_id', $staffIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get(['staff_id', 'work_date']);

        foreach ($shifts as $shift) {
            $date = CarbonImmutable::parse($shift->work_date)->toDateString();
            $day = $calendar[$date] ?? null;

            if ($day === null
                || $day['status'] === StoreCalendarDay::STATUS_CLOSED
                || $day['opens_at'] === null
                || $day['closes_at'] === null) {
                continue;
            }

            $working[$date][] = (int) $shift->staff_id;
        }

        return array_map(
            static fn (array $ids): array => array_values(array_unique($ids)),
            $working,
        );
    }

    /** @return list<int> */
    private function staffPool(Service $service, ?int $staffId): array
    {
        if ($staffId === null && ! $service->requires_staff) {
            return [];
        }

        $query = $service->staff()
            ->where('staff.is_bookable', true);

        if ($staffId !== null) {
            $query->where('staff.user_id', $staffId);
        }

        return $query
            ->orderBy('staff.user_id')
            ->pluck('staff.user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function businessTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $date->toDateString().' '.$time,
            $date->getTimezone(),
        );
    }

    /**
     * @param  list<int>  $staffIds
     * @return array<int, list<array{start: CarbonImmutable, end: CarbonImmutable}>>
     */
    private function shiftsByStaff(array $staffIds, CarbonImmutable $date): array
    {
        if ($staffIds === []) {
            return [];
        }

        $shifts = [];

        foreach (StaffShift::query()
            ->whereIn('staff_id', $staffIds)
            ->whereDate('work_date', $date->toDateString())
            ->get(['staff_id', 'start_at', 'end_at']) as $shift) {
            $shifts[(int) $shift->staff_id][] = [
                'start' => $this->businessTime($date, (string) $shift->start_at),
                'end' => $this->businessTime($date, (string) $shift->end_at),
            ];
        }

        return $shifts;
    }

    /**
     * @param  list<int>  $staffIds
     * @param  list<int>  $boothIds
     * @return array<string, array<string, true>>
     */
    private function occupiedByResource(
        array $staffIds,
        array $boothIds,
        CarbonImmutable $date,
    ): array {
        if ($staffIds === [] && $boothIds === []) {
            return [];
        }

        $rows = DB::table('reservation_resource_slots as slots')
            ->join('reservations', 'reservations.id', '=', 'slots.reservation_id')
            ->whereIn('reservations.status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
            ])
            ->where('slots.slot_start', '>=', $date->startOfDay()->format('Y-m-d H:i:s'))
            ->where('slots.slot_start', '<', $date->addDay()->startOfDay()->format('Y-m-d H:i:s'))
            ->where(function (Builder $query) use ($staffIds, $boothIds): void {
                if ($staffIds !== []) {
                    $query->where(function (Builder $staffQuery) use ($staffIds): void {
                        $staffQuery
                            ->where('slots.resource_type', ResourceType::Staff->value)
                            ->whereIn('slots.resource_id', $staffIds);
                    });
                }

                if ($boothIds !== []) {
                    $method = $staffIds === [] ? 'where' : 'orWhere';

                    $query->{$method}(function (Builder $boothQuery) use ($boothIds): void {
                        $boothQuery
                            ->where('slots.resource_type', ResourceType::Booth->value)
                            ->whereIn('slots.resource_id', $boothIds);
                    });
                }
            })
            ->get(['slots.resource_type', 'slots.resource_id', 'slots.slot_start']);

        $occupied = [];

        foreach ($rows as $row) {
            $resourceKey = $this->resourceKey(
                ResourceType::from((string) $row->resource_type),
                (int) $row->resource_id,
            );
            $occupied[$resourceKey][(string) $row->slot_start] = true;
        }

        return $occupied;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $shifts
     */
    private function isWithinShift(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        array $shifts,
    ): bool {
        foreach ($shifts as $shift) {
            if ($startsAt->greaterThanOrEqualTo($shift['start'])
                && $endsAt->lessThanOrEqualTo($shift['end'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CarbonImmutable>  $slots
     * @param  array<string, array<string, true>>  $occupiedByResource
     */
    private function hasOccupiedSlot(
        ResourceType $resourceType,
        int $resourceId,
        array $slots,
        array $occupiedByResource,
    ): bool {
        $occupiedSlots = $occupiedByResource[$this->resourceKey($resourceType, $resourceId)] ?? [];

        foreach ($slots as $slot) {
            if (isset($occupiedSlots[$slot->format('Y-m-d H:i:s')])) {
                return true;
            }
        }

        return false;
    }

    private function resourceKey(ResourceType $resourceType, int $resourceId): string
    {
        return $resourceType->value.':'.$resourceId;
    }

    /**
     * 予定ブロック（休憩・ミーティング等）の時間帯。予約とは別テーブルで管理しているため、
     * 同じ日付ぶんをまとめて1クエリで取得する（N+1禁止・§49）。
     *
     * @param  list<int>  $staffIds
     * @param  list<int>  $boothIds
     * @return array<string, list<array{start: CarbonImmutable, end: CarbonImmutable}>>
     */
    private function blockedRangesByResource(
        array $staffIds,
        array $boothIds,
        CarbonImmutable $date,
    ): array {
        if ($staffIds === [] && $boothIds === []) {
            return [];
        }

        $rows = DB::table('staff_schedule_blocks')
            ->whereDate('work_date', $date->toDateString())
            ->where(function (Builder $query) use ($staffIds, $boothIds): void {
                if ($staffIds !== []) {
                    $query->whereIn('staff_id', $staffIds);
                }

                if ($boothIds !== []) {
                    $method = $staffIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('booth_id', $boothIds);
                }
            })
            ->get(['staff_id', 'booth_id', 'start_at', 'end_at']);

        $ranges = [];

        foreach ($rows as $row) {
            $range = [
                'start' => $this->businessTime($date, (string) $row->start_at),
                'end' => $this->businessTime($date, (string) $row->end_at),
            ];

            if ($row->staff_id !== null) {
                $ranges[$this->resourceKey(ResourceType::Staff, (int) $row->staff_id)][] = $range;
            }

            if ($row->booth_id !== null) {
                $ranges[$this->resourceKey(ResourceType::Booth, (int) $row->booth_id)][] = $range;
            }
        }

        return $ranges;
    }

    /**
     * @param  array<string, list<array{start: CarbonImmutable, end: CarbonImmutable}>>  $blockedRangesByResource
     */
    private function hasBlockOverlap(
        ResourceType $resourceType,
        int $resourceId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        array $blockedRangesByResource,
    ): bool {
        $ranges = $blockedRangesByResource[$this->resourceKey($resourceType, $resourceId)] ?? [];

        foreach ($ranges as $range) {
            if ($startsAt->lessThan($range['end']) && $endsAt->greaterThan($range['start'])) {
                return true;
            }
        }

        return false;
    }
}
