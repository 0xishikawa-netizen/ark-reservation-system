<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Membership\Gateway\MembershipStripeGateway;
use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipStateMachine;
use App\Domain\Membership\MembershipStatusMapper;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Enums\Payment\PaymentKind;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Payment;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membership の業務状態・台帳・派生 cache と Stripe の現在状態を突合する。
 *
 * 既定は read-only。`--repair` は派生 cache のみ、`--sync` は Stripe retrieve による
 * ローカル状態の前進同期だけを許可し、Stripe や追記専用台帳は変更しない。
 */
final class ReconcileMemberships extends Command
{
    private const BATCH_SIZE = 200;

    /** @var string */
    protected $signature = 'memberships:reconcile
        {--membership= : 対象 membership id（省略で全件）}
        {--repair : 安全な派生 cache/state のみ修復}
        {--sync : Stripe を retrieve して現在状態へ前進同期（Stripe 読み取りのみ・mutate しない）}
        {--dry-run : --repair/--sync の書き込みを行わない}';

    /** @var string */
    protected $description = 'Membership の業務状態・台帳・cache と Stripe subscription の整合を検査する（既定 read-only）';

    private readonly MembershipStateMachine $stateMachine;

    public function __construct(
        private readonly MembershipLedgerService $ledger,
        private readonly MembershipSubscriptionService $subscriptions,
        private readonly MembershipStripeGateway $gateway,
        private readonly MembershipStatusMapper $mapper,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();

        $this->stateMachine = new MembershipStateMachine;
    }

