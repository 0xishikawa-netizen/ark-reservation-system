<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Payment\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentRefund> */
class PaymentRefundFactory extends Factory
{
    protected $model = PaymentRefund::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'refund_operation_id' => fake()->unique()->uuid(),
            'amount' => fake()->numberBetween(1, 1000),
            'reason' => fake()->sentence(),
            'status' => RefundStatus::Pending,
            'stripe_refund_id' => null,
            'failure_code' => null,
            'failure_message' => null,
            'created_by' => User::factory(),
        ];
    }
}
