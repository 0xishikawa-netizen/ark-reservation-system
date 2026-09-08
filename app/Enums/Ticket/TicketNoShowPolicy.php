<?php

declare(strict_types=1);

namespace App\Enums\Ticket;

enum TicketNoShowPolicy: string
{
    case Restore = 'restore';
    case Consume = 'consume';
}
