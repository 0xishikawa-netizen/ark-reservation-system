<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Accounting\CheckoutStatus;
use App\Models\Checkout;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Checkout> */
class CheckoutFactory extends Factory
{
    protected $model = Checkout::class;

    public function definition(): array
    {
        return ['visit_id' => Visit::factory(), 'status' => CheckoutStatus::Draft, 'subtotal_amount' => 10000, 'tax_amount' => 1000, 'total_amount' => 11000, 'currency' => 'jpy'];
    }
}
