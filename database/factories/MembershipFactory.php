<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Membership\MembershipStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'membership_plan_id' => MembershipPlan::factory(),
            'stripe_subscription_id' => 'sub_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'membership_operation_id' => (string) Str::uuid(),
            'pending_operation' => null,
            'status' => MembershipStatus::Active->value,
            'current_period_start' => now()->startOfMonth()->toDateString(),
            'current_period_end' => now()->startOfMonth()->addMonth()->toDateString(),
            'cancel_at_period_end' => false,
            'grace_until' => null,
            'period_available' => 0,
            'started_at' => now(),
            'canceled_at' => null,
            'last_synced_at' => now(),
            'needs_attention' => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Pending->value,
            'stripe_subscription_id' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'started_at' => null,
            'pending_operation' => 'create',
        ]);
    }

    public function grace(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Grace->value,
            'grace_until' => now()->addDays(7),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Paused->value]);
    }

    public function canceling(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Canceling->value,
            'cancel_at_period_end' => true,
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Canceled->value,
            'canceled_at' => now(),
        ]);
    }
}
