<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalIntegration;

use App\Modules\ExternalIntegration\Exceptions\GatewayNotImplementedException;
use App\Modules\ExternalIntegration\Exceptions\UnsupportedOperationException;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\AvailabilityQuery;
use App\Modules\ExternalIntegration\Gateways\Reservation\Dto\ReservationSnapshot;
use App\Modules\ExternalIntegration\Gateways\Reservation\ExternalReservationGateway;
use App\Modules\ExternalIntegration\Gateways\Reservation\NullExternalReservationGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GatewayBindingTest extends TestCase
{
    public function test_default_gateway_resolves_to_null_gateway(): void
    {
        config(['reservation.gateway' => 'null']);

        $this->assertInstanceOf(
            NullExternalReservationGateway::class,
            app(ExternalReservationGateway::class),
        );
    }

    public function test_selecting_peak_manager_throws_the_phase_one_exception_on_resolution(): void
    {
        config(['reservation.gateway' => 'peak_manager']);

        $this->expectException(GatewayNotImplementedException::class);
        $this->expectExceptionMessage('ゲートウェイ [peak_manager] は Phase 1 では未実装です。');

        app(ExternalReservationGateway::class);
    }

    public function test_null_gateway_methods_issue_no_database_queries(): void
    {
        config(['reservation.gateway' => 'null']);

        $gateway = app(ExternalReservationGateway::class);
        $query = new AvailabilityQuery(
            CarbonImmutable::parse('2026-09-08 10:00:00'),
            CarbonImmutable::parse('2026-09-08 11:00:00'),
        );
        $snapshot = new ReservationSnapshot(
            localReservationId: null,
            customerName: 'テスト顧客',
            serviceName: 'テストサービス',
            staffName: null,
            startsAt: $query->from,
            endsAt: $query->to,
            source: 'local',
        );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $gateway->capabilities();
        $unsupportedCalls = 0;

        foreach ([
            fn () => $gateway->fetchAvailability($query),
            fn () => $gateway->pushReservation($snapshot),
            fn () => $gateway->updateExternalReservation('external-1', $snapshot),
            function () use ($gateway): void {
                $gateway->cancelExternalReservation('external-1', null);
            },
            fn () => $gateway->pullReservations($query->from, $query->to),
        ] as $operation) {
            try {
                $operation();
            } catch (UnsupportedOperationException) {
                $unsupportedCalls++;
            }
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $unsupportedCalls);
        $this->assertCount(0, $queries);
    }
}
