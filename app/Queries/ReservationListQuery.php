<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ReservationListQuery
{
    /** @return LengthAwarePaginator<int, array<string, int|string|null>> */
    public function paginate(
        ?string $date,
        ?int $staffId,
        ?string $status,
        int $perPage = 20,
        ?int $customerId = null,
    ): LengthAwarePaginator {
        $paginator = DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->leftJoin('staff', 'staff.user_id', '=', 'reservations.staff_id')
            ->select([
                'reservations.id',
                'reservations.starts_at',
                'reservations.ends_at',
                'reservations.status',
                'reservations.source',
                'reservations.staff_id',
                'customer_users.name as customer_name',
                'services.name as service_name',
                'staff.display_name as staff_name',
            ])
            ->when($date !== null, fn ($query) => $query->whereDate('reservations.starts_at', $date))
            ->when($staffId !== null, fn ($query) => $query->where('reservations.staff_id', $staffId))
            ->when($status !== null, fn ($query) => $query->where('reservations.status', $status))
            ->when($customerId !== null, fn ($query) => $query->where('reservations.customer_id', $customerId))
            ->orderByDesc('reservations.starts_at')
            ->orderByDesc('reservations.id')
            ->paginate($perPage)
            ->withQueryString();

        return $paginator->through(static fn (object $row): array => [
            'id' => (int) $row->id,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'customer_name' => (string) $row->customer_name,
            'service_name' => (string) $row->service_name,
            'staff_id' => $row->staff_id === null ? null : (int) $row->staff_id,
            'staff_name' => $row->staff_name === null ? null : (string) $row->staff_name,
            'status' => (string) $row->status,
            'source' => (string) $row->source,
        ]);
    }
}
