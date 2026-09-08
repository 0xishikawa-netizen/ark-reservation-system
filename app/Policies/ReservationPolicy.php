<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $reservation->customer_id === $user->customer?->user_id;
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $reservation->customer_id === $user->customer?->user_id;
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $reservation->customer_id === $user->customer?->user_id;
    }
}
