<?php

declare(strict_types=1);

namespace App\Enums\Reservation;

enum InflowChannel: string
{
    case Google = 'google';
    case Website = 'website';
    case Instagram = 'instagram';
    case Direct = 'direct';

    public static function fromUntrustedSource(?string $source): self
    {
        return match ($source) {
            self::Google->value => self::Google,
            self::Website->value => self::Website,
            self::Instagram->value => self::Instagram,
            default => self::Direct,
        };
    }
}
