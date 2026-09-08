<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Models\TicketWallet;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ExpireTickets extends Command
{
    private const BATCH_SIZE = 500;

    /** @var string */
    protected $signature = 'tickets:expire {--dry-run}';

    /** @var string */
    protected $description = '有効期限を過ぎた回数券の利用可能残数を失効する';

    public function __construct(
        private readonly TicketLedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = $this->expirableWallets();

        if ((bool) $this->option('dry-run')) {
            $count = 0;

            $query->chunkById(self::BATCH_SIZE, function (Collection $wallets) use (&$count): void {
                foreach ($wallets as $wallet) {
                    $this->line(sprintf(
                        'wallet#%d customer#%d available=%d',
                        $wallet->id,
                        $wallet->customer_id,
                        $this->ledger->available($wallet),
                    ));
                    $count++;
                }
            });

            $this->info(sprintf('失効対象回数券件数: %d（dry-run: 変更なし）', $count));

            return self::SUCCESS;
        }

        $count = 0;

        $query->chunkById(self::BATCH_SIZE, function (Collection $wallets) use (&$count): void {
            foreach ($wallets as $wallet) {
                $expired = DB::transaction(function () use ($wallet): bool {
                    $locked = TicketWallet::query()
                        ->whereKey($wallet->id)
                        ->whereDate('expires_at', '<', today())
                        ->where('status', '!=', TicketWalletStatus::Expired->value)
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null) {
                        return false;
                    }

                    $available = $this->ledger->available($locked);

                    if ($available > 0) {
                        $this->ledger->append(
                            wallet: $locked,
                            type: TicketTransactionType::Expire,
                            delta: -$available,
                            dedupeKey: "expire:{$locked->id}:".now()->format('Ym'),
                            reason: '有効期限切れ',
                        );
                    }

                    $locked->update(['status' => TicketWalletStatus::Expired]);

                    $this->auditLogger->log(
                        'ticket.expired',
                        $locked,
                        "回数券失効 wallet#{$locked->id} -{$available}",
                        null,
                    );

                    return true;
                });

                if ($expired) {
                    $count++;
                }
            }
        });

        $this->info(sprintf('失効した回数券件数: %d', $count));

        return self::SUCCESS;
    }

    /** @return Builder<TicketWallet> */
    private function expirableWallets(): Builder
    {
        return TicketWallet::query()
            ->whereDate('expires_at', '<', today())
            ->where('status', '!=', TicketWalletStatus::Expired->value);
    }
}
