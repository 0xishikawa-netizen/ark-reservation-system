<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CheckoutLine;
use App\Models\Staff;
use App\Models\StaffRevenueAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffRevenueAllocation> */
class StaffRevenueAllocationFactory extends Factory
{
    protected $model = StaffRevenueAllocation::class;

    public function definition(): array
    {
        return ['checkout_line_id' => CheckoutLine::factory(), 'staff_id' => Staff::factory(), 'staff_name_snapshot' => fake()->name(), 'basis_minutes' => 60, 'allocated_amount' => 11000];
    }
}
