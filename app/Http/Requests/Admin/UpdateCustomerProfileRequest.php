<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $customer = $this->route('customer');

        if (! $customer instanceof Customer) {
            $customer = $user->customer;
        }

        return $customer instanceof Customer && $user->can('update', $customer);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'kana' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
