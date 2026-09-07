<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation\Dto;

final readonly class ExternalRef
{
    public function __construct(
        public string $provider,
        public string $externalId,
    ) {}
}
