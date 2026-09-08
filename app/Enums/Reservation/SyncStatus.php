<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum SyncStatus: string
{
    case Pending = 'PENDING';
    case Synced = 'SYNCED';
    case Failed = 'FAILED';
    case NotRequired = 'NOT_REQUIRED';
}
