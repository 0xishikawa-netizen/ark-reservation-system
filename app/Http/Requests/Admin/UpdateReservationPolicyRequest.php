<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateReservationPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.min_hours_before' => ['required', 'integer', 'min:0', 'distinct'],
            'tiers.*.refund_percent' => ['required', 'integer', 'between:0,100'],
            'no_show_refund_percent' => ['required', 'integer', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tiers = $this->input('tiers');

            if (! is_array($tiers)) {
                return;
            }

            $hasZeroHourTier = collect($tiers)->contains(
                static fn (mixed $tier): bool => is_array($tier)
                    && filter_var(
                        $tier['min_hours_before'] ?? null,
                        FILTER_VALIDATE_INT,
                    ) !== false
                    && (int) $tier['min_hours_before'] === 0,
            );

            if (! $hasZeroHourTier) {
                $validator->errors()->add(
                    'tiers',
                    '開始時刻まで0時間の段階を必ず含めてください。',
                );
            }

            $encoded = json_encode($tiers);

            if (is_string($encoded) && strlen($encoded) > 255) {
                $validator->errors()->add(
                    'tiers',
                    '返金段階の設定量が上限を超えています。',
                );
            }
        });
    }
}
