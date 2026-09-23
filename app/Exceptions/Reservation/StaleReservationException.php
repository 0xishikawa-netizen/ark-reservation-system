<?php

declare(strict_types=1);

namespace App\Exceptions\Reservation;

use RuntimeException;

final class StaleReservationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('messages.reservation.stale'));
    }
}
