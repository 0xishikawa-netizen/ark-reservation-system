<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Enums\Reservation\ReservationSource;
use Carbon\CarbonImmutable;

final readonly class ReservationInput
{
    public function __construct(
        public int $customerId,
        public int $serviceId,
        public ?int $staffId,
        public ?int $boothId,
        public CarbonImmutable $startsAt,
        public ReservationSource $source,
        public ?int $actorUserId,
        public ?string $notes,
        public bool $adminContext,
    ) {}
}
