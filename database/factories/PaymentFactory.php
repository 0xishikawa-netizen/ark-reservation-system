<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'parent_payment_id' => null,
            'customer_id' => static fn (array $attributes): int => (int) Reservation::query()
                ->findOrFail($attributes['reservation_id'])
                ->customer_id,
            'kind' => PaymentKind::Single,
            'provider' => 'stripe',
            'payment_operation_id' => fake()->unique()->uuid(),
            'amount' => fake()->numberBetween(1000, 30000),
            'currency' => 'jpy',
            'status' => PaymentStatus::Pending,
            'payment_expires_at' => null,
            'capture_method' => 'manual',
            'stripe_payment_intent_id' => null,
            'stripe_charge_id' => null,
            'authorized_at' => null,
            'paid_at' => null,
            'voided_at' => null,
            'refunded_amount' => 0,
            'failure_code' => null,
            'failure_message' => null,
            'needs_attention' => false,
            'last_synced_at' => null,
            'created_by' => null,
        ];
    }
}
