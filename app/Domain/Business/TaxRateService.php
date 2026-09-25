<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaxRateService
{
    /** 境界は半開区間 [effective_from, effective_to)。 */
    public const MAX_RATE_BPS = 10_000;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array{tax_category_id:int,rate_bps:int,effective_from:string,effective_to?:string|null} $data */
    public function save(array $data, ?TaxRate $rate, ?Authenticatable $actor): TaxRate
    {
        return DB::transaction(function () use ($data, $rate, $actor): TaxRate {
            TaxCategory::query()->whereKey($data['tax_category_id'])->lockForUpdate()->firstOrFail();

            $start = CarbonImmutable::parse($data['effective_from'])->startOfDay();
            $end = isset($data['effective_to']) && $data['effective_to'] !== null
                ? CarbonImmutable::parse($data['effective_to'])->startOfDay()
                : null;

            if ($end !== null && ! $end->greaterThan($start)) {
                throw ValidationException::withMessages([
                    'effective_to' => __('messages.business.tax_rate_end_after_start'),
                ]);
            }

            $overlaps = TaxRate::query()
                ->where('tax_category_id', $data['tax_category_id'])
                ->when($rate !== null, fn ($query) => $query->where('id', '!=', $rate->getKey()))
                ->when(
                    $end !== null,
                    fn ($query) => $query->where('effective_from', '<', $end->toDateString()),
                )
                ->where(function ($query) use ($start): void {
                    $query->whereNull('effective_to')
                        ->orWhere('effective_to', '>', $start->toDateString());
                })
                ->lockForUpdate()
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'effective_from' => __('messages.business.tax_rate_overlap'),
                ]);
            }

            $attributes = [
                'tax_category_id' => $data['tax_category_id'],
                'rate_bps' => $data['rate_bps'],
                'effective_from' => $start->toDateString(),
                'effective_to' => $end?->toDateString(),
            ];

            if ($rate === null) {
                $rate = TaxRate::query()->create($attributes);
                $action = 'tax_rate.created';
            } else {
                $rate = TaxRate::query()->whereKey($rate->getKey())->lockForUpdate()->firstOrFail();
                $rate->update($attributes);
                $action = 'tax_rate.updated';
            }

            $this->auditLogger->log(
                $action,
                $rate,
                sprintf('税率期間 %s（%d bp）を保存', $start->toDateString(), $data['rate_bps']),
                $actor,
            );

            return $rate->refresh();
        });
    }

    public function forDate(TaxCategory|int $category, CarbonImmutable|string $date): ?TaxRate
    {
        $categoryId = $category instanceof TaxCategory ? (int) $category->getKey() : $category;
        $businessDate = $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date);

        return TaxRate::query()
            ->where('tax_category_id', $categoryId)
            ->where('effective_from', '<=', $businessDate->toDateString())
            ->where(function ($query) use ($businessDate): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $businessDate->toDateString());
            })
            ->first();
    }
}
