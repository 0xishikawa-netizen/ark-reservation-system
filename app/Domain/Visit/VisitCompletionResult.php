<?php

declare(strict_types=1);

namespace App\Domain\Visit;

use App\Models\Reservation;
use App\Models\Visit;

final readonly class VisitCompletionResult
{
    public function __construct(
        public ?Reservation $reservation,
        public Visit $visit,
        public bool $accountingPending,
    ) {}
}
