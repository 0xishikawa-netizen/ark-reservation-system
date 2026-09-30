<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ServiceListQuery
{
    /** @return Collection<int, Service>|LengthAwarePaginator<int, Service> */
    public function get(?string $search, bool $onlyActive, ?string $category = null): Collection|LengthAwarePaginator
    {
        $search = trim((string) $search);

        return Service::query()
            ->select([
                'id',
                'name',
                'duration_min',
                'price',
                'category',
                'analysis_category_id',
                'tax_category_id',
                'is_online_bookable',
                'requires_staff',
                'color',
                'is_active',
                'sort_order',
            ])
            ->with([
                'staff:user_id,display_name',
                'analysisCategory:id,name',
                'taxCategory:id,name',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->when($category !== null && $category !== '', fn ($query) => $query->where('category', $category))
            ->when($onlyActive, fn ($query) => $query->active())
            ->orderByDesc('is_active')
            ->get();
    }
}
