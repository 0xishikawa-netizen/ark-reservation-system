<?php

declare(strict_types=1);

namespace App\Enums\Ticket;

enum TicketReservationUsageStatus: string
{
    case Held = 'held';
    case Released = 'released';
    case Consumed = 'consumed';
}
