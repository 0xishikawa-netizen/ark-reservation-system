<?php

declare(strict_types=1);

namespace App\Modules\ExternalIntegration;

use App\Modules\ExternalIntegration\Exceptions\GatewayNotImplementedException;
use App\Modules\ExternalIntegration\Gateways\Reservation\ExternalReservationGateway;
use Illuminate\Support\ServiceProvider;

class ExternalIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExternalReservationGateway::class, function (): ExternalReservationGateway {
            $key = config('reservation.gateway', 'null');
            $key = is_string($key) ? $key : '';
            $class = config("reservation.gateways.{$key}.class");

            if ($key !== 'null' && (
                in_array($key, ['peak_manager', 'salon_board'], true)
                || ! is_string($class)
                || ! class_exists($class)
            )) {
                throw new GatewayNotImplementedException(
                    "ゲートウェイ [{$key}] は Phase 1 では未実装です。",
                );
            }

            /** @var class-string<ExternalReservationGateway> $class */
            return $this->app->make($class);
        });
    }
}
