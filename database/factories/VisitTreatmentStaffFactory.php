<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Staff;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisitTreatmentStaff> */
class VisitTreatmentStaffFactory extends Factory
{
    protected $model = VisitTreatmentStaff::class;

    public function definition(): array
    {
        return ['visit_treatment_id' => VisitTreatment::factory(), 'staff_id' => Staff::factory(), 'staff_name_snapshot' => fake()->name(), 'actual_minutes' => 60];
    }
}
