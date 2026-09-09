<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enum;

/**
 * Provider が持ち得る機能。全 Provider に同じ機能を強制しない。
 */
enum ProviderCapability: string
{
    case ReadReservations = 'read_reservations';
    case CreateReservations = 'create_reservations';
    case UpdateReservations = 'update_reservations';
    case CancelReservations = 'cancel_reservations';
    case ReadAvailability = 'read_availability';
    case ReadCustomers = 'read_customers';
    case Webhook = 'webhook';
    case Polling = 'polling';
}
