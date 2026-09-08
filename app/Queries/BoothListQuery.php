<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Booth;
use Illuminate\Database\Eloquent\Collection;

class BoothListQuery
{
    /** @return Collection<int, Booth> */
    public function get(?string $search): Collection
    {
        $search = trim((string) $search);

        return Booth::query()
            ->select(['id', 'name', 'sort_order', 'is_active'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%");
            })
            ->reorder()
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->get();
    }
}
