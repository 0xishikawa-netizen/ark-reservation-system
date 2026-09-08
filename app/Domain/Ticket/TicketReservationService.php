<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Models\Reservation;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class TicketReservationService
{
    public function __construct(
        private readonly TicketLedgerService $ledger,
        private readonly TicketFefoSelector $fefo,
        private readonly TicketPolicyResolver $policy,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function hold(
        Reservation $reservation,
        ?Authenticatable $actor = null,
    ): TicketReservationUsage
    {
        $existing = $this->usageFor($reservation);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($reservation, $actor): TicketReservationUsage {
            $wallet = $this->fefo->selectForHold((int) $reservation->customer_id);

            if ($wallet === null) {
                throw new InsufficientTicketBalanceException('利用可能な回数券がありません');
            }

            $this->ledger->append(
                wallet: $wallet,
                type: TicketTransactionType::ReserveHold,
                delta: -1,
                dedupeKey: "resv:{$reservation->id}:RESERVE_HOLD",
                reservationId: (int) $reservation->id,
            );

            $usage = TicketReservationUsage::query()->create([
                'reservation_id' => $reservation->id,
                'ticket_wallet_id' => $wallet->id,
                'no_show_policy' => $this->policy->noShowPolicy(),
                'status' => TicketReservationUsageStatus::Held,
                'held_at' => now(),
            ]);

            $this->auditLogger->log(
                'ticket.held',
                $usage,
                "回数券 HOLD 予約#{$reservation->id} wallet#{$wallet->id}",
                $actor,
            );

            return $usage;
        });
    }

    public function release(
        Reservation $reservation,
        ?Authenticatable $actor = null,
    ): void
    {
        $usage = $this->usageFor($reservation);

        if ($usage === null || $usage->status !== TicketReservationUsageStatus::Held) {
            return;
        }

        DB::transaction(function () use ($reservation, $usage, $actor): void {
            $wallet = TicketWallet::query()
                ->whereKey($usage->ticket_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ledger->append(
                wallet: $wallet,
                type: TicketTransactionType::ReserveRelease,
                delta: 1,
                dedupeKey: "resv:{$reservation->id}:RESERVE_RELEASE",
                reservationId: (int) $reservation->id,
            );

            if ($wallet->expires_at->isBefore(today())) {
                $this->ledger->append(
                    wallet: $wallet,
                    type: TicketTransactionType::Expire,
                    delta: -1,
                    dedupeKey: "expire:{$wallet->id}:resv:{$reservation->id}",
                    reason: '期限切れ回数券の解放分を相殺',
                );
            }

            $usage->update([
                'status' => TicketReservationUsageStatus::Released,
                'released_at' => now(),
            ]);

            $this->auditLogger->log(
                'ticket.released',
                $usage,
                "回数券 RELEASE 予約#{$reservation->id} wallet#{$wallet->id}",
                $actor,
            );
        });
    }

    public function consume(
        Reservation $reservation,
        ?Authenticatable $actor = null,
    ): void
    {
        $usage = $this->usageFor($reservation);

        if ($usage === null || in_array($usage->status, [
            TicketReservationUsageStatus::Released,
            TicketReservationUsageStatus::Consumed,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($reservation, $usage, $actor): void {
            $wallet = TicketWallet::query()
                ->whereKey($usage->ticket_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ledger->append(
                wallet: $wallet,
                type: TicketTransactionType::ReserveRelease,
                delta: 1,
                dedupeKey: "resv:{$reservation->id}:RESERVE_RELEASE",
                reservationId: (int) $reservation->id,
            );
            $this->ledger->append(
                wallet: $wallet,
                type: TicketTransactionType::Consume,
                delta: -1,
                dedupeKey: "resv:{$reservation->id}:CONSUME",
                reservationId: (int) $reservation->id,
            );

            $usage->update([
                'status' => TicketReservationUsageStatus::Consumed,
                'consumed_at' => now(),
                'released_at' => $usage->released_at ?? now(),
            ]);

            $this->auditLogger->log(
                'ticket.consumed',
                $usage,
                "回数券 CONSUME 予約#{$reservation->id} wallet#{$wallet->id}",
                $actor,
            );
        });
    }

    public function handleNoShow(
        Reservation $reservation,
        ?Authenticatable $actor = null,
    ): void
    {
        $usage = $this->usageFor($reservation);

        if ($usage === null) {
            return;
        }

        match ($usage->no_show_policy) {
            TicketNoShowPolicy::Restore => $this->release($reservation, $actor),
            TicketNoShowPolicy::Consume => $this->consume($reservation, $actor),
        };
    }

    private function usageFor(Reservation $reservation): ?TicketReservationUsage
    {
        return TicketReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->first();
    }
}
