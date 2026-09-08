<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Models\Reservation;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketReservationUsage> */
class TicketReservationUsageFactory extends Factory
{
    protected $model = TicketReservationUsage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'ticket_wallet_id' => TicketWallet::factory(),
            'no_show_policy' => TicketNoShowPolicy::Restore,
            'status' => TicketReservationUsageStatus::Held,
            'held_at' => now(),
            'released_at' => null,
            'consumed_at' => null,
        ];
    }
}
