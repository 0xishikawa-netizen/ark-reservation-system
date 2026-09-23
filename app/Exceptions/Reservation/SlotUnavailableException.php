<?php

declare(strict_types=1);

namespace App\Exceptions\Reservation;

use RuntimeException;
use Throwable;

final class SlotUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(__('messages.reservation.slot_taken'), 0, $previous);
    }
}
