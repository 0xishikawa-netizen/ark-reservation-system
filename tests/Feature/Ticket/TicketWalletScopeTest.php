<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Enums\Ticket\TicketWalletStatus;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\TicketWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TicketWalletScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fefo_orders_by_expiration_then_creation_then_id(): void
    {
        $customer = Customer::factory()->create();
        $product = TicketProduct::factory()->create();
        $base = [
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => $product->total_count,
            'balance' => 0,
            'status' => TicketWalletStatus::Active->value,
        ];

        $early = TicketWallet::factory()->create([
            ...$base,
            'expires_at' => '2026-10-01',
            'created_at' => '2026-09-04 09:00:00',
        ]);
        $middleLatest = TicketWallet::factory()->create([
            ...$base,
            'expires_at' => '2026-10-10',
            'created_at' => '2026-09-03 09:00:00',
        ]);
        $middleOldest = TicketWallet::factory()->create([
            ...$base,
            'expires_at' => '2026-10-10',
            'created_at' => '2026-09-01 09:00:00',
        ]);
        $middleTieFirst = TicketWallet::factory()->create([
            ...$base,
            'expires_at' => '2026-10-10',
            'created_at' => '2026-09-02 09:00:00',
        ]);
        $middleTieSecond = TicketWallet::factory()->create([
            ...$base,
            'expires_at' => '2026-10-10',
            'created_at' => '2026-09-02 09:00:00',
        ]);
        $late = TicketWallet::factory()->create([
            ...$base,
            'expires_at' => '2026-10-20',
            'created_at' => '2026-09-01 09:00:00',
        ]);

        $this->assertSame(
            [
                $early->id,
                $middleOldest->id,
                $middleTieFirst->id,
                $middleTieSecond->id,
                $middleLatest->id,
                $late->id,
            ],
            TicketWallet::query()->fefo()->pluck('id')->all(),
        );
    }

    public function test_active_excludes_non_active_and_expired_wallets(): void
    {
        $activeFuture = TicketWallet::factory()->create([
            'expires_at' => '2026-09-09',
            'status' => TicketWalletStatus::Active->value,
        ]);
        $activeToday = TicketWallet::factory()->create([
            'expires_at' => '2026-09-08',
            'status' => TicketWalletStatus::Active->value,
        ]);
        TicketWallet::factory()->create([
            'expires_at' => '2026-09-07',
            'status' => TicketWalletStatus::Active->value,
        ]);
        TicketWallet::factory()->expired()->create([
            'expires_at' => '2026-09-09',
        ]);
        TicketWallet::factory()->exhausted()->create([
            'expires_at' => '2026-09-09',
        ]);

        $this->assertEqualsCanonicalizing(
            [$activeFuture->id, $activeToday->id],
            TicketWallet::query()->active()->pluck('id')->all(),
        );
    }
}
