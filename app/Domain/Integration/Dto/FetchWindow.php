<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

use Carbon\CarbonImmutable;

final readonly class FetchWindow
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}
}
