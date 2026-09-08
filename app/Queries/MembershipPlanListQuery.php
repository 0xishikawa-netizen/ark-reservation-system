<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Membership\MembershipStatus;
use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Collection;

class MembershipPlanListQuery
{
    /** @return Collection<int, MembershipPlan> */
    public function get(): Collection
    {
        return MembershipPlan::query()
            ->select([
                'id',
                'name',
                'price',
                'usage_count_per_period',
                'billing_interval',
                'stripe_price_id',
                'is_active',
                'sort_order',
            ])
            ->withCount([
                'memberships as active_memberships_count' => fn ($query) => $query
                    ->where('status', '!=', MembershipStatus::Canceled->value),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
