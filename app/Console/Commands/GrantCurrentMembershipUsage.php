<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipUsageTransaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class GrantCurrentMembershipUsage extends Command
{
    private const BATCH_SIZE = 200;

    /** @var string */
    protected $signature = 'memberships:grant-current {--dry-run}';

    /** @var string */
    protected $description = '利用可能な Membership に未付与の当期利用回数を付与する';

    public function __construct(private readonly MembershipLedgerService $ledger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $grantedCount = 0;
        $skippedCount = 0;

        $this->grantableMemberships()->chunkById(
            self::BATCH_SIZE,
            function (Collection $memberships) use ($dryRun, &$grantedCount, &$skippedCount): void {
                foreach ($memberships as $membership) {
                    $period = $membership->current_period_start->toDateString();
                    $dedupeKey = "grant:{$membership->id}:{$period}";

                    if (MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->exists()) {
                        $skippedCount++;

                        continue;
                    }

                    $count = (int) $membership->plan->usage_count_per_period;

                    if ($dryRun) {
                        $this->line("GRANT 予定 membership#{$membership->id} period={$period} +{$count}");
                        $grantedCount++;

                        continue;
                    }

                    $transaction = $this->ledger->grant(
                        $membership,
                        $period,
                        $count,
                        reason: 'period grant (scheduler)',
                    );

                    if ($transaction->wasRecentlyCreated) {
                        $grantedCount++;
                    } else {
                        // exists() 後に webhook が先行した場合も dedupe によりここへ収束する。
                        $skippedCount++;
                    }
                }
            },
        );

        $suffix = $dryRun ? '（dry-run: 変更なし）' : '';
        $this->info("付与 {$grantedCount} 件 / スキップ（既存）{$skippedCount} 件{$suffix}");

        return self::SUCCESS;
    }

    /** @return Builder<Membership> */
    private function grantableMemberships(): Builder
    {
        return Membership::query()
            ->with('plan')
            ->whereIn('status', [
                MembershipStatus::Active->value,
                MembershipStatus::Grace->value,
                MembershipStatus::Canceling->value,
            ])
            ->whereNotNull('current_period_start')
            ->whereNotNull('membership_plan_id');
    }
}
