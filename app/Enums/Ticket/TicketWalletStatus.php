<?php

declare(strict_types=1);

namespace App\Enums\Ticket;

enum TicketWalletStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
}
