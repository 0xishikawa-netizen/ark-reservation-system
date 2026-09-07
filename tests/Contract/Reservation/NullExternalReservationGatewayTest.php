<?php

declare(strict_types=1);

namespace Tests\Contract\Reservation;

use App\Modules\ExternalIntegration\Gateways\Reservation\ExternalReservationGateway;
use App\Modules\ExternalIntegration\Gateways\Reservation\NullExternalReservationGateway;

class NullExternalReservationGatewayTest extends ReservationGatewayContractTestCase
{
    protected function gateway(): ExternalReservationGateway
    {
        return new NullExternalReservationGateway;
    }

    protected function expectsUnsupported(): bool
    {
        return true;
    }

    public function test_all_capabilities_are_disabled(): void
    {
        $capabilities = $this->gateway()->capabilities();

        $this->assertFalse($capabilities->canFetchAvailability);
        $this->assertFalse($capabilities->canPush);
        $this->assertFalse($capabilities->canUpdate);
        $this->assertFalse($capabilities->canCancel);
        $this->assertFalse($capabilities->canPull);
        $this->assertFalse($capabilities->supportsWebhook);
    }
}
