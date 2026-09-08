<?php

declare(strict_types=1);

namespace App\Exceptions\Reservation;

use RuntimeException;

final class StaleReservationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('予約が他で更新されました。画面を更新してください');
    }
}
