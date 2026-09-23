<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

final class CustomerReservationListQuery
{
    /**
     * @return array{
     *     upcoming: list<array{id: int, starts_at: string, ends_at: string, service_id: int, service_name: string, staff_id: int|null, staff_name: string|null, status: string}>,
     *     past: list<array{id: int, starts_at: string, ends_at: string, service_id: int, service_name: string, staff_id: int|null, staff_name: string|null, status: string}>
     * }
     */
    public function get(int $customerId): array
    {
        $now = now();
        $baseQuery = Reservation::query()
            ->where('customer_id', $customerId)
            ->with([
                'service:id,name',
                'staff:user_id,display_name',
            ]);

        return [
            'upcoming' => $this->reservations(
                (clone $baseQuery)
                    ->where('starts_at', '>', $now)
                    ->orderBy('starts_at'),
            ),
            'past' => $this->reservations(
                (clone $baseQuery)
                    ->where('starts_at', '<=', $now)
                    ->orderByDesc('starts_at'),
            ),
        ];
    }

    /**
     * @param  Builder<Reservation>  $query
     * @return list<array{id: int, starts_at: string, ends_at: string, service_id: int, service_name: string, staff_id: int|null, staff_name: string|null, status: string}>
     */
    private function reservations(Builder $query): array
    {
        return $query
            ->get()
            ->map(static fn (Reservation $reservation): array => [
                'id' => (int) $reservation->id,
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $reservation->ends_at->format('Y-m-d H:i:s'),
                'service_id' => (int) $reservation->service_id,
                'service_name' => $reservation->service->name,
                'staff_id' => $reservation->staff_id === null ? null : (int) $reservation->staff_id,
                'staff_name' => $reservation->staff?->display_name,
                'status' => $reservation->status->value,
            ])
            ->values()
            ->all();
    }
}
