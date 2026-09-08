<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kana' => fake()->name(),
            'phone' => null,
            'birthday' => null,
            'gender' => null,
            'note' => null,
            'stripe_customer_id' => null,
            'created_via' => 'web',
        ];
    }
}
