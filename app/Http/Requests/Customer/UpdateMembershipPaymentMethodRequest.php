<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMembershipPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->customer !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'max:255'],
        ];
    }
}
