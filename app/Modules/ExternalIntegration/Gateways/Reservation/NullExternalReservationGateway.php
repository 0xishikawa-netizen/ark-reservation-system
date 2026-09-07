<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation;

use App\Modules\ExternalIntegration\Exceptions\UnsupportedOperationException;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityQuery;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityResult;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ExternalRef;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\GatewayCapabilities;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ReservationSnapshot;
use Carbon\CarbonImmutable;

class NullExternalReservationGateway implements ExternalReservationGateway
{
    public function capabilities(): GatewayCapabilities
    {
        return GatewayCapabilities::none();
    }

    public function fetchAvailability(AvailabilityQuery $query): AvailabilityResult
    {
        throw new UnsupportedOperationException('fetchAvailability は NullExternalReservationGateway では利用できません。');
    }

    public function pushReservation(ReservationSnapshot $snapshot): ExternalRef
    {
        throw new UnsupportedOperationException('pushReservation は NullExternalReservationGateway では利用できません。');
    }

    public function updateExternalReservation(string $externalId, ReservationSnapshot $snapshot): ExternalRef
    {
        throw new UnsupportedOperationException('updateExternalReservation は NullExternalReservationGateway では利用できません。');
    }

    public function cancelExternalReservation(string $externalId, ?string $reason): void
    {
        throw new UnsupportedOperationException('cancelExternalReservation は NullExternalReservationGateway では利用できません。');
    }

    public function pullReservations(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        throw new UnsupportedOperationException('pullReservations は NullExternalReservationGateway では利用できません。');
    }
}
