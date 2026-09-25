<?php

declare(strict_types=1);

namespace App\Support\Business;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class BusinessTime
{
    public function timezone(): string
    {
        return (string) config('business.timezone', 'Asia/Tokyo');
    }

    public function businessDate(CarbonInterface|string|null $instant = null): CarbonImmutable
    {
        if ($instant instanceof CarbonInterface) {
            return CarbonImmutable::instance($instant)->setTimezone($this->timezone())->startOfDay();
        }

        if (is_string($instant)) {
            return CarbonImmutable::parse($instant)->setTimezone($this->timezone())->startOfDay();
        }

        return CarbonImmutable::now($this->timezone())->startOfDay();
    }
}
