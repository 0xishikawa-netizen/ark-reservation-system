<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Http\Requests\Concerns\ValidatesReservationInput;
use App\Models\Service;
use App\Models\User;
use App\Rules\JapanesePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreGuestReservationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', new JapanesePhoneNumber],
            'email' => ['nullable', 'string', 'email', 'max:190', Rule::unique(User::class)],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'payment_method' => ['required', 'string', Rule::in(['single', 'onsite'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => __('messages.auth.email_already_registered'),
            'phone.regex' => __('messages.otp.invalid_phone'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $service = $this->validatedService($validator);

            if ($service !== null && (! $service->is_active
                || ! $service->is_online_bookable
                || ! $service->requires_staff)) {
                $validator->errors()->add(
                    'service_id',
                    __('messages.reservation.service_not_online_target'),
                );
            }

            $this->validateStaff($validator, $service);
            $this->validateBoundary($validator);
        });
    }

    private function validateStaff(Validator $validator, ?Service $service): void
    {
        $this->validateStaffAssignable($validator, $service?->id, 'messages.reservation.staff_not_assigned_to_selected');
    }
}
