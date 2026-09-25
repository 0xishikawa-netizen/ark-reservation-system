<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RevenueAllocation;
use App\Models\RevenueRecognitionContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RevenueAllocation> */
class RevenueAllocationFactory extends Factory
{
    protected $model = RevenueAllocation::class;

    public function definition(): array
    {
        return ['revenue_recognition_contract_id' => RevenueRecognitionContract::factory(), 'recognized_on' => '2026-09-24', 'amount' => 10000, 'allocation_no' => 1, 'is_remainder' => false, 'operation_key' => (string) Str::uuid()];
    }
}
