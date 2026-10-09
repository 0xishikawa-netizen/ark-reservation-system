<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesReservationInput;
use App\Models\Service;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreAdminReservationRequest extends FormRequest
{
    use ValidatesReservationInput;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,user_id'],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(
                    fn (Builder $query): Builder => $query->where('is_active', true),
                ),
            ],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'is_staff_requested' => ['nullable', 'boolean'],
            'staff_gender_preference' => ['nullable', 'string', 'in:male,female'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'starts_at' => ['required', 'date'],
            // 施術後の着替え・片付け用の余白（分）。台帳から選べる値だけに限定する。
            'buffer_min' => ['nullable', 'integer', Rule::in([0, 5, 10, 15])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateNominationOrPreference($validator);
            $service = $this->validatedService($validator);
            $this->validateStaff($validator, $service);
            $this->validateBooth($validator);
            $this->validateBoundary($validator, (bool) config('reservation.allow_admin_free_time', false));
        });
    }

    private function validateStaff(Validator $validator, ?Service $service): void
    {
        if ($service?->requires_staff && $this->input('staff_id') === null) {
            $validator->errors()->add('staff_id', __('messages.reservation.staff_required'));

            return;
        }

        $this->validateStaffAssignable($validator, $service?->id, 'messages.reservation.staff_not_assigned_to_selected');
    }

    /** 指名と性別希望は同時に付けられない（指名は特定のスタッフ、希望は性別で、意味が重なるため）。 */
    private function validateNominationOrPreference(Validator $validator): void
    {
        if ($this->boolean('is_staff_requested') && $this->filled('staff_gender_preference')) {
            $validator->errors()->add('staff_gender_preference', __('messages.reservation.nomination_and_preference_exclusive'));
        }
    }

    private function validateBooth(Validator $validator): void
    {
        if ($this->input('booth_id') === null || $validator->errors()->has('booth_id')) {
            return;
        }

        $active = DB::table('booths')
            ->where('id', (int) $this->input('booth_id'))
            ->where('is_active', true)
            ->exists();

        if (! $active) {
            $validator->errors()->add('booth_id', __('messages.reservation.booth_unavailable'));
        }
    }
}
