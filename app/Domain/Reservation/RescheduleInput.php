<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use Carbon\CarbonImmutable;

final readonly class RescheduleInput
{
    public function __construct(
        public int $reservationId,
        public ?int $staffId,
        public ?int $boothId,
        public CarbonImmutable $startsAt,
        public int $expectedVersion,
        public ?int $actorUserId,
        public bool $adminContext,
        public bool $updateNotes = false,
        public ?string $notes = null,
    ) {}
}
