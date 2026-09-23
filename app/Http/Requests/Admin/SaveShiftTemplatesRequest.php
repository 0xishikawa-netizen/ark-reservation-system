<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveShiftTemplatesRequest extends FormRequest
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
            'entries' => ['present', 'array', 'max:35'],
            'entries.*.weekday' => ['required', 'integer', 'between:0,6'],
            'entries.*.start_at' => ['required', 'date_format:H:i'],
            'entries.*.end_at' => ['required', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $entries = $this->input('entries');

            if (! is_array($entries)) {
                return;
            }

            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $start = $entry['start_at'] ?? null;
                $end = $entry['end_at'] ?? null;

                if (is_string($start) && is_string($end) && $start >= $end) {
                    $validator->errors()->add(
                        "entries.{$index}.end_at",
                        __('messages.common.end_after_start'),
                    );
                }
            }
        });
    }
}
