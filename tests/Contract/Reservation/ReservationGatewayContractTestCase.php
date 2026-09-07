<?php

declare(strict_types=1);

namespace Tests\Contract\Reservation;

use App\Modules\ExternalIntegration\Exceptions\UnsupportedOperationException;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityQuery;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityResult;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ExternalRef;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\GatewayCapabilities;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ReservationSnapshot;
use App\Modules\ExternalIntegration\Gateways\Reservation\ExternalReservationGateway;
use Carbon\CarbonImmutable;
use Tests\TestCase;

abstract class ReservationGatewayContractTestCase extends TestCase
{
    abstract protected function gateway(): ExternalReservationGateway;

    abstract protected function expectsUnsupported(): bool;

    public function test_capabilities_returns_gateway_capabilities(): void
    {
        $this->assertInstanceOf(GatewayCapabilities::class, $this->gateway()->capabilities());
    }

    public function test_fetch_availability_satisfies_the_contract(): void
    {
        if ($this->expectsUnsupported()) {
            $this->expectException(UnsupportedOperationException::class);
            $this->expectExceptionMessage('fetchAvailability');
        }

        $result = $this->gateway()->fetchAvailability($this->availabilityQuery());

        $this->assertInstanceOf(AvailabilityResult::class, $result);
    }

    public function test_push_reservation_satisfies_the_contract(): void
    {
        if ($this->expectsUnsupported()) {
            $this->expectException(UnsupportedOperationException::class);
            $this->expectExceptionMessage('pushReservation');
        }

        $result = $this->gateway()->pushReservation($this->reservationSnapshot());

        $this->assertInstanceOf(ExternalRef::class, $result);
    }

    public function test_update_external_reservation_satisfies_the_contract(): void
    {
        if ($this->expectsUnsupported()) {
            $this->expectException(UnsupportedOperationException::class);
            $this->expectExceptionMessage('updateExternalReservation');
        }

        $result = $this->gateway()->updateExternalReservation('external-1', $this->reservationSnapshot());

        $this->assertInstanceOf(ExternalRef::class, $result);
    }

    public function test_cancel_external_reservation_satisfies_the_contract(): void
    {
        if ($this->expectsUnsupported()) {
            $this->expectException(UnsupportedOperationException::class);
            $this->expectExceptionMessage('cancelExternalReservation');
        }

        $this->gateway()->cancelExternalReservation('external-1', 'テスト取消');

        $this->addToAssertionCount(1);
    }

    public function test_pull_reservations_satisfies_the_contract(): void
    {
        if ($this->expectsUnsupported()) {
            $this->expectException(UnsupportedOperationException::class);
            $this->expectExceptionMessage('pullReservations');
        }

        $result = $this->gateway()->pullReservations(
            CarbonImmutable::parse('2026-09-08 10:00:00'),
            CarbonImmutable::parse('2026-09-08 11:00:00'),
        );

        $this->assertIsIterable($result);
    }

    protected function availabilityQuery(): AvailabilityQuery
    {
        return new AvailabilityQuery(
            from: CarbonImmutable::parse('2026-09-08 10:00:00'),
            to: CarbonImmutable::parse('2026-09-08 11:00:00'),
            staffId: 1,
            serviceId: 2,
        );
    }

    protected function reservationSnapshot(): ReservationSnapshot
    {
        return new ReservationSnapshot(
            localReservationId: 1,
            customerName: '予約 太郎',
            serviceName: 'コンディショニング',
            staffName: '担当 花子',
            startsAt: CarbonImmutable::parse('2026-09-08 10:00:00'),
            endsAt: CarbonImmutable::parse('2026-09-08 11:00:00'),
            source: 'local',
        );
    }
}
