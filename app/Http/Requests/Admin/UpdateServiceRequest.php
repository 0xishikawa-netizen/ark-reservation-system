<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Service;
use App\Models\ServiceAnalysisCategory;
use App\Models\TaxCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateServiceRequest extends FormRequest
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
            'analysis_category_id' => ['nullable', 'integer', Rule::exists(ServiceAnalysisCategory::class, 'id')],
            'tax_category_id' => ['nullable', 'integer', Rule::exists(TaxCategory::class, 'id')],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_online_bookable' => ['sometimes', 'boolean'],
            'requires_staff' => ['sometimes', 'boolean'],
            'sort_order' => ['required', 'integer'],
            'staff_ids' => ['sometimes', 'array'],
            'staff_ids.*' => ['integer', 'distinct', 'exists:staff,user_id'],
            // Task 11-28: メニューで使える具体ブース（空＝全有効ブース）と必要資格。
            'booth_ids' => ['sometimes', 'array'],
            'booth_ids.*' => ['integer', 'distinct', 'exists:booths,id'],
            'qualification_ids' => ['sometimes', 'array'],
            'qualification_ids.*' => ['integer', 'distinct', 'exists:qualifications,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $service = $this->route('service');
            $currentRequiresStaff = $service instanceof Service
                ? $service->requires_staff
                : true;
            $requiresStaff = $this->has('requires_staff')
                ? $this->boolean('requires_staff')
                : $currentRequiresStaff;
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
