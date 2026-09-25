<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PRD-####'),
            'name' => fake()->words(2, true),
            'price' => fake()->numberBetween(100, 50_000),
            'tax_category_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
