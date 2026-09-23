<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Models\MembershipPlan;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateMembershipPlan
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, ?Authenticatable $actor = null): MembershipPlan
    {
        return DB::transaction(function () use ($data, $actor): MembershipPlan {
            $plan = MembershipPlan::query()->create(Arr::only($data, [
                'name',
                'price',
                'usage_count_per_period',
                'billing_interval',
                'stripe_price_id',
                'is_active',
                'sort_order',
            ]));

            $this->auditLogger->log(
                'membership_plan.created',
                $plan,
                "月額プラン「{$plan->name}」を作成",
                $actor,
            );

            return $plan;
        });
    }
}
