<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Membership\MembershipStateMachine;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Enums\Membership\MembershipStatus;
use App\Models\Membership;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ExpireMembershipGrace extends Command
{
    private const BATCH_SIZE = 200;

    /** @var string */
    protected $signature = 'memberships:expire-grace {--dry-run}';

    /** @var string */
    protected $description = 'grace 期限超過後も Stripe が未回復の Membership を paused にする';

    public function __construct(
        private readonly MembershipSubscriptionService $subscriptions,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pausedCount = 0;
        $skippedCount = 0;

        $this->expiredGraceMemberships()->chunkById(
            self::BATCH_SIZE,
            function (Collection $memberships) use ($dryRun, &$pausedCount, &$skippedCount): void {
                foreach ($memberships as $membership) {
                    if ($dryRun) {
                        $this->line(
                            "paused 判定候補 membership#{$membership->id} grace_until={$membership->grace_until->toDateTimeString()}",
                        );
                        $pausedCount++;

                        continue;
                    }

                    // Stripe HTTP は DB transaction の外で行い、現在状態へ前進同期する。
                    $this->subscriptions->syncFromStripe($membership);
                    $membership->refresh();

                    if ($membership->status !== MembershipStatus::Grace) {
                        $skippedCount++;

                        continue;
                    }

                    $paused = DB::transaction(function () use ($membership): bool {
                        $locked = Membership::query()
                            ->whereKey($membership->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        // retrieve 後の webhook 回復や並行実行をロック下でも再確認する。
                        if ($locked->status !== MembershipStatus::Grace
                            || $locked->grace_until === null
                            || ! $locked->grace_until->lt(now())) {
                            return false;
                        }

                        (new MembershipStateMachine)->apply(
                            $locked,
                            'status',
                            MembershipStatus::Paused->value,
                        );
                        $locked->forceFill([
                            'grace_until' => null,
                            'last_synced_at' => now(),
                        ])->save();

                        $this->auditLogger->log(
                            'membership.paused',
                            $locked,
                            "grace 期限超過で paused membership#{$locked->id}",
                            null,
                        );

                        return true;
                    });

                    if ($paused) {
                        $pausedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            },
        );

        $suffix = $dryRun ? '（dry-run: 変更なし）' : '';
        $this->info("paused {$pausedCount} 件 / スキップ（回復・状態変更）{$skippedCount} 件{$suffix}");

        return self::SUCCESS;
    }

    /** @return Builder<Membership> */
    private function expiredGraceMemberships(): Builder
    {
        return Membership::query()
            ->where('status', MembershipStatus::Grace->value)
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', now())
            ->whereNotNull('stripe_subscription_id');
    }
}
