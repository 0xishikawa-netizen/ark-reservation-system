<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Service;
use App\Models\Staff;

final class OnlineBookableServiceQuery
{
    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     duration_min: int,
     *     price: int,
     *     color: string|null,
     *     staff: list<array{id: int, display_name: string}>
     * }>
     */
    public function get(): array
    {
        return Service::query()
            ->select(['id', 'name', 'duration_min', 'price', 'color'])
            ->where('is_active', true)
            ->where('is_online_bookable', true)
            ->where('requires_staff', true)
            ->with(['staff' => function ($query): void {
                $query
                    ->select(['staff.user_id', 'staff.display_name'])
                    ->where('staff.is_bookable', true)
                    ->orderBy('staff.sort_order')
                    ->orderBy('staff.user_id');
            }])
            ->get()
            ->map(static fn (Service $service): array => [
                'id' => (int) $service->id,
                'name' => $service->name,
                'duration_min' => (int) $service->duration_min,
                'price' => (int) $service->price,
                'color' => $service->color,
                'staff' => $service->staff
                    ->map(static fn (Staff $staff): array => [
                        'id' => (int) $staff->user_id,
                        'display_name' => $staff->display_name,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
