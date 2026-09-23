<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

final class AvailabilityStatusClassifier
{
    /** 3枠（週次では3名）以上を「○」、1〜2を「△」として案内する。 */
    private const OPEN_THRESHOLD = 3;

    public static function classify(int $availableCount): string
    {
        return match (true) {
            $availableCount >= self::OPEN_THRESHOLD => 'open',
            $availableCount > 0 => 'some',
            default => 'full',
        };
    }
}
