<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation\Dto;

final readonly class GatewayCapabilities
{
    public function __construct(
        public bool $canFetchAvailability,
        public bool $canPush,
        public bool $canUpdate,
        public bool $canCancel,
        public bool $canPull,
        public bool $supportsWebhook,
    ) {}

    public static function none(): self
    {
        return new self(
            canFetchAvailability: false,
            canPush: false,
            canUpdate: false,
            canCancel: false,
            canPull: false,
            supportsWebhook: false,
        );
    }
}
