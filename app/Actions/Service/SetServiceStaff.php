<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Service;
use App\Models\Staff;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetServiceStaff
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<int>  $staffIds */
    public function execute(
        Service $service,
        array $staffIds,
        ?Authenticatable $actor = null,
    ): Service {
        $staffIds = array_values(array_unique($staffIds));

        return DB::transaction(function () use ($service, $staffIds, $actor): Service {
            $existingCount = Staff::query()
                ->whereIn('user_id', $staffIds)
                ->count();

            if ($existingCount !== count($staffIds)) {
                throw ValidationException::withMessages([
                    'staff_ids' => [__('messages.staff.not_found_in_list')],
                ]);
            }

            $service->staff()->sync($staffIds);

            $this->auditLogger->log(
                'service.staff_set',
                $service,
                "サービス「{$service->name}」の施術可能スタッフを更新",
                $actor,
            );

            return $service->load('staff');
        });
    }
}
