<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum ReservationStatus: string
{
    case PendingPayment = 'pending_payment';
    case PendingExternalSync = 'pending_external_sync';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::NoShow,
            self::Canceled,
            self::Expired => true,
            default => false,
        };
    }
}
