<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Reservation\BookingWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.manage') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'horizon_mode' => ['required', Rule::in([
                BookingWindow::MODE_NONE,
                BookingWindow::MODE_MONTHLY,
                BookingWindow::MODE_ROLLING,
            ])],
            'horizon_days' => ['required', 'integer', 'between:1,365'],
            'release_day_of_month' => ['required', 'integer', 'between:1,28'],
            'min_lead_minutes' => ['required', 'integer', 'between:0,10080'],
            'closed_dates' => ['present', 'array', 'max:120'],
            'closed_dates.*' => ['date_format:Y-m-d'],
        ];
    }
}
