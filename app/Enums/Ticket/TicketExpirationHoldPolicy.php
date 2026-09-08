<?php

declare(strict_types=1);

namespace App\Enums\Ticket;

enum TicketExpirationHoldPolicy: string
{
    case PreserveHold = 'preserve_hold';
}
