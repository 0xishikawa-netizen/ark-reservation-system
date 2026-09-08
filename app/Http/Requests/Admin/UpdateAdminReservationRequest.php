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
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'version' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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

        if ($reservation->service()->value('requires_staff') && $staffId === null) {
            $validator->errors()->add('staff_id', 'このサービスには担当スタッフが必要です。');

            return;
        }

        if ($staffId === null || $validator->errors()->has('staff_id')) {
            return;
        }

        $staff = Staff::query()->find((int) $staffId);
        $assigned = DB::table('service_staff')
            ->where('service_id', $reservation->service_id)
            ->where('staff_id', (int) $staffId)
            ->exists();

        if ($staff === null || ! $staff->is_bookable || ! $assigned) {
            $validator->errors()->add(
                'staff_id',
                'このスタッフは予約サービスを担当できません。',
            );
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
            $validator->errors()->add('booth_id', 'このブースは現在利用できません。');
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
                '開始時刻を予約枠の境界に合わせてください。',
            );
        }
    }
}
