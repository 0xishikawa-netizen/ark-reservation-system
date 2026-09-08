<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Staff;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class DeactivateStaff
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Staff $staff,
        ?Authenticatable $actor = null,
    ): Staff {
        return DB::transaction(function () use ($staff, $actor): Staff {
            $staff->update(['is_bookable' => false]);

            $this->auditLogger->log(
                'staff.deactivated',
                $staff,
                "スタッフ「{$staff->display_name}」を予約受付不可に変更",
                $actor,
            );

            return $staff->refresh();
        });
    }
}
