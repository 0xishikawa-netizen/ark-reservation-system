<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $customer = $this->route('customer');

        return $customer instanceof Customer && $user->can('update', $customer);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
