<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RevokeTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.grant') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'operation_key' => ['required', 'uuid'],
        ];
    }
}
