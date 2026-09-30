<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StaffShift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class StaffShiftListQuery
{
    /** @return Collection<int, StaffShift> */
    public function get(
        ?int $staffId,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        return StaffShift::query()
            ->select(['id', 'staff_id', 'work_date', 'start_at', 'end_at', 'origin'])
            ->with('staff:user_id,display_name,color,sort_order')
            ->when($staffId !== null, fn ($query) => $query->where('staff_id', $staffId))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('work_date')
            ->orderBy('start_at')
            ->orderBy('staff_id')
            ->get();
    }
}
