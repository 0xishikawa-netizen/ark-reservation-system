<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('membership.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0'],
            'usage_count_per_period' => ['required', 'integer', 'min:1', 'max:999'],
            'billing_interval' => ['required', 'string', Rule::in(['month'])],
            'stripe_price_id' => ['required', 'string', 'starts_with:price_', 'max:40'],
            'sort_order' => ['required', 'integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
