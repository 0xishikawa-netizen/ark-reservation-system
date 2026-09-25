<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Checkout;
use App\Models\CheckoutLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckoutLine> */
class CheckoutLineFactory extends Factory
{
    protected $model = CheckoutLine::class;

    public function definition(): array
    {
        return [
            'checkout_id' => Checkout::factory(), 'item_type' => 'service', 'item_name_snapshot' => fake()->word(),
            'quantity' => 1, 'unit_amount' => 11000, 'net_amount' => 10000, 'tax_amount' => 1000,
            'gross_amount' => 11000, 'is_staff_allocatable' => true, 'sort_order' => 0,
        ];
    }
}
