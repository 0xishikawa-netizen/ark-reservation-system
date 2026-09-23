<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

class StaffListQuery
{
    /** @return Collection<int, Staff> */
    public function get(): Collection
    {
        return Staff::query()
            ->select(['user_id', 'display_name', 'color', 'is_bookable', 'sort_order'])
            ->with([
                'user:id,name,email,is_active',
                'user.roles:id,name',
            ])
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();
    }

    public function forEdit(Staff $staff): Staff
    {
        return $staff->load(['user:id,name,email,is_active', 'user.roles:id,name']);
    }
}
