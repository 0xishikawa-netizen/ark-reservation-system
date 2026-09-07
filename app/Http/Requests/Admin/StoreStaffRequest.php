<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'display_name' => ['required', 'string', 'max:50'],
            'role' => ['required', 'string', Rule::in(['staff', 'manager'])],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_bookable' => ['sometimes', 'boolean'],
        ];
    }
}
