<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Reservation\ReservationStatus;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleQuery
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{
     *   staff: list<array{user_id: int, display_name: string, color: string, sort_order: int}>,
     *   shifts: list<array{staff_id: int, start_at: string, end_at: string}>,
     *   reservations: list<array{id: int, customer_name: string, service_name: string, staff_id: int|null, booth_id: int|null, starts_at: string, ends_at: string, status: string, source: string}>,
     *   business_hours: array{open: string, close: string, slot_minutes: int}
     * }
     */
    public function get(CarbonImmutable $date, ?int $staffId = null): array
    {
        $staff = DB::table('staff')
            ->where('is_bookable', true)
            ->when($staffId !== null, fn ($query) => $query->where('user_id', $staffId))
            ->orderBy('sort_order')
            ->orderBy('user_id')
            ->get(['user_id', 'display_name', 'color', 'sort_order'])
            ->map(static fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'display_name' => (string) $row->display_name,
                'color' => (string) $row->color,
                'sort_order' => (int) $row->sort_order,
            ])
            ->all();
        $staffIds = array_column($staff, 'user_id');

        $shifts = $staffIds === []
            ? []
            : DB::table('staff_shifts')
                ->whereDate('work_date', $date->toDateString())
                ->whereIn('staff_id', $staffIds)
                ->orderBy('staff_id')
                ->orderBy('start_at')
                ->get(['staff_id', 'start_at', 'end_at'])
                ->map(static fn (object $row): array => [
                    'staff_id' => (int) $row->staff_id,
                    'start_at' => (string) $row->start_at,
                    'end_at' => (string) $row->end_at,
                ])
                ->all();

        $reservations = DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->whereDate('reservations.starts_at', $date->toDateString())
            ->whereIn('reservations.status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Completed->value,
                ReservationStatus::NoShow->value,
            ])
            ->when($staffId !== null, fn ($query) => $query->where('reservations.staff_id', $staffId))
            ->orderBy('reservations.starts_at')
            ->get([
                'reservations.id',
                'reservations.staff_id',
                'reservations.booth_id',
                'reservations.starts_at',
                'reservations.ends_at',
                'reservations.status',
                'reservations.source',
                'customer_users.name as customer_name',
                'services.name as service_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'customer_name' => (string) $row->customer_name,
                'service_name' => (string) $row->service_name,
                'staff_id' => $row->staff_id === null ? null : (int) $row->staff_id,
                'booth_id' => $row->booth_id === null ? null : (int) $row->booth_id,
                'starts_at' => (string) $row->starts_at,
                'ends_at' => (string) $row->ends_at,
                'status' => (string) $row->status,
                'source' => (string) $row->source,
            ])
            ->all();

        return [
            'staff' => $staff,
            'shifts' => $shifts,
            'reservations' => $reservations,
            'business_hours' => [
                'open' => (string) $this->settings->get(
                    'business_hours.open',
                    config('reservation.business_hours.open', '10:00'),
                ),
                'close' => (string) $this->settings->get(
                    'business_hours.close',
                    config('reservation.business_hours.close', '22:00'),
                ),
                'slot_minutes' => (int) $this->settings->get(
                    'reservation.slot_minutes',
                    config('reservation.slot_minutes', 15),
                ),
            ],
        ];
    }
}
