<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Accounting\CheckoutLineItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 来店・会計入力の保存内容。業務上の整合（合計・税・担当時間）はCheckoutEntryServiceで検証する。 */
final class SaveCheckoutEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkouts.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $time = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        return [
            'primary_staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'nominated_staff_ids' => ['nullable', 'array', 'max:20'],
            'nominated_staff_ids.*' => ['integer', 'distinct', 'exists:staff,user_id'],
            'treatments' => ['nullable', 'array', 'max:20'],
            'treatments.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'treatments.*.actual_minutes' => ['required', 'integer', 'min:1', 'max:720'],
            'treatments.*.started_at' => $time,
            'treatments.*.staff' => ['nullable', 'array', 'max:10'],
            'treatments.*.staff.*.staff_id' => ['required', 'integer', 'exists:staff,user_id'],
            'treatments.*.staff.*.actual_minutes' => ['required', 'integer', 'min:1', 'max:720'],
            'treatments.*.staff.*.started_at' => $time,
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.item_type' => ['required', Rule::enum(CheckoutLineItemType::class)],
            'lines.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.ticket_product_id' => ['nullable', 'integer', 'exists:ticket_products,id'],
            'lines.*.membership_plan_id' => ['nullable', 'integer', 'exists:membership_plans,id'],
            'lines.*.item_name' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lines.*.tax_category_id' => ['nullable', 'integer', 'exists:tax_categories,id'],
            'lines.*.treatment_index' => ['nullable', 'integer', 'min:0'],
            'lines.*.is_staff_allocatable' => ['boolean'],
            'lines.*.allocations' => ['nullable', 'array', 'max:10'],
            'lines.*.allocations.*.staff_id' => ['required', 'integer', 'exists:staff,user_id'],
            'lines.*.allocations.*.amount' => ['required', 'integer', 'min:0', 'max:10000000'],
            'tenders' => ['nullable', 'array', 'max:10'],
            'tenders.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'tenders.*.amount' => ['required', 'integer', 'min:1', 'max:10000000'],
        ];
    }
}
