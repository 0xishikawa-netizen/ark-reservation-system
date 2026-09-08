<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Models\MembershipPlan;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class ToggleMembershipPlanActive
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        MembershipPlan $plan,
        bool $active,
        ?Authenticatable $actor = null,
    ): MembershipPlan {
        return DB::transaction(function () use ($plan, $active, $actor): MembershipPlan {
            $plan->update(['is_active' => $active]);

            $this->auditLogger->log(
                $active ? 'membership_plan.activated' : 'membership_plan.deactivated',
                $plan,
                sprintf('会員プラン「%s」を%s', $plan->name, $active ? '有効化' : '無効化'),
                $actor,
            );

            return $plan->refresh();
        });
    }
}
