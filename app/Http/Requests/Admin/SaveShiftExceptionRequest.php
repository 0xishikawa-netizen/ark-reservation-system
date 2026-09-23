<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveShiftExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.manage') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'integer', 'exists:staff,user_id'],
            'exception_date' => ['required', 'date_format:Y-m-d'],
            'is_off' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
