<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation\Dto;

use Carbon\CarbonImmutable;

final readonly class ReservationSnapshot
{
    public function __construct(
        public ?int $localReservationId,
        public string $customerName,
        public string $serviceName,
        public ?string $staffName,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $source,
    ) {}
}
