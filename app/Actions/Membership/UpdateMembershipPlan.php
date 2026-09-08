<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Models\MembershipPlan;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateMembershipPlan
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(
        MembershipPlan $plan,
        array $data,
        ?Authenticatable $actor = null,
    ): MembershipPlan {
        return DB::transaction(function () use ($plan, $data, $actor): MembershipPlan {
            $plan->update(Arr::only($data, [
                'name',
                'price',
                'usage_count_per_period',
                'billing_interval',
                'stripe_price_id',
                'is_active',
                'sort_order',
            ]));

            $this->auditLogger->log(
                'membership_plan.updated',
                $plan,
                "会員プラン「{$plan->name}」を更新",
                $actor,
            );

            return $plan->refresh();
        });
    }
}
