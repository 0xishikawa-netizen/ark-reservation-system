<?php

declare(strict_types=1);

namespace App\Enums\Ticket;

enum TicketTransactionType: string
{
    case Purchase = 'PURCHASE';
    case ReserveHold = 'RESERVE_HOLD';
    case ReserveRelease = 'RESERVE_RELEASE';
    case Consume = 'CONSUME';
    case Grant = 'GRANT';
    case Revoke = 'REVOKE';
    case Expire = 'EXPIRE';
    case Adjust = 'ADJUST';

    public function requiresReservation(): bool
    {
        return match ($this) {
            self::ReserveHold,
            self::ReserveRelease,
            self::Consume => true,
            default => false,
        };
    }
}
