<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipPlan> */
class MembershipPlanFactory extends Factory
{
    protected $model = MembershipPlan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $count = fake()->randomElement([2, 4, 8]);

        return [
            'name' => sprintf('月 %d 回プラン', $count),
            'price' => $count * 6000,
            'usage_count_per_period' => $count,
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
