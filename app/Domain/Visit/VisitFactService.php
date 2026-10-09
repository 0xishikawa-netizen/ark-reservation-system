<?php

declare(strict_types=1);

namespace App\Domain\Visit;

use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VisitFactService
{
    public function __construct(
        private readonly BusinessTime $businessTime,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createDraft(
        Customer $customer,
        ?Reservation $reservation,
        array $attributes = [],
        ?Authenticatable $actor = null,
    ): Visit {
        $startedAt = $attributes['started_at'] ?? null;

        return DB::transaction(function () use ($customer, $reservation, $attributes, $startedAt, $actor): Visit {
            if ($reservation !== null && (int) $reservation->customer_id !== (int) $customer->getKey()) {
                throw ValidationException::withMessages(['reservation_id' => __('messages.visit.reservation_customer_mismatch')]);
            }

            $derivedBusinessDate = $this->businessTime->businessDate($startedAt)->toDateString();
            if ($startedAt !== null && isset($attributes['business_date']) && $attributes['business_date'] !== $derivedBusinessDate) {
                throw ValidationException::withMessages(['business_date' => __('messages.visit.business_date_mismatch')]);
            }

            $visit = Visit::query()->create([
                ...$attributes,
                'customer_id' => $customer->getKey(),
                'reservation_id' => $reservation?->getKey(),
                'business_date' => $startedAt === null ? ($attributes['business_date'] ?? $derivedBusinessDate) : $derivedBusinessDate,
                'status' => VisitStatus::Draft,
            ]);
            $this->audit->log('visit.created', $visit, '来店実績の下書きを作成', $actor);

            return $visit;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function addTreatment(Visit $visit, ?Service $service, array $attributes = [], ?Authenticatable $actor = null): VisitTreatment
    {
        return DB::transaction(function () use ($visit, $service, $attributes, $actor): VisitTreatment {
            $lockedVisit = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedVisit->status !== VisitStatus::Draft) {
                throw ValidationException::withMessages(['visit' => __('messages.visit.completed_treatment_locked')]);
            }

            $service?->loadMissing('analysisCategory');
            $values = [
                ...$attributes,
                'visit_id' => $lockedVisit->getKey(),
                'service_id' => $service?->getKey(),
                'analysis_category_id' => $service?->analysis_category_id,
                'service_name_snapshot' => $attributes['service_name_snapshot'] ?? $service?->name,
                'analysis_category_code_snapshot' => $attributes['analysis_category_code_snapshot'] ?? $service?->analysisCategory?->code,
                'analysis_category_name_snapshot' => $attributes['analysis_category_name_snapshot'] ?? $service?->analysisCategory?->name,
                'status' => VisitTreatmentStatus::Draft,
            ];
            $operationKey = $attributes['operation_key'] ?? null;
            $treatment = is_string($operationKey) && $operationKey !== ''
                ? VisitTreatment::query()->firstOrCreate(['operation_key' => $operationKey], $values)
                : VisitTreatment::query()->create($values);
            if ((int) $treatment->visit_id !== (int) $lockedVisit->getKey()) {
                throw ValidationException::withMessages(['operation_key' => __('messages.visit.operation_key_conflict')]);
            }
            if ($treatment->wasRecentlyCreated) {
                $this->audit->log('visit_treatment.created', $treatment, '施術実績を追加', $actor);
            }

            return $treatment;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function assignStaff(VisitTreatment $treatment, Staff $staff, array $attributes, ?Authenticatable $actor = null): VisitTreatmentStaff
    {
        return DB::transaction(function () use ($treatment, $staff, $attributes, $actor): VisitTreatmentStaff {
            $locked = VisitTreatment::query()->whereKey($treatment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== VisitTreatmentStatus::Draft) {
                throw ValidationException::withMessages(['treatment' => __('messages.visit.completed_staff_locked')]);
            }
            if (! isset($attributes['actual_minutes']) || (int) $attributes['actual_minutes'] < 1) {
                throw ValidationException::withMessages(['actual_minutes' => __('messages.visit.actual_minutes_positive')]);
            }

            $assignment = VisitTreatmentStaff::query()->updateOrCreate(
                ['visit_treatment_id' => $locked->getKey(), 'staff_id' => $staff->getKey()],
                [...$attributes, 'staff_name_snapshot' => $attributes['staff_name_snapshot'] ?? $staff->display_name],
            );
            $this->audit->log('visit_treatment.staff_recorded', $assignment, '実担当時間を記録', $actor);

            return $assignment;
        });
    }

    public function completeTreatment(VisitTreatment $treatment, ?Authenticatable $actor = null): VisitTreatment
    {
        return DB::transaction(function () use ($treatment, $actor): VisitTreatment {
            $locked = VisitTreatment::query()->whereKey($treatment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === VisitTreatmentStatus::Completed) {
                return $locked;
            }
            if ($locked->status !== VisitTreatmentStatus::Draft || $locked->actual_minutes === null) {
                throw ValidationException::withMessages(['treatment' => __('messages.visit.actual_minutes_required')]);
            }

            $assignments = VisitTreatmentStaff::query()
                ->where('visit_treatment_id', $locked->getKey())->lockForUpdate()->get();
            $requiresStaff = $locked->service_id === null
                || (bool) $locked->service()->value('requires_staff');
            $staffMinutes = (int) $assignments->sum('actual_minutes');
            if (($assignments->isEmpty() && $requiresStaff)
                || ($assignments->isNotEmpty() && $staffMinutes !== (int) $locked->actual_minutes)) {
                throw ValidationException::withMessages(['actual_minutes' => __('messages.visit.staff_minutes_mismatch')]);
            }

            $locked->forceFill(['status' => VisitTreatmentStatus::Completed])->save();
            $this->audit->log('visit_treatment.completed', $locked, '施術実績を確定', $actor);

            return $locked->refresh();
        });
    }
}
