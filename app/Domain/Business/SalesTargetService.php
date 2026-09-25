<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Models\MonthlySalesTarget;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class SalesTargetService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function defaultAmount(): ?int
    {
        $value = $this->settings->get((string) config('business.sales_target_default_key'));

        return $value === null ? null : (int) $value;
    }

    public function setDefaultAmount(int $amount, ?Authenticatable $actor): void
    {
        $this->settings->set((string) config('business.sales_target_default_key'), $amount, 'int');
        $this->auditLogger->log('sales_target.default_updated', null, "店舗既定売上目標を {$amount} 円に更新", $actor);
    }

    public function setMonthly(string $month, int $amount, ?Authenticatable $actor): MonthlySalesTarget
    {
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth()->toDateString();

        return DB::transaction(function () use ($monthStart, $amount, $actor): MonthlySalesTarget {
            $target = MonthlySalesTarget::query()
                ->where('target_month', $monthStart)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                $target = MonthlySalesTarget::query()->create([
                    'target_month' => $monthStart,
                    'target_amount' => $amount,
                    'updated_by' => $actor?->getAuthIdentifier(),
                ]);
            } else {
                $target->update([
                    'target_amount' => $amount,
                    'updated_by' => $actor?->getAuthIdentifier(),
                ]);
            }

            $this->auditLogger->log(
                'sales_target.monthly_updated',
                $target,
                "月別売上目標 {$monthStart} を {$amount} 円に更新",
                $actor,
            );

            return $target->refresh();
        });
    }

    public function clearMonthly(MonthlySalesTarget $target, ?Authenticatable $actor): void
    {
        DB::transaction(function () use ($target, $actor): void {
            $locked = MonthlySalesTarget::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $this->auditLogger->log('sales_target.monthly_cleared', $locked, '月別売上目標を解除', $actor);
            $locked->delete();
        });
    }

    public function forMonth(CarbonImmutable|string $month): ?int
    {
        $monthStart = ($month instanceof CarbonImmutable ? $month : CarbonImmutable::parse($month))
            ->startOfMonth()
            ->toDateString();
        $override = MonthlySalesTarget::query()->where('target_month', $monthStart)->value('target_amount');

        return $override === null ? $this->defaultAmount() : (int) $override;
    }
}
