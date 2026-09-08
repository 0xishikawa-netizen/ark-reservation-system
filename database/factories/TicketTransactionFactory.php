<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ticket\TicketTransactionType;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketTransaction> */
class TicketTransactionFactory extends Factory
{
    protected $model = TicketTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_wallet_id' => TicketWallet::factory(),
            'type' => TicketTransactionType::Grant,
            'delta' => 5,
            'reservation_id' => null,
            'staff_id' => null,
            'reason' => null,
            'dedupe_key' => 'factory:'.fake()->unique()->uuid(),
            'created_at' => now(),
        ];
    }
}
