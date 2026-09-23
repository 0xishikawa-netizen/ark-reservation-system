<?php

declare(strict_types=1);

namespace App\Actions\Reservation;

use App\Enums\Reservation\InflowChannel;
use App\Models\Reservation;

final class RecordReservationInflowChannel
{
    public function execute(Reservation $reservation, InflowChannel $channel): Reservation
    {
        $reservation->forceFill(['inflow_channel' => $channel->value])->save();

        return $reservation;
    }
}
