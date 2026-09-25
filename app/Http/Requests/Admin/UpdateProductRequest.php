<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Models\TaxCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'code' => [
                'nullable',
                'string',
                'max:64',
                'alpha_dash:ascii',
                Rule::unique('products', 'code')->ignore($product instanceof Product ? $product->id : null),
            ],
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0'],
            'tax_category_id' => ['nullable', 'integer', Rule::exists(TaxCategory::class, 'id')],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
        ];
    }
}
