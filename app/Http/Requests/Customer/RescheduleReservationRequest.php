<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Models\Reservation;
use App\Models\Staff;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Throwable;

class RescheduleReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateStaff($validator);
            $this->validateBoundary($validator);
        });
    }

    private function validateStaff(Validator $validator): void
    {
        if ($this->input('staff_id') === null || $validator->errors()->has('staff_id')) {
            return;
        }

        $reservation = $this->route('reservation');

        if (! $reservation instanceof Reservation) {
            return;
        }

        $staffId = (int) $this->input('staff_id');
        $staff = Staff::query()->find($staffId);
        $isAssigned = DB::table('service_staff')
            ->where('service_id', $reservation->service_id)
            ->where('staff_id', $staffId)
            ->exists();

        if ($staff === null || ! $staff->is_bookable || ! $isAssigned) {
            $validator->errors()->add(
                'staff_id',
                'このスタッフは予約サービスを担当できません。',
            );
        }
    }

    private function validateBoundary(Validator $validator): void
    {
        if ($validator->errors()->has('starts_at')) {
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
                '開始時刻を予約枠の境界に合わせてください。',
            );
        }
    }
}
