<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->where('is_online_bookable', true)
                        ->where('requires_staff', true),
                ),
            ],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
