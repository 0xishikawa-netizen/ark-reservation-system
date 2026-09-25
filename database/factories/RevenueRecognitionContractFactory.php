<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Accounting\RevenueContractKind;
use App\Enums\Accounting\RevenueRecognitionContractStatus;
use App\Models\RevenueRecognitionContract;
use App\Models\TicketWallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RevenueRecognitionContract> */
class RevenueRecognitionContractFactory extends Factory
{
    protected $model = RevenueRecognitionContract::class;

    public function definition(): array
    {
        return ['kind' => RevenueContractKind::Ticket, 'ticket_wallet_id' => TicketWallet::factory(), 'contract_amount' => 30000, 'currency' => 'jpy', 'status' => RevenueRecognitionContractStatus::Active, 'operation_key' => (string) Str::uuid()];
    }
}