    public function handle(): int
    {
        $repair = (bool) $this->option('repair');
        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');
        $discrepancyCount = 0;
        $needsAttentionCount = 0;
        $repairedCount = 0;
        $syncedCount = 0;

        $membershipCount = $this->forEachMembership(function (Membership $membership) use (
            $repair,
            $sync,
            $dryRun,
            &$discrepancyCount,
            &$needsAttentionCount,
            &$repairedCount,
            &$syncedCount,
        ): void {
            $inspection = $this->inspect($membership);
            $discrepancyCount += count($inspection['discrepancies']);

            if ($inspection['needs_attention']) {
                $needsAttentionCount++;
            }

            foreach ($inspection['discrepancies'] as $discrepancy) {
                $this->warn($discrepancy);
            }

            if ($sync && $membership->stripe_subscription_id !== null) {
                if ($dryRun) {
                    $this->line($this->identity($membership).' sync 予定');
                } else {
                    try {
                        // syncFromStripe 自身が retrieve し、Stripe を変更せずローカルだけを前進同期する。
                        $this->subscriptions->syncFromStripe($membership);
                        $membership->refresh();
                        $syncedCount++;
                    } catch (PaymentGatewayException $exception) {
                        $this->warn($this->identity($membership).' sync 失敗 種別='.$exception::class);
                    }
                }
            }

            if (! $repair) {
                return;
            }

            if ($dryRun) {
                if ($inspection['available'] !== (int) $membership->period_available) {
                    $this->line(sprintf(
                        '%s 修復予定 period_available:%d->%d',
                        $this->identity($membership),
                        $membership->period_available,
                        $inspection['available'],
                    ));
                }

                return;
            }

            if ($this->repairPeriodAvailable($membership)) {
                $repairedCount++;
            }
        });

        if (! ($repair || $sync) || $dryRun) {
            if ($dryRun) {
                $this->info('dry-run: sync／修復内容は書き込んでいません。');
            }

            $this->outputSummary($discrepancyCount, $membershipCount, $needsAttentionCount);

            return $discrepancyCount > 0 ? self::FAILURE : self::SUCCESS;
        }

        $residualCount = 0;
        $needsAttentionCount = 0;
        $membershipCount = $this->forEachMembership(function (Membership $membership) use (
            &$residualCount,
            &$needsAttentionCount,
        ): void {
            $inspection = $this->inspect($membership);
            $residualCount += count($inspection['discrepancies']);

            if ($inspection['needs_attention']) {
                $needsAttentionCount++;
            }

            foreach ($inspection['discrepancies'] as $discrepancy) {
                $this->warn("残差分: {$discrepancy}");
            }
        });

        if ($sync) {
            $this->info(sprintf('同期した membership: %d 件', $syncedCount));
        }

        if ($repair) {
            $this->info(sprintf('修復した membership: %d 件', $repairedCount));
        }

        $this->outputSummary($residualCount, $membershipCount, $needsAttentionCount);

        return $residualCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{available: int, needs_attention: bool, discrepancies: list<string>}
     */
    private function inspect(Membership $membership): array
    {
        $available = $this->ledger->available($membership);
        $held = $this->ledger->held($membership);
        $period = $this->ledger->currentPeriod($membership);
        $usageHeld = MembershipReservationUsage::query()
            ->where('membership_id', $membership->id)
            ->where('period_start', $period)
            ->where('status', MembershipReservationUsageStatus::Reserved->value)
            ->count();
        $grantCount = MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('period_start', $period)
            ->where('type', MembershipUsageType::Grant->value)
            ->count();
        $discrepancies = [];

        if ($available !== (int) $membership->period_available) {
            $discrepancies[] = $this->discrepancy(
                $membership,
                'period_available cache 不一致',
                "ledger={$available} cache={$membership->period_available}",
            );
        }

        if ($available < 0) {
            $discrepancies[] = $this->discrepancy($membership, '負の available', "available={$available}");
        }

        if ($held < 0) {
            $discrepancies[] = $this->discrepancy($membership, '負の held', "held={$held}");
        }

        if ($held !== $usageHeld) {
            $discrepancies[] = $this->discrepancy(
                $membership,
                'held 不整合',
                "ledger={$held} reserved_usage={$usageHeld}",
            );
        }

        if ($grantCount > 1) {
            $discrepancies[] = $this->discrepancy(
                $membership,
                '当期 GRANT 重複',
                "period={$period} count={$grantCount}",
            );
        }

        if ($membership->status === MembershipStatus::Grace && $membership->grace_until === null) {
            $discrepancies[] = $this->discrepancy($membership, 'grace 整合不一致', 'grace_until=null');
        }

        if ($membership->status === MembershipStatus::Grace
            && $membership->grace_until !== null
            && $membership->grace_until->lt(now())) {
            $discrepancies[] = $this->discrepancy(
                $membership,
                'grace 期限超過滞留',
                'memberships:expire-grace の対象',
            );
        }

        if ($membership->status === MembershipStatus::Pending
            && $membership->created_at->lt(now()->subDay())
            && ($membership->pending_operation !== null || $membership->needs_attention)) {
            $discrepancies[] = $this->discrepancy(
                $membership,
                'pending 滞留',
                'subscription create の曖昧結果が未収束',
            );
        }

        if ($membership->needs_attention) {
            $discrepancies[] = $this->discrepancy($membership, 'needs_attention 未解決', 'needs_attention=true');
        }

        if ($membership->stripe_subscription_id !== null) {
            try {
                // status / cancel / period / invoice の全比較で、この1回の retrieve 結果を共有する。
                $result = $this->gateway->retrieveSubscription((string) $membership->stripe_subscription_id);
                $target = $this->mapper->targetFor($result->stripeStatus, $result->cancelAtPeriodEnd);
                $current = $membership->status->value;

                if ($target !== $membership->status
                    && $this->stateMachine->pathTo($current, $target->value) !== []) {
                    $discrepancies[] = $this->discrepancy(
                        $membership,
                        'status ドリフト（Stripe が先行）',
                        "local={$current} stripe_target={$target->value}",
                    );
                }

                if ((bool) $membership->cancel_at_period_end !== $result->cancelAtPeriodEnd) {
                    $local = $membership->cancel_at_period_end ? 'true' : 'false';
                    $stripe = $result->cancelAtPeriodEnd ? 'true' : 'false';
                    $discrepancies[] = $this->discrepancy(
                        $membership,
                        'cancel_at_period_end 不一致',
                        "local={$local} stripe={$stripe}",
                    );
                }

                $localStart = $membership->current_period_start?->toDateString();
                if ($localStart !== $result->currentPeriodStart) {
                    $discrepancies[] = $this->discrepancy(
                        $membership,
                        'current_period_start 不一致',
                        'local='.($localStart ?? 'null').' stripe='.($result->currentPeriodStart ?? 'null'),
                    );
                }

                $localEnd = $membership->current_period_end?->toDateString();
                if ($localEnd !== $result->currentPeriodEnd) {
                    $discrepancies[] = $this->discrepancy(
                        $membership,
                        'current_period_end 不一致',
                        'local='.($localEnd ?? 'null').' stripe='.($result->currentPeriodEnd ?? 'null'),
                    );
                }

                if ($result->latestInvoiceStatus === 'paid' && $grantCount === 0) {
                    $discrepancies[] = $this->discrepancy(
                        $membership,
                        'invoice 済みだが当期 GRANT 未付与',
                        "period={$period}",
                    );
                }
            } catch (PaymentGatewayException $exception) {
                $discrepancies[] = $this->discrepancy(
                    $membership,
                    'Stripe retrieve 失敗',
                    '種別='.$exception::class,
                );
            }
        }

        $invalidPaymentCount = Payment::query()
            ->where('kind', PaymentKind::MembershipInvoice->value)
            ->where('customer_id', $membership->customer_id)
            ->where('payment_operation_id', 'not like', 'inv:%')
            ->count();

        if ($invalidPaymentCount > 0) {
            $discrepancies[] = $this->discrepancy(
                $membership,
                'membership invoice payment key 違反',
                "inv: 非プレフィックス={$invalidPaymentCount}件",
            );
        }

        return [
            'available' => $available,
            'needs_attention' => (bool) $membership->needs_attention,
            'discrepancies' => $discrepancies,
        ];
    }

    private function repairPeriodAvailable(Membership $membership): bool
    {
        return DB::transaction(function () use ($membership): bool {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $available = $this->ledger->available($locked);
            $oldAvailable = (int) $locked->period_available;

            if ($oldAvailable === $available) {
                return false;
            }

            $newAvailable = $this->ledger->recalculatePeriodAvailable($locked);
            $locked->refresh();
            $locked->forceFill(['last_synced_at' => now()])->save();

            $this->auditLogger->log(
                'membership.reconciled',
                $locked,
                "reconcile 修復 membership#{$locked->id} period_available:{$oldAvailable}->{$newAvailable}",
                null,
            );

            return true;
        });
    }

    private function discrepancy(Membership $membership, string $type, string $detail): string
    {
        return sprintf('%s %s %s', $type, $this->identity($membership), $detail);
    }

    private function identity(Membership $membership): string
    {
        return "membership#{$membership->id} customer#{$membership->customer_id}";
    }

    /** @param callable(Membership): void $callback */
    private function forEachMembership(callable $callback): int
    {
        $membershipId = $this->option('membership');

        if ($membershipId !== null && $membershipId !== '') {
            $callback(Membership::query()->findOrFail($membershipId));

            return 1;
        }

        $count = 0;

        Membership::query()
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function (Collection $memberships) use ($callback, &$count): void {
                foreach ($memberships as $membership) {
                    $callback($membership);
                    $count++;
                }
            });

        return $count;
    }

    private function outputSummary(int $discrepancyCount, int $membershipCount, int $needsAttentionCount): void
    {
        $this->info(sprintf(
            '検出した差分: %d 件（対象 membership %d 件 / needs_attention %d 件）',
            $discrepancyCount,
            $membershipCount,
            $needsAttentionCount,
        ));
    }
}
