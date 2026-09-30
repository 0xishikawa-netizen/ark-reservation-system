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

    public function __construct(
        private readonly StoreCalendarService $storeCalendar,
        private readonly BookingResourceResolver $resources,
    ) {
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

        // メニューに具体ブースが紐付いていれば、紐付けブースのどれか1つが空いている時だけ予約可能（Task 11-28）。
        // 1つ目のブースが埋まっていても、別の紐付けブースが空いていれば予約できる。
        $boothRestricted = $this->resources->requiresMappedBooth($service);

        if ($boothId !== null && ! $this->resources->boothAllowed($service, $boothId)) {
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

        // ブースも考慮する時の候補ブース。指定があればそのブースだけ、なければメニューで使えるブース
        // （紐付けが無いメニューは全有効ブース）。紐付けのあるメニューは常にブースも考慮する。
        $boothPool = $boothId !== null
            ? [$boothId]
            : ($withBooths || $boothRestricted ? $this->resources->candidateBoothIds($service) : []);

        if (($withBooths || $boothRestricted) && $boothPool === []) {
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
     * 指定の開始時刻で予約できない理由（画面のトースト表示用）。予約できる時は空配列。
     * openStartTimes と同じ判定（施術可否・資格・休業日・営業時間・勤務・他予約・予定・ブース）を1時刻について行い、
     * 何を直せば取れるかが分かる言葉で返す（以前は「このメニュー・担当では空いていません」だけだった）。
     *
     * @return list<string>
     */
    public function explainUnavailable(int $serviceId, ?int $staffId, ?int $boothId, CarbonImmutable $startsAt, int $bufferMin = 0): array
    {
        $service = Service::query()->find($serviceId);
        if ($service === null || ! $service->is_active) {
            return [__('messages.availability_reason.service_inactive')];
        }
        $staffName = $staffId === null ? null : (string) DB::table('staff')->where('user_id', $staffId)->value('display_name');
        $date = $startsAt->startOfDay();
        $endsAt = $startsAt->addMinutes($service->duration_min + max($bufferMin, 0));
        $reasons = [];

        // 担当スタッフ：施術可否 → 資格 → 予約可否。
        if ($staffId !== null) {
            if (! DB::table('service_staff')->where('service_id', $service->id)->where('staff_id', $staffId)->exists()) {
                return [__('messages.availability_reason.staff_cannot_perform', ['staff' => $staffName, 'service' => $service->name])];
            }
            if (! $this->resources->isQualified($service, $staffId)) {
                $required = DB::table('qualification_service as qs')->join('qualifications as q', 'q.id', '=', 'qs.qualification_id')
                    ->where('qs.service_id', $service->id)->pluck('q.name')->implode('・');

                return [__('messages.availability_reason.staff_not_qualified', ['staff' => $staffName, 'service' => $service->name, 'qualifications' => $required])];
            }
        }

        // 店の営業日・営業時間。
        $calendarDay = $this->storeCalendar->resolve($date);
        if ($calendarDay['status'] === StoreCalendarDay::STATUS_CLOSED || $calendarDay['opens_at'] === null || $calendarDay['closes_at'] === null) {
            return [__('messages.availability_reason.store_closed')];
        }
        $open = $this->businessTime($date, $calendarDay['opens_at']);
        $close = $this->businessTime($date, $calendarDay['closes_at']);
        $hours = substr((string) $calendarDay['opens_at'], 0, 5).'〜'.substr((string) $calendarDay['closes_at'], 0, 5);
        if ($startsAt->lessThan($open) || $endsAt->greaterThan($close)) {
            $reasons[] = __('messages.availability_reason.outside_business_hours', ['hours' => $hours, 'end' => $endsAt->format('H:i')]);
        }
        if (! $this->slotKey->isBoundary($startsAt)) {
            $reasons[] = __('messages.availability_reason.not_boundary', ['minutes' => $this->slotKey->slotMinutes()]);
        }

        $slots = $this->slotKey->occupiedSlots($startsAt, $endsAt, false);

        // 担当スタッフの勤務・他予約・予定。
        if ($staffId !== null) {
            $shifts = $this->shiftsByStaff([$staffId], $date)[$staffId] ?? [];
            if ($shifts === []) {
                $reasons[] = __('messages.availability_reason.staff_no_shift', ['staff' => $staffName]);
            } elseif (! $this->isWithinShift($startsAt, $endsAt, $shifts)) {
                $ranges = collect($shifts)->map(fn (array $shift): string => $shift['start']->format('H:i').'〜'.$shift['end']->format('H:i'))->implode('、');
                $reasons[] = __('messages.availability_reason.staff_outside_shift', ['staff' => $staffName, 'shift' => $ranges, 'end' => $endsAt->format('H:i')]);
            }
            $conflict = $this->conflictingReservation($staffId, $slots);
            if ($conflict !== null) {
                $reasons[] = __('messages.availability_reason.staff_booked', ['staff' => $staffName, 'range' => $conflict]);
            }
            if ($this->hasBlockOverlap(ResourceType::Staff, $staffId, $startsAt, $endsAt, $this->blockedRangesByResource([$staffId], [], $date))) {
                $reasons[] = __('messages.availability_reason.staff_blocked', ['staff' => $staffName]);
            }
        } elseif ($service->requires_staff) {
            $pool = $this->staffPool($service, null);
            if ($pool === []) {
                $reasons[] = __('messages.availability_reason.no_capable_staff', ['service' => $service->name]);
            } else {
                $shiftsByStaff = $this->shiftsByStaff($pool, $date);
                $occupied = $this->occupiedByResource($pool, [], $date);
                $blocked = $this->blockedRangesByResource($pool, [], $date);
                $free = array_filter($pool, fn (int $id): bool => $this->isWithinShift($startsAt, $endsAt, $shiftsByStaff[$id] ?? [])
                    && ! $this->hasOccupiedSlot(ResourceType::Staff, $id, $slots, $occupied)
                    && ! $this->hasBlockOverlap(ResourceType::Staff, $id, $startsAt, $endsAt, $blocked));
                if ($free === []) {
                    $reasons[] = __('messages.availability_reason.all_staff_busy', ['service' => $service->name]);
                }
            }
        }

        // ブース。
        if ($boothId !== null && ! $this->resources->boothAllowed($service, $boothId)) {
            $reasons[] = __('messages.availability_reason.booth_not_allowed', ['booth' => (string) DB::table('booths')->where('id', $boothId)->value('name'), 'service' => $service->name]);
        } else {
            $mapped = $this->resources->requiresMappedBooth($service);
            $boothPool = $boothId !== null ? [$boothId] : ($mapped ? $this->resources->candidateBoothIds($service) : []);
            // 紐付けたブースがすべて無効だと、どの時間も予約できない。
            if ($boothId === null && $mapped && $boothPool === []) {
                $reasons[] = __('messages.availability_reason.no_active_booth', ['service' => $service->name]);
            }
            if ($boothPool !== []) {
                $occupied = $this->occupiedByResource([], $boothPool, $date);
                $blocked = $this->blockedRangesByResource([], $boothPool, $date);
                $free = array_filter($boothPool, fn (int $id): bool => ! $this->hasOccupiedSlot(ResourceType::Booth, $id, $slots, $occupied)
                    && ! $this->hasBlockOverlap(ResourceType::Booth, $id, $startsAt, $endsAt, $blocked));
                if ($free === []) {
                    $names = DB::table('booths')->whereIn('id', $boothPool)->orderBy('sort_order')->pluck('name')->implode('・');
                    $reasons[] = $boothId !== null
                        ? __('messages.availability_reason.booth_busy', ['booth' => $names])
                        : __('messages.availability_reason.all_booths_busy', ['booths' => $names]);
                }
            }
        }

        return $reasons;
    }

    /**
     * スタッフが押さえている枠（reservation_resource_slots）のうち、指定時間と重なる予約の時間帯。無ければ null。
     * 空き枠計算と同じ「枠」で判定する（完了済みでも枠を押さえている予約は重なりとして扱う）。
     *
     * @param  list<CarbonImmutable>  $slots
     */
    private function conflictingReservation(int $staffId, array $slots): ?string
    {
        if ($slots === []) {
            return null;
        }
        $reservationId = DB::table('reservation_resource_slots')
            ->where('resource_type', ResourceType::Staff->value)->where('resource_id', $staffId)
            ->whereIn('slot_start', array_map(static fn (CarbonImmutable $slot): string => $slot->format('Y-m-d H:i:s'), $slots))
            ->orderBy('slot_start')->value('reservation_id');
        if ($reservationId === null) {
            return null;
        }
        $row = DB::table('reservations')->where('id', $reservationId)->first(['starts_at', 'ends_at']);

        return $row === null ? null
            : CarbonImmutable::parse((string) $row->starts_at)->format('H:i').'〜'.CarbonImmutable::parse((string) $row->ends_at)->format('H:i');
    }

    /**
     * 指定した開始時刻ちょうどで空いている最初のブースを返す（メニュー選択時の自動割当用）。
     * メニューに具体ブースの紐付けがあればその中から選ぶ（Task 11-28）。終了後バッファも含めて判定する。
     * あくまで画面側の初期提案であり、最終的な二重予約防止は既存の
     * ReservationService::create()/reschedule() と枠の一意制約が改めて検証する。
     */
    public function firstAvailableBooth(int $serviceId, CarbonImmutable $startsAt, int $bufferMin = 0): ?int
    {
        $service = Service::query()->findOrFail($serviceId);

        if (! $service->is_active) {
            return null;
        }

        return $this->resources->firstFreeBooth(
            $this->resources->candidateBoothIds($service),
            $startsAt,
            $startsAt->addMinutes($service->duration_min + max($bufferMin, 0)),
        );
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
        // 必要資格（はり等）を全部保有しているスタッフだけ（Task 11-28）。
        $this->resources->whereQualified($query, $service);

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
