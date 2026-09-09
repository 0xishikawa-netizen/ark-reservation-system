<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum ReservationSource: string
{
    case Hotpepper = 'HOTPEPPER';
    case Epark = 'EPARK';
    case ArkWeb = 'ARK_WEB';
    case PeakManager = 'PEAK_MANAGER';
    case SalonBoard = 'SALON_BOARD';
    case External = 'EXTERNAL';
    case Admin = 'ADMIN';
}
