<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum PaymentMethod: string
{
    case Single = 'single';
    case Membership = 'membership';
    case Ticket = 'ticket';
    case Onsite = 'onsite';
    case Unpaid = 'unpaid';
}
