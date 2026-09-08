<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\TicketProduct;
use Illuminate\Database\Eloquent\Collection;

class TicketProductListQuery
{
    /** @return Collection<int, TicketProduct> */
    public function get(): Collection
    {
        return TicketProduct::query()
            ->select([
                'id',
                'name',
                'total_count',
                'price',
                'validity_days',
                'is_active',
                'sort_order',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
