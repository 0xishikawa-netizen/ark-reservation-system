<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Ticket\TicketLedgerService;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;

class CustomerTicketQuery
{
    /**
     * @return list<array{
     *     id: int,
     *     product_name: string,
     *     purchased_count: int,
     *     available: int,
     *     held: int,
     *     total: int,
     *     balance_cache: int,
     *     expires_at: string,
     *     status: string
     * }>
     */
    public function walletsFor(int $customerId): array
    {
        $ledger = app(TicketLedgerService::class);

        return TicketWallet::query()
            ->select([
                'id',
                'customer_id',
                'ticket_product_id',
                'purchased_count',
                'balance',
                'expires_at',
                'status',
                'created_at',
            ])
            ->with('product:id,name')
            ->where('customer_id', $customerId)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->map(function (TicketWallet $wallet) use ($ledger): array {
                $summary = $ledger->summary($wallet);

                return [
                    'id' => (int) $wallet->id,
                    'product_name' => $wallet->product->name,
                    'purchased_count' => (int) $wallet->purchased_count,
                    'available' => $summary['available'],
                    'held' => $summary['held'],
                    'total' => $summary['total'],
                    'balance_cache' => (int) $wallet->balance,
                    'expires_at' => $wallet->expires_at->toDateString(),
                    'status' => $wallet->status->value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     id: int,
     *     wallet_id: int,
     *     type: string,
     *     delta: int,
     *     reservation_id: ?int,
     *     reason: ?string,
     *     created_at: string
     * }>
     */
    public function historyFor(int $customerId, int $limit = 100): array
    {
        return TicketTransaction::query()
            ->select([
                'id',
                'ticket_wallet_id',
                'type',
                'delta',
                'reservation_id',
                'reason',
                'created_at',
            ])
            ->whereHas('wallet', fn ($query) => $query->where('customer_id', $customerId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (TicketTransaction $transaction): array => [
                'id' => (int) $transaction->id,
                'wallet_id' => (int) $transaction->ticket_wallet_id,
                'type' => $transaction->type->value,
                'delta' => (int) $transaction->delta,
                'reservation_id' => $transaction->reservation_id === null
                    ? null
                    : (int) $transaction->reservation_id,
                'reason' => $transaction->reason,
                'created_at' => $transaction->created_at->toDateTimeString(),
            ])
            ->values()
            ->all();
    }
}
