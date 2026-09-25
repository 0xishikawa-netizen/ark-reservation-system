<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Models\EmploymentType;
use App\Models\Staff;
use App\Models\StaffEmploymentPeriod;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class StaffEmploymentService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function assignFrom(
        Staff $staff,
        EmploymentType $type,
        string $effectiveFrom,
        ?Authenticatable $actor,
    ): StaffEmploymentPeriod {
        return DB::transaction(function () use ($staff, $type, $effectiveFrom, $actor): StaffEmploymentPeriod {
            Staff::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            $start = CarbonImmutable::parse($effectiveFrom)->startOfDay();
            $periods = StaffEmploymentPeriod::query()
                ->where('staff_id', $staff->getKey())
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get();

            $covering = $periods->first(fn (StaffEmploymentPeriod $period): bool => $period->effective_from->lessThanOrEqualTo($start)
                && ($period->effective_to === null || $period->effective_to->greaterThan($start))
            );

            if ($covering !== null && $covering->employment_type_id === $type->getKey()) {
                return $covering;
            }

            if ($covering !== null && $covering->effective_from->isSameDay($start)) {
                $covering->update(['employment_type_id' => $type->getKey()]);
                $period = $covering;
            } else {
                if ($covering !== null) {
                    $covering->update(['effective_to' => $start->toDateString()]);
                }

                $next = $periods->first(fn (StaffEmploymentPeriod $item): bool => $item->effective_from->greaterThan($start));
                $period = StaffEmploymentPeriod::query()->create([
                    'staff_id' => $staff->getKey(),
                    'employment_type_id' => $type->getKey(),
                    'effective_from' => $start->toDateString(),
                    'effective_to' => $next?->effective_from->toDateString(),
                ]);
            }

            $this->auditLogger->log(
                'staff.employment_changed',
                $staff,
                sprintf('雇用区分を%sから「%s」に設定', $start->toDateString(), $type->name),
                $actor,
            );

            return $period->refresh();
        });
    }
}
