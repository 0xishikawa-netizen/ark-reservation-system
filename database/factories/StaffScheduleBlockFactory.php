<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Schedule\ScheduleBlockType;
use App\Models\Staff;
use App\Models\StaffScheduleBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffScheduleBlock> */
class StaffScheduleBlockFactory extends Factory
{
    protected $model = StaffScheduleBlock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'booth_id' => null,
            'work_date' => now()->toDateString(),
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
            'type' => ScheduleBlockType::Break,
            'title' => null,
            'note' => null,
            'created_by' => null,
        ];
    }
}
