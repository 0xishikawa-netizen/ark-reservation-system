<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->customer !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'membership_plan_id' => [
                'required',
                'integer',
                Rule::exists('membership_plans', 'id')->where(
                    fn (Builder $query): Builder => $query->where('is_active', true),
                ),
            ],
            'payment_method_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
