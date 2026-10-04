<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Reservation;
use App\Models\Staff;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Throwable;

final class UpdateAdminReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'is_staff_requested' => ['nullable', 'boolean'],
            'staff_gender_preference' => ['nullable', 'string', 'in:male,female'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'version' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateNominationOrPreference($validator);
            $this->validateStaff($validator);
            $this->validateBooth($validator);
            $this->validateBoundary($validator);
        });
    }

    private function validateStaff(Validator $validator): void
    {
        $reservation = $this->route('reservation');
        $staffId = $this->input('staff_id');

        if (! $reservation instanceof Reservation) {
            return;
        }

        // メニューを変える場合は、変更後のメニューで担当の要否・担当可否を確かめる。
        $serviceId = $this->input('service_id') !== null ? (int) $this->input('service_id') : (int) $reservation->service_id;

        if (DB::table('services')->where('id', $serviceId)->value('requires_staff') && $staffId === null) {
            $validator->errors()->add('staff_id', __('messages.reservation.staff_required'));

            return;
        }

        if ($staffId === null || $validator->errors()->has('staff_id')) {
            return;
        }

        $staff = Staff::query()->find((int) $staffId);
        $assigned = DB::table('service_staff')
            ->where('service_id', $serviceId)
            ->where('staff_id', (int) $staffId)
            ->exists();

        if ($staff === null || ! $staff->is_bookable || ! $assigned) {
            $validator->errors()->add(
                'staff_id',
                __('messages.reservation.staff_not_assigned_to_reserved'),
            );
        }
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

    private function validateBoundary(Validator $validator): void
    {
        if ((bool) config('reservation.allow_admin_free_time', false)
            || $validator->errors()->has('starts_at')) {
            return;
        }

        try {
            $startsAt = CarbonImmutable::parse((string) $this->input('starts_at'));
        } catch (Throwable) {
            return;
        }

        if (! SlotKey::fromSettings()->isBoundary($startsAt)) {
            $validator->errors()->add(
                'starts_at',
                __('messages.reservation.non_boundary_start'),
            );
        }
    }
}
