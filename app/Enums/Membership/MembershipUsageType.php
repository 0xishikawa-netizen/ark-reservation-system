<?php

declare(strict_types=1);

namespace App\Enums\Membership;

enum MembershipUsageType: string
{
    case Grant = 'GRANT';
    case Reserve = 'RESERVE';
    case Release = 'RELEASE';
    case Consume = 'CONSUME';
    case Adjust = 'ADJUST';

    public function requiresReservation(): bool
    {
        return match ($this) {
            self::Reserve, self::Release, self::Consume => true,
            default => false,
        };
    }
}
