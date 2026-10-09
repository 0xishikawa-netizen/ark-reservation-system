<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Rules\JapanesePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyGuestLookupCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', new JapanesePhoneNumber],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('messages.otp.invalid_phone'),
            'code.digits' => __('messages.otp.six_digits'),
        ];
    }
}
