<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket_products.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'total_count' => ['required', 'integer', 'min:1', 'max:999'],
            'price' => ['required', 'integer', 'min:0'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
