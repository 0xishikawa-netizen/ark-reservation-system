<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\TicketLedgerService;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpireTicketsCommandTest extends TestCase
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

    public function test_it_expires_only_the_available_balance_and_writes_an_audit_log(): void
    {
        $wallet = $this->walletWithBalance(3, '2026-09-07', 'expire-basic');

        $this->artisan('tickets:expire')
            ->expectsOutput('失効した回数券件数: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Expire->value,
            'delta' => -3,
            'dedupe_key' => "expire:{$wallet->id}:202609",
        ]);
        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(TicketWalletStatus::Expired, $wallet->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.expired',
            'entity_id' => (string) $wallet->id,
        ]);
    }

    public function test_it_is_idempotent(): void
    {
        $wallet = $this->walletWithBalance(3, '2026-09-07', 'expire-idempotent');

        $this->artisan('tickets:expire')->assertSuccessful();
        $transactionCount = TicketTransaction::query()->count();
        $balance = $this->ledger()->available($wallet);

        $this->artisan('tickets:expire')
            ->expectsOutput('失効した回数券件数: 0')
            ->assertSuccessful();

        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertSame($balance, $this->ledger()->available($wallet));
        $this->assertSame(1, TicketTransaction::query()
            ->where('dedupe_key', "expire:{$wallet->id}:202609")
            ->count());
        $this->assertSame(1, $this->auditCount('ticket.expired'));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $wallet = $this->walletWithBalance(3, '2026-09-07', 'expire-dry-run');
        $transactionCount = TicketTransaction::query()->count();

        $this->artisan('tickets:expire', ['--dry-run' => true])
            ->expectsOutput("wallet#{$wallet->id} customer#{$wallet->customer_id} available=3")
            ->expectsOutput('失効対象回数券件数: 1（dry-run: 変更なし）')
            ->assertSuccessful();

        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketWalletStatus::Active, $wallet->fresh()->status);
        $this->assertSame(0, $this->auditCount('ticket.expired'));
    }

    public function test_future_wallet_is_not_changed(): void
    {
        $wallet = $this->walletWithBalance(3, '2026-09-09', 'expire-future');

        $this->artisan('tickets:expire')
            ->expectsOutput('失効した回数券件数: 0')
            ->assertSuccessful();

        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketWalletStatus::Active, $wallet->fresh()->status);
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::Expire));
    }

    public function test_unresolved_hold_and_reservation_are_preserved(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $wallet = $this->walletWithBalance(
            balance: 5,
            expiresAt: '2026-09-09',
            key: 'expire-held',
            customer: $customer,
        );
        app(TicketReservationService::class)->hold($reservation);
        $usageBefore = $this->usage($reservation)->getAttributes();
        $reservationBefore = $reservation->fresh()->getAttributes();

        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $this->artisan('tickets:expire')->assertSuccessful();

        $usage = $this->usage($reservation);
        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->ledger()->held($wallet));
        $this->assertSame(TicketWalletStatus::Expired, $wallet->fresh()->status);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Expire->value,
            'delta' => -4,
            'dedupe_key' => "expire:{$wallet->id}:202609",
        ]);
        $this->assertSame(TicketReservationUsageStatus::Held, $usage->status);
        $this->assertSame($usageBefore, $usage->getAttributes());
        $this->assertSame($reservationBefore, $reservation->fresh()->getAttributes());
    }

    private function walletWithBalance(
        int $balance,
        string $expiresAt,
        string $key,
        ?Customer $customer = null,
    ): TicketWallet {
        $customer ??= Customer::factory()->create();
        $product = TicketProduct::factory()->create();
        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => $balance,
            'expires_at' => $expiresAt,
            'status' => TicketWalletStatus::Active,
        ]);

        $this->ledger()->append(
            $wallet,
            TicketTransactionType::Grant,
            $balance,
            "grant:{$key}",
        );

        return $wallet;
    }

    private function ledger(): TicketLedgerService
    {
        return app(TicketLedgerService::class);
    }

    private function usage(Reservation $reservation): TicketReservationUsage
    {
        return TicketReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
    }

    private function transactionCount(TicketTransactionType $type): int
    {
        return TicketTransaction::query()->where('type', $type->value)->count();
    }

    private function auditCount(string $action): int
    {
        return (int) AuditLog::query()->where('action', $action)->count();
    }
}
