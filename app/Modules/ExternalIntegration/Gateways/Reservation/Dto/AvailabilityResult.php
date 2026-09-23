<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation\Dto;

final readonly class AvailabilityResult
{
    /**
     * @param  list<array{starts_at: string, ends_at: string, staff_id: int|null}>  $slots
     */
    public function __construct(public array $slots) {}
}
