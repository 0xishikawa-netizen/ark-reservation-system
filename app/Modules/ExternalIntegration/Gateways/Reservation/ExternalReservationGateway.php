<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration\Gateways\Reservation;

use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityQuery;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityResult;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ExternalRef;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\GatewayCapabilities;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ReservationSnapshot;
use Carbon\CarbonImmutable;

interface ExternalReservationGateway
{
    public function capabilities(): GatewayCapabilities;

    public function fetchAvailability(AvailabilityQuery $query): AvailabilityResult;

    public function pushReservation(ReservationSnapshot $snapshot): ExternalRef;

    public function updateExternalReservation(string $externalId, ReservationSnapshot $snapshot): ExternalRef;

    public function cancelExternalReservation(string $externalId, ?string $reason): void;

    /** @return iterable<ReservationSnapshot> */
    public function pullReservations(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
