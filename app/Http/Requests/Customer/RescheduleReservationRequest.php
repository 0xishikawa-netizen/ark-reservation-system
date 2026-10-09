<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesReservationInput;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RescheduleReservationRequest extends FormRequest
{
    use ValidatesReservationInput;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateStaff($validator);
            $this->validateBoundary($validator);
        });
    }

    private function validateStaff(Validator $validator): void
    {
        $reservation = $this->route('reservation');

        if (! $reservation instanceof Reservation) {
            return;
        }

        $this->validateStaffAssignable($validator, (int) $reservation->service_id, 'messages.reservation.staff_not_assigned_to_reserved');
    }
}
