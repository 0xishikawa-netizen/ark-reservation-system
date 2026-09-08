<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GrantTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.grant') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'ticket_product_id' => ['required', 'integer', 'exists:ticket_products,id'],
            'count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'reason' => ['required', 'string', 'max:255'],
            'operation_key' => ['required', 'uuid'],
        ];
    }
}
