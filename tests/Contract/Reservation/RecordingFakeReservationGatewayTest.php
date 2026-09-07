<?php

declare(strict_types=1);

namespace Tests\Contract\Reservation;

use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityResult;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ExternalRef;
use App\Modules\ExternalIntegration\Gateways\Reservation\ExternalReservationGateway;
use Carbon\CarbonImmutable;
use Tests\Support\RecordingFakeReservationGateway;

class RecordingFakeReservationGatewayTest extends ReservationGatewayContractTestCase
{
    protected function gateway(): ExternalReservationGateway
    {
        return new RecordingFakeReservationGateway;
    }

    protected function expectsUnsupported(): bool
    {
        return false;
    }

    public function test_fake_records_calls_and_returns_canned_values_without_database_access(): void
    {
        $gateway = new RecordingFakeReservationGateway;
        $query = $this->availabilityQuery();
        $snapshot = $this->reservationSnapshot();

        $availability = $gateway->fetchAvailability($query);
        $pushed = $gateway->pushReservation($snapshot);
        $updated = $gateway->updateExternalReservation('external-1', $snapshot);
        $gateway->cancelExternalReservation('external-1', 'テスト取消');
        $pulled = $gateway->pullReservations(
            CarbonImmutable::parse('2026-09-08 10:00:00'),
            CarbonImmutable::parse('2026-09-08 11:00:00'),
        );

        $this->assertInstanceOf(AvailabilityResult::class, $availability);
        $this->assertInstanceOf(ExternalRef::class, $pushed);
        $this->assertInstanceOf(ExternalRef::class, $updated);
        $this->assertCount(1, $gateway->availabilityQueries);
        $this->assertCount(1, $gateway->pushedReservations);
        $this->assertCount(1, $gateway->updatedReservations);
        $this->assertCount(1, $gateway->canceledReservations);
        $this->assertCount(1, $gateway->pullRanges);
        $this->assertCount(1, is_array($pulled) ? $pulled : iterator_to_array($pulled));
        $this->assertTrue($gateway->capabilities()->canPush);
        $this->assertFalse($gateway->capabilities()->supportsWebhook);
    }
}
