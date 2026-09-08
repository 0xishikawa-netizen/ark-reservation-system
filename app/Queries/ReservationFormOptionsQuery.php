<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Support\Facades\DB;

final class ReservationFormOptionsQuery
{
    /**
     * @return array{
     *   services: list<array{id: int, name: string, duration_min: int, requires_staff: bool, staff_ids: list<int>}>,
     *   staff: list<array{user_id: int, display_name: string, color: string}>,
     *   booths: list<array{id: int, name: string}>
     * }
     */
    public function get(): array
    {
        $staffIdsByService = [];

        foreach (DB::table('service_staff')->orderBy('staff_id')->get() as $row) {
            $staffIdsByService[(int) $row->service_id][] = (int) $row->staff_id;
        }

        $services = DB::table('services')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'duration_min', 'requires_staff'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'duration_min' => (int) $row->duration_min,
                'requires_staff' => (bool) $row->requires_staff,
                'staff_ids' => $staffIdsByService[(int) $row->id] ?? [],
            ])
            ->all();

        $staff = $this->staff();

        $booths = DB::table('booths')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();

        return compact('services', 'staff', 'booths');
    }

    /** @return list<array{user_id: int, display_name: string, color: string}> */
    public function staff(): array
    {
        return DB::table('staff')
            ->where('is_bookable', true)
            ->orderBy('sort_order')
            ->orderBy('user_id')
            ->get(['user_id', 'display_name', 'color'])
            ->map(static fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'display_name' => (string) $row->display_name,
                'color' => (string) $row->color,
            ])
            ->all();
    }

    /** @return array<string, int|string|null> */
    public function reservation(int $reservationId): array
    {
        $row = DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->leftJoin('staff', 'staff.user_id', '=', 'reservations.staff_id')
            ->leftJoin('booths', 'booths.id', '=', 'reservations.booth_id')
            ->where('reservations.id', $reservationId)
            ->firstOrFail([
                'reservations.id',
                'reservations.customer_id',
                'reservations.service_id',
                'reservations.staff_id',
                'reservations.booth_id',
                'reservations.starts_at',
                'reservations.ends_at',
                'reservations.status',
                'reservations.source',
                'reservations.version',
                'reservations.notes',
                'customer_users.name as customer_name',
                'services.name as service_name',
                'staff.display_name as staff_name',
                'booths.name as booth_name',
            ]);

        return [
            'id' => (int) $row->id,
            'customer_id' => (int) $row->customer_id,
            'customer_name' => (string) $row->customer_name,
            'service_id' => (int) $row->service_id,
            'service_name' => (string) $row->service_name,
            'staff_id' => $row->staff_id === null ? null : (int) $row->staff_id,
            'staff_name' => $row->staff_name === null ? null : (string) $row->staff_name,
            'booth_id' => $row->booth_id === null ? null : (int) $row->booth_id,
            'booth_name' => $row->booth_name === null ? null : (string) $row->booth_name,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'status' => (string) $row->status,
            'source' => (string) $row->source,
            'version' => (int) $row->version,
            'notes' => $row->notes === null ? null : (string) $row->notes,
        ];
    }
}
