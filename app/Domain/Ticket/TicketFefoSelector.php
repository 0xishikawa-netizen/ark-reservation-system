<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Models\TicketWallet;

final class TicketFefoSelector
{
    public function __construct(private readonly TicketLedgerService $ledger) {}

    public function selectForHold(int $customerId): ?TicketWallet
    {
        $wallets = TicketWallet::query()
            ->where('customer_id', $customerId)
            ->active()
            ->fefo()
            ->lockForUpdate()
            ->get();

        foreach ($wallets as $wallet) {
            if ($this->ledger->available($wallet) >= 1) {
                return $wallet;
            }
        }

        return null;
    }
}
