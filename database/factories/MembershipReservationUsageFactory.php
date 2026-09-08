<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipReservationUsage> */
class MembershipReservationUsageFactory extends Factory
{
    protected $model = MembershipReservationUsage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'membership_id' => Membership::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'no_show_policy' => MembershipNoShowPolicy::Consume->value,
            'status' => MembershipReservationUsageStatus::Reserved->value,
            'reserved_at' => now(),
            'released_at' => null,
            'consumed_at' => null,
        ];
    }
}
