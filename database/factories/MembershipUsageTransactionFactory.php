<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Membership\MembershipUsageType;
use App\Models\Membership;
use App\Models\MembershipUsageTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipUsageTransaction> */
class MembershipUsageTransactionFactory extends Factory
{
    protected $model = MembershipUsageTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'type' => MembershipUsageType::Grant,
            'delta' => 4,
            'reservation_id' => null,
            'staff_id' => null,
            'reason' => null,
            'dedupe_key' => 'factory:'.fake()->unique()->uuid(),
            'created_at' => now(),
        ];
    }
}
