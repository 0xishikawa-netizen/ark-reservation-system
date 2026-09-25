<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->lexify('method_????'), 'name' => fake()->word(), 'is_enabled' => true, 'display_order' => 0];
    }
}
