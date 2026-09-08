<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TicketProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketProduct> */
class TicketProductFactory extends Factory
{
    protected $model = TicketProduct::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $count = fake()->numberBetween(5, 20);

        return [
            'name' => sprintf('ARK %d回券', $count),
            'total_count' => $count,
            'price' => fake()->numberBetween(10_000, 80_000),
            'validity_days' => fake()->randomElement([90, 180]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
