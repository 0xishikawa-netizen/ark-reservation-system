<?php

declare(strict_types=1);

namespace App\Exceptions\Reservation;

use RuntimeException;
use Throwable;

final class SlotUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('指定の時間帯は既に予約されています', 0, $previous);
    }
}
