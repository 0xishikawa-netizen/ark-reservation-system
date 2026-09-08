<?php

declare(strict_types=1);

namespace App\Enums\Membership;

enum MembershipReservationUsageStatus: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';
}
