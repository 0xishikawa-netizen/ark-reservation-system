<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Visit\VisitStatus;
use App\Models\Customer;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Visit> */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'business_date' => '2026-09-24', 'status' => VisitStatus::Draft];
    }
}
