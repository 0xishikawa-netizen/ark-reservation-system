<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\Visit;
use App\Models\VisitTreatment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisitTreatment> */
class VisitTreatmentFactory extends Factory
{
    protected $model = VisitTreatment::class;

    public function definition(): array
    {
        return [
            'visit_id' => Visit::factory(), 'service_name_snapshot' => fake()->word(),
            'status' => VisitTreatmentStatus::Draft, 'actual_minutes' => 60, 'sort_order' => 0,
        ];
    }
}
