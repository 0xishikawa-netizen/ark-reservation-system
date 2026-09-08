<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdjustMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('membership.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'operation_key' => ['required', 'uuid'],
        ];
    }
}
