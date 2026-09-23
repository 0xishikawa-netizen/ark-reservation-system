<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Enums\Schedule\ScheduleBlockType;
use Carbon\CarbonImmutable;

final readonly class ScheduleBlockInput
{
    public function __construct(
        public ?int $staffId,
        public ?int $boothId,
        public CarbonImmutable $workDate,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ScheduleBlockType $type,
        public ?string $title,
        public ?string $note,
        public ?int $actorUserId,
    ) {}
}
