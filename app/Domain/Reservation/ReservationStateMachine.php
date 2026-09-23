<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Enums\Reservation\ReservationStatus;
use App\Support\StateMachine\StateMachine;

class ReservationStateMachine extends StateMachine
{
    public function assertCanTransition(ReservationStatus $from, ReservationStatus $to): void
    {
        $this->assert($from->value, $to->value);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            ReservationStatus::PendingPayment->value => [
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Canceled->value,
                ReservationStatus::Expired->value,
            ],
            ReservationStatus::PendingExternalSync->value => [
                ReservationStatus::Confirmed->value,
                ReservationStatus::Canceled->value,
                ReservationStatus::NoShow->value,
            ],
            ReservationStatus::Confirmed->value => [
                ReservationStatus::Completed->value,
                ReservationStatus::Canceled->value,
                ReservationStatus::NoShow->value,
            ],
            ReservationStatus::Completed->value => [],
            ReservationStatus::NoShow->value => [],
            ReservationStatus::Canceled->value => [],
            ReservationStatus::Expired->value => [],
        ];
    }
}
