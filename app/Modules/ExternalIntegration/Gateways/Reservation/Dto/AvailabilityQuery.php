<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation\Dto;

use Carbon\CarbonImmutable;

final readonly class AvailabilityQuery
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $staffId = null,
        public ?int $serviceId = null,
    ) {}
}
