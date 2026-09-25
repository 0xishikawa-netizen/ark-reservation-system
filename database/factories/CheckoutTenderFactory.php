<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Accounting\CheckoutTenderStatus;
use App\Models\Checkout;
use App\Models\CheckoutTender;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckoutTender> */
class CheckoutTenderFactory extends Factory
{
    protected $model = CheckoutTender::class;

    public function definition(): array
    {
        return ['checkout_id' => Checkout::factory(), 'payment_method_id' => PaymentMethod::factory(), 'amount' => 11000, 'status' => CheckoutTenderStatus::Received, 'received_at' => now()];
    }
}
