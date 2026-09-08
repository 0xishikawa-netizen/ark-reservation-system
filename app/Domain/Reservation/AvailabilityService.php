<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Models\Booth;
use App\Models\Service;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AvailabilityService
{
    private readonly SlotKey $slotKey;

    public function __construct(private readonly Settings $settings)
    {
        $this->slotKey = SlotKey::fromSettings();
    }

    /**
     * @return list<array{starts_at: string, ends_at: string, available_staff_ids: list<int>}>
     */
    public function openStartTimes(
        int $serviceId,
        ?int $staffId,
        ?int $boothId,
        CarbonImmutable $date,
    ): array {
        $service = Service::query()->findOrFail($serviceId);

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

        $open = $this->businessTime(
            $date,
            (string) $this->settings->get(
                'business_hours.open',
                config('reservation.business_hours.open', '10:00'),
            ),
        );
        $close = $this->businessTime(
            $date,
            (string) $this->settings->get(
                'business_hours.close',
                config('reservation.business_hours.close', '22:00'),
            ),
        );

        if ($close->lessThanOrEqualTo($open)) {
            return [];
        }

        $shiftsByStaff = $this->shiftsByStaff($staffPool, $date);
        $occupiedByResource = $this->occupiedByResource($staffPool, $boothId, $date);
        $firstCandidate = $open;

        while ($firstCandidate->lessThan($close) && ! $this->slotKey->isBoundary($firstCandidate)) {
            $firstCandidate = $firstCandidate->addMinute();
        }

        $results = [];

        for (
            $startsAt = $firstCandidate;
            $startsAt->addMinutes($service->duration_min)->lessThanOrEqualTo($close);
            $startsAt = $startsAt->addMinutes($this->slotKey->slotMinutes())
        ) {
            $endsAt = $startsAt->addMinutes($service->duration_min);
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

                $availableStaffIds[] = $candidateStaffId;
            }

            if ($service->requires_staff && $availableStaffIds === []) {
                continue;
            }

            if ($staffId !== null && $availableStaffIds !== [$staffId]) {
                continue;
            }

            if ($boothId !== null && $this->hasOccupiedSlot(
                ResourceType::Booth,
                $boothId,
                $slots,
                $occupiedByResource,
            )) {
                continue;
            }

            $results[] = [
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'available_staff_ids' => $staffId !== null || ! $service->requires_staff
                    ? []
                    : $availableStaffIds,
            ];
        }

        return $results;
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
     * @return array<string, array<string, true>>
     */
    private function occupiedByResource(
        array $staffIds,
        ?int $boothId,
        CarbonImmutable $date,
    ): array {
        if ($staffIds === [] && $boothId === null) {
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
            ->where(function (Builder $query) use ($staffIds, $boothId): void {
                if ($staffIds !== []) {
                    $query->where(function (Builder $staffQuery) use ($staffIds): void {
                        $staffQuery
                            ->where('slots.resource_type', ResourceType::Staff->value)
                            ->whereIn('slots.resource_id', $staffIds);
                    });
                }

                if ($boothId !== null) {
                    $method = $staffIds === [] ? 'where' : 'orWhere';

                    $query->{$method}(function (Builder $boothQuery) use ($boothId): void {
                        $boothQuery
                            ->where('slots.resource_type', ResourceType::Booth->value)
                            ->where('slots.resource_id', $boothId);
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
}
