<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booth;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Booth> */
class BoothFactory extends Factory
{
    protected $model = Booth::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'ブース '.fake()->unique()->numberBetween(1, 9999),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
