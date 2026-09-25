<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\TaxCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:64', 'alpha_dash:ascii', 'unique:products,code'],
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0'],
            'tax_category_id' => [
                'nullable',
                'integer',
                Rule::exists(TaxCategory::class, 'id')->where('is_active', true),
            ],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
        ];
    }
}
