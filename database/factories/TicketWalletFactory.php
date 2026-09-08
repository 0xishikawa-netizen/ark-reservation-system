<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ticket\TicketWalletStatus;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\TicketWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketWallet> */
class TicketWalletFactory extends Factory
{
    protected $model = TicketWallet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'ticket_product_id' => TicketProduct::factory(),
            'purchased_count' => static fn (array $attributes): int => (int) TicketProduct::query()
                ->findOrFail($attributes['ticket_product_id'])
                ->total_count,
            'balance' => 0,
            'expires_at' => today()->addDays(90),
            'status' => TicketWalletStatus::Active->value,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => today()->subDay(),
            'status' => TicketWalletStatus::Expired->value,
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (): array => [
            'balance' => 0,
            'status' => TicketWalletStatus::Exhausted->value,
        ]);
    }
}
