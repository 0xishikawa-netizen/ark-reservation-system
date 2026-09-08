<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Service;
use App\Models\Staff;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

final class StoreAdminReservationRequest extends FormRequest
{
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
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $service = $this->validatedService($validator);
            $this->validateStaff($validator, $service);
            $this->validateBooth($validator);
            $this->validateBoundary($validator);
        });
    }

    private function validatedService(Validator $validator): ?Service
    {
        if ($validator->errors()->has('service_id')) {
            return null;
        }

        return Service::query()->find((int) $this->input('service_id'));
    }

    private function validateStaff(Validator $validator, ?Service $service): void
    {
        $staffId = $this->input('staff_id');

        if ($service?->requires_staff && $staffId === null) {
            $validator->errors()->add('staff_id', 'このサービスには担当スタッフが必要です。');

            return;
        }

        if ($staffId === null || $validator->errors()->has('staff_id')) {
            return;
        }

        $staff = Staff::query()->find((int) $staffId);
        $assigned = $service !== null && DB::table('service_staff')
            ->where('service_id', $service->id)
            ->where('staff_id', (int) $staffId)
            ->exists();

        if ($staff === null || ! $staff->is_bookable || ! $assigned) {
            $validator->errors()->add(
                'staff_id',
                'このスタッフは選択したサービスを担当できません。',
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
