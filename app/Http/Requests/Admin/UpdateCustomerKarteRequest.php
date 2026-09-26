<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Geography\Prefectures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomerKarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customers.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acquisition_channel_id' => ['nullable', 'integer', 'exists:acquisition_channels,id'],
            'acquisition_note' => ['nullable', 'string', 'max:100'],
            'visit_purpose_ids' => ['nullable', 'array', 'max:20'],
            'visit_purpose_ids.*' => ['integer', 'distinct', 'exists:visit_purposes,id'],
            'visit_purpose_note' => ['nullable', 'string', 'max:255'],
            'referrer_customer_id' => ['nullable', 'integer', 'exists:customers,user_id'],
            'referrer_name' => ['nullable', 'string', 'max:100'],
            'prefecture' => ['nullable', 'string', Rule::in(Prefectures::ALL)],
            // 分析用は市区町村まで。番地・建物名は入力させない（数字で始まる番地らしい値を拒否）。
            'city' => ['nullable', 'string', 'max:50', 'not_regex:/[0-9０-９]/u'],
        ];
    }
}
