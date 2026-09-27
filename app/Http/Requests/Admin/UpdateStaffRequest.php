<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_bookable' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'role' => ['nullable', 'string', Rule::in(['staff', 'manager', 'admin'])],
            'is_active' => ['sometimes', 'boolean'],
            // Task 11-28: 実施できる施術・保有資格。
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'qualification_ids' => ['sometimes', 'array'],
            'qualification_ids.*' => ['integer', 'distinct', 'exists:qualifications,id'],
        ];
    }
}
