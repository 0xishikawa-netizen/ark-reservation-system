<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketWalletStatus;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileTickets extends Command
{
    private const BATCH_SIZE = 500;

    /** @var string */
    protected $signature = 'tickets:reconcile
        {--wallet= : 対象 wallet id（省略で全件）}
        {--repair : 派生キャッシュを再計算して修復}
        {--dry-run : --repair 時に書き込まない}';

    /** @var string */
    protected $description = '回数券台帳（正）と ticket_wallets キャッシュ／状態の整合を検査する（通常は read-only）';

    public function __construct(
        private readonly TicketLedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repair = (bool) $this->option('repair');
        $dryRun = (bool) $this->option('dry-run');
        $discrepancyCount = 0;
        $heldWalletCount = 0;
        $repairedWalletCount = 0;

        $walletCount = $this->forEachWallet(function (TicketWallet $wallet) use (
            $repair,
            $dryRun,
            &$discrepancyCount,
            &$heldWalletCount,
            &$repairedWalletCount,
        ): void {
            $inspection = $this->inspect($wallet);
            $discrepancyCount += count($inspection['discrepancies']);

            if ($inspection['held'] > 0) {
                $heldWalletCount++;
            }

            foreach ($inspection['discrepancies'] as $discrepancy) {
                $this->warn($discrepancy);
            }

            if (! $repair) {
                return;
            }

            if ($dryRun) {
                $this->outputRepairPlan($wallet, $inspection['available']);

                return;
            }

            if ($this->repair($wallet)) {
                $repairedWalletCount++;
            }
        });

        if (! $repair || $dryRun) {
            if ($dryRun) {
                $this->info('dry-run: 修復内容は書き込んでいません。');
            }

            $this->outputSummary($discrepancyCount, $walletCount, $heldWalletCount);

            return $discrepancyCount > 0 ? self::FAILURE : self::SUCCESS;
        }

        $residualCount = 0;
        $heldWalletCount = 0;
        $walletCount = $this->forEachWallet(function (TicketWallet $wallet) use (
            &$residualCount,
            &$heldWalletCount,
        ): void {
            $inspection = $this->inspect($wallet);
            $residualCount += count($inspection['discrepancies']);

            if ($inspection['held'] > 0) {
                $heldWalletCount++;
            }

            foreach ($inspection['discrepancies'] as $discrepancy) {
                $this->warn("残差分: {$discrepancy}");
            }
        });

        $this->info(sprintf('修復した wallet: %d 件', $repairedWalletCount));
        $this->outputSummary($residualCount, $walletCount, $heldWalletCount);

        return $residualCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{available: int, held: int, discrepancies: list<string>}
     */
    private function inspect(TicketWallet $wallet): array
    {
        $available = $this->ledger->available($wallet);
        $held = $this->ledger->held($wallet);
        $usageHeld = TicketReservationUsage::query()
            ->where('ticket_wallet_id', $wallet->id)
            ->where('status', TicketReservationUsageStatus::Held->value)
            ->count();
        $discrepancies = [];

        if ($available !== (int) $wallet->balance) {
            $discrepancies[] = $this->discrepancy(
                'balance キャッシュ不一致',
                $wallet,
                $available,
                (int) $wallet->balance,
            );
        }

        if ($available < 0) {
            $discrepancies[] = $this->discrepancy(
                '負の available',
                $wallet,
                '0以上',
                $available,
            );
        }

        if ($held !== $usageHeld) {
            $discrepancies[] = $this->discrepancy(
                'held 不整合',
                $wallet,
                $held,
                $usageHeld,
            );
        }

        if ($held < 0) {
            $discrepancies[] = $this->discrepancy(
                '負の held',
                $wallet,
                '0以上',
                $held,
            );
        }

        if ($wallet->status === TicketWalletStatus::Exhausted && $available > 0) {
            $discrepancies[] = $this->discrepancy(
                'status 不整合',
                $wallet,
                TicketWalletStatus::Active->value,
                $wallet->status->value,
            );
        }

        if ($wallet->status === TicketWalletStatus::Active && $available <= 0) {
            $discrepancies[] = $this->discrepancy(
                'status 不整合',
                $wallet,
                TicketWalletStatus::Exhausted->value,
                $wallet->status->value,
            );
        }

        if ($wallet->status === TicketWalletStatus::Expired && $available > 0) {
            $discrepancies[] = $this->discrepancy(
                'expired wallet の available 未ゼロ化',
                $wallet,
                0,
                $available,
            );
        }

        if ($wallet->status !== TicketWalletStatus::Expired && $wallet->expires_at->lt(today())) {
            $discrepancies[] = $this->discrepancy(
                'expire 未処理',
                $wallet,
                TicketWalletStatus::Expired->value,
                $wallet->status->value,
            );
        }

        return [
            'available' => $available,
            'held' => $held,
            'discrepancies' => $discrepancies,
        ];
    }

    private function repair(TicketWallet $wallet): bool
    {
        return DB::transaction(function () use ($wallet): bool {
            $locked = TicketWallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();
            $available = $this->ledger->available($locked);
            $status = $this->expectedStatus($locked, $available);
            $oldBalance = (int) $locked->balance;
            $oldStatus = $locked->status;

            $locked->fill([
                'balance' => $available,
                'status' => $status,
            ]);

            if (! $locked->isDirty()) {
                return false;
            }

            $locked->save();

            $this->auditLogger->log(
                'ticket.reconcile.repaired',
                $locked,
                "reconcile 修復 wallet#{$locked->id} balance:{$oldBalance}->{$available} status:{$oldStatus->value}->{$status->value}",
                null,
            );

            return true;
        });
    }

    private function outputRepairPlan(TicketWallet $wallet, int $available): void
    {
        $status = $this->expectedStatus($wallet, $available);

        if ((int) $wallet->balance === $available && $wallet->status === $status) {
            return;
        }

        $this->line(sprintf(
            '修復予定 wallet#%d customer#%d balance:%d->%d status:%s->%s',
            $wallet->id,
            $wallet->customer_id,
            $wallet->balance,
            $available,
            $wallet->status->value,
            $status->value,
        ));
    }

    private function expectedStatus(TicketWallet $wallet, int $available): TicketWalletStatus
    {
        if ($wallet->expires_at->lt(today())) {
            return TicketWalletStatus::Expired;
        }

        return $available > 0
            ? TicketWalletStatus::Active
            : TicketWalletStatus::Exhausted;
    }

    private function discrepancy(
        string $type,
        TicketWallet $wallet,
        int|string $expected,
        int|string $actual,
    ): string {
        return sprintf(
            '%s wallet#%d customer#%d 期待値=%s 実値=%s',
            $type,
            $wallet->id,
            $wallet->customer_id,
            $expected,
            $actual,
        );
    }

    /** @param callable(TicketWallet): void $callback */
    private function forEachWallet(callable $callback): int
    {
        $walletId = $this->option('wallet');

        if ($walletId !== null) {
            $callback(TicketWallet::query()->findOrFail($walletId));

            return 1;
        }

        $count = 0;

        TicketWallet::query()
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function (Collection $wallets) use ($callback, &$count): void {
                foreach ($wallets as $wallet) {
                    $callback($wallet);
                    $count++;
                }
            });

        return $count;
    }

    private function outputSummary(int $discrepancyCount, int $walletCount, int $heldWalletCount): void
    {
        $this->info(sprintf('未解消 HOLD のある wallet: %d 件', $heldWalletCount));
        $this->info(sprintf('検出した差分: %d 件（対象 wallet %d 件）', $discrepancyCount, $walletCount));
    }
}
