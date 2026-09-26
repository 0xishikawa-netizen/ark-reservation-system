<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Models\EmploymentType;
use App\Models\PaymentMethod;
use App\Models\ServiceAnalysisCategory;
use App\Models\TaxCategory;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class BusinessMasterService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function createAnalysisCategory(array $data, ?Authenticatable $actor): ServiceAnalysisCategory
    {
        return $this->create(ServiceAnalysisCategory::class, $data, 'service_analysis_category', $actor);
    }

    /** @param array<string, mixed> $data */
    public function updateAnalysisCategory(ServiceAnalysisCategory $category, array $data, ?Authenticatable $actor): ServiceAnalysisCategory
    {
        return $this->update($category, $data, 'service_analysis_category', $actor);
    }

    /**
     * 顧客カルテの選択肢マスタ（来店動機・来店目的。Task 11-21）。
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function createKarteMaster(string $modelClass, array $data, ?Authenticatable $actor): Model
    {
        return $this->create($modelClass, $data, 'karte_master', $actor);
    }

    /** @param array<string, mixed> $data */
    public function updateKarteMaster(Model $model, array $data, ?Authenticatable $actor): Model
    {
        return $this->update($model, $data, 'karte_master', $actor);
    }

    /** @param array<string, mixed> $data */
    public function createTaxCategory(array $data, ?Authenticatable $actor): TaxCategory
    {
        return $this->create(TaxCategory::class, $data, 'tax_category', $actor);
    }

    /** @param array<string, mixed> $data */
    public function updateTaxCategory(TaxCategory $category, array $data, ?Authenticatable $actor): TaxCategory
    {
        return $this->update($category, $data, 'tax_category', $actor);
    }

    /** @param array<string, mixed> $data */
    public function createPaymentMethod(array $data, ?Authenticatable $actor): PaymentMethod
    {
        return $this->create(PaymentMethod::class, $data, 'payment_method_master', $actor);
    }

    /** @param array<string, mixed> $data */
    public function updatePaymentMethod(PaymentMethod $method, array $data, ?Authenticatable $actor): PaymentMethod
    {
        return $this->update($method, $data, 'payment_method_master', $actor);
    }

    /** @param array<string, mixed> $data */
    public function createEmploymentType(array $data, ?Authenticatable $actor): EmploymentType
    {
        return $this->create(EmploymentType::class, $data, 'employment_type', $actor);
    }

    /** @param array<string, mixed> $data */
    public function updateEmploymentType(EmploymentType $type, array $data, ?Authenticatable $actor): EmploymentType
    {
        return $this->update($type, $data, 'employment_type', $actor);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    private function create(string $modelClass, array $data, string $auditPrefix, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($modelClass, $data, $auditPrefix, $actor): Model {
            $model = $modelClass::query()->create($data);
            $this->auditLogger->log(
                "{$auditPrefix}.created",
                $model,
                sprintf('%s「%s」を作成', $auditPrefix, (string) $model->getAttribute('name')),
                $actor,
            );

            return $model;
        });
    }

    /** @param array<string, mixed> $data */
    private function update(Model $model, array $data, string $auditPrefix, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($model, $data, $auditPrefix, $actor): Model {
            $locked = $model::query()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $this->auditLogger->log(
                "{$auditPrefix}.updated",
                $locked,
                sprintf('%s「%s」を更新', $auditPrefix, (string) $locked->getAttribute('name')),
                $actor,
            );

            return $locked->refresh();
        });
    }
}
