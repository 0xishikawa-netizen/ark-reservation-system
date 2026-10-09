<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Service;
use App\Models\Staff;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * 予約の作成・変更リクエストに共通する入力検証（Task 11-34 共通化）。
 * 会員予約・ゲスト予約・管理画面予約・予約変更の FormRequest が同じ規則を使う。
 * 最終判定（空き・勤務・重複）は ReservationService が行い、ここでは入力として明らかに不正なものだけを弾く。
 */
trait ValidatesReservationInput
{
    /** service_id が検証を通っていれば、そのメニューを返す。 */
    private function validatedService(Validator $validator): ?Service
    {
        if ($validator->errors()->has('service_id')) {
            return null;
        }

        return Service::query()->find((int) $this->input('service_id'));
    }

    /**
     * 開始時刻が予約スロットの境界かを検査する。
     * 管理画面で任意時刻予約を許可している時（$allowFreeTime）は検査しない。
     */
    private function validateBoundary(Validator $validator, bool $allowFreeTime = false): void
    {
        if ($allowFreeTime || $validator->errors()->has('starts_at')) {
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

    /**
     * 指定された担当が予約可能で、そのメニューの担当者かを検査する。担当未指定なら何もしない。
     *
     * @param  string  $messageKey  担当できない時のエラー文言キー（新規予約と予約変更で文言が違う）
     */
    private function validateStaffAssignable(Validator $validator, ?int $serviceId, string $messageKey): void
    {
        $staffId = $this->input('staff_id');

        if ($staffId === null || $validator->errors()->has('staff_id')) {
            return;
        }

        $staff = Staff::query()->find((int) $staffId);
        $assigned = $serviceId !== null && DB::table('service_staff')
            ->where('service_id', $serviceId)
            ->where('staff_id', (int) $staffId)
            ->exists();

        if ($staff === null || ! $staff->is_bookable || ! $assigned) {
            $validator->errors()->add('staff_id', __($messageKey));
        }
    }
}
