<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'duration_min' => ['required', 'integer', 'min:5', 'max:600'],
            'price' => ['required', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_online_bookable' => ['sometimes', 'boolean'],
            'requires_staff' => ['sometimes', 'boolean'],
            'sort_order' => ['required', 'integer'],
            'staff_ids' => ['sometimes', 'array'],
            'staff_ids.*' => ['integer', 'distinct', 'exists:staff,user_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requiresStaff = $this->has('requires_staff')
                ? $this->boolean('requires_staff')
                : true;
            $staffIds = $this->input('staff_ids', []);

            if ($requiresStaff && (! is_array($staffIds) || $staffIds === [])) {
                $validator->errors()->add(
                    'staff_ids',
                    __('messages.service.staff_required'),
                );
            }
        });
    }
}
