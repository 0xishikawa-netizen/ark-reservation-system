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
        // null = 現状維持（未指定）。値がある場合のみ「指名」フラグを更新する。
        public ?bool $isStaffRequested = null,
        // 性別希望を更新する時だけ true（null = 希望なしへ戻す）。
        public bool $updateStaffGenderPreference = false,
        public ?string $staffGenderPreference = null,
    ) {}
}
