<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Ticket\TicketPolicyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTicketPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket_policy.manage') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'no_show_policy' => [
                'required',
                'string',
                Rule::in(TicketPolicyResolver::ALLOWED_NO_SHOW),
            ],
            'expiration_hold_policy' => [
                'required',
                'string',
                Rule::in(TicketPolicyResolver::ALLOWED_EXPIRATION_HOLD),
            ],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
