<?php

declare(strict_types=1);

namespace App\Enums\Membership;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Grace = 'grace';
    case Canceling = 'canceling';
    case Paused = 'paused';
    case Canceled = 'canceled';

    /**
     * 予約で利用権を消費できる状態か（当期 available >= 1 は別途チェック）。
     */
    public function isBookable(): bool
    {
        return match ($this) {
            self::Active, self::Grace, self::Canceling => true,
            self::Pending, self::Paused, self::Canceled => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Canceled;
    }
}
