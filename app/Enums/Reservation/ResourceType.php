<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum ResourceType: string
{
    case Staff = 'staff';
    case Booth = 'booth';
}
