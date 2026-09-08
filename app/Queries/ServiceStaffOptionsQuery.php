<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Service;
use App\Models\Staff;
use Illuminate\Support\Collection;

class ServiceStaffOptionsQuery
{
    /**
     * @return Collection<int, array{user_id: int, display_name: string, is_bookable: bool}>
     */
    public function get(): Collection
    {
        return Staff::query()
            ->select(['user_id', 'display_name', 'is_bookable', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Staff $staff): array => [
                'user_id' => $staff->user_id,
                'display_name' => $staff->display_name,
                'is_bookable' => $staff->is_bookable,
            ]);
    }

    /** @return list<int> */
    public function selectedIds(Service $service): array
    {
        return $service->staff()
            ->pluck('staff.user_id')
            ->map(fn (mixed $staffId): int => (int) $staffId)
            ->values()
            ->all();
    }
}
