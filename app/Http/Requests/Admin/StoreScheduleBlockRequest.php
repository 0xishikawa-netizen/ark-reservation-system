<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Schedule\ScheduleBlockType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScheduleBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'staff_id' => ['nullable', 'required_without:booth_id', 'integer', 'exists:staff,user_id'],
            'booth_id' => ['nullable', 'required_without:staff_id', 'integer', 'exists:booths,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_at' => ['required', 'date_format:H:i'],
            'end_at' => ['required', 'date_format:H:i', 'after:start_at'],
            'type' => ['required', Rule::enum(ScheduleBlockType::class)],
            'title' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
