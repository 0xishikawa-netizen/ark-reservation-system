<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\TicketLedgerService;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileTicketsCommandTest extends TestCase
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

    public function test_consistent_wallet_exits_successfully(): void
    {
        $this->walletWithGrant(5, 'consistent');

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();
    }

    public function test_tampered_balance_is_reported_without_pii_and_returns_failure(): void
    {
        $user = User::factory()->create([
            'name' => '照合テスト顧客氏名',
            'email' => 'reconcile-pii@example.test',
        ]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'kana' => 'ショウゴウテストカナ',
        ]);
        $wallet = $this->walletWithGrant(5, 'tampered', $customer);
        DB::table('ticket_wallets')->where('id', $wallet->id)->update(['balance' => 99]);

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain("wallet#{$wallet->id} customer#{$customer->user_id}")
            ->doesntExpectOutputToContain('照合テスト顧客氏名')
            ->doesntExpectOutputToContain('reconcile-pii@example.test')
            ->doesntExpectOutputToContain('ショウゴウテストカナ')
            ->assertFailed();
    }

    public function test_repair_updates_only_cache_and_writes_one_audit_log(): void
    {
        $wallet = $this->walletWithGrant(5, 'repair');
        DB::table('ticket_wallets')->where('id', $wallet->id)->update(['balance' => 99]);
        $transactionCount = TicketTransaction::query()->count();

        $this->artisan('tickets:reconcile', ['--repair' => true])
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();

        $this->assertSame(5, $wallet->fresh()->balance);
        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket.reconcile.repaired',
            'entity_id' => (string) $wallet->id,
        ]);
        $this->assertSame(1, $this->auditCount());
    }

    public function test_repair_dry_run_does_not_write_cache_or_audit_log(): void
    {
        $wallet = $this->walletWithGrant(5, 'repair-dry-run');
        DB::table('ticket_wallets')->where('id', $wallet->id)->update(['balance' => 99]);
        $transactionCount = TicketTransaction::query()->count();

        $this->artisan('tickets:reconcile', ['--repair' => true, '--dry-run' => true])
            ->expectsOutputToContain("修復予定 wallet#{$wallet->id} customer#{$wallet->customer_id}")
            ->assertFailed();

        $this->assertSame(99, $wallet->fresh()->balance);
        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_status_discrepancy_is_detected_and_repaired(): void
    {
        $wallet = $this->walletWithGrant(5, 'status');
        DB::table('ticket_wallets')->where('id', $wallet->id)->update([
            'status' => TicketWalletStatus::Exhausted->value,
        ]);

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain('status 不整合')
            ->assertFailed();

        $this->artisan('tickets:reconcile', ['--repair' => true])->assertSuccessful();

        $this->assertSame(TicketWalletStatus::Active, $wallet->fresh()->status);
    }

    public function test_unprocessed_expiration_is_detected_and_repair_only_updates_derived_fields(): void
    {
        $wallet = $this->walletWithGrant(5, 'expiration', expiresAt: '2026-09-07');
        $transactionCount = TicketTransaction::query()->count();

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain('expire 未処理')
            ->assertFailed();

        $this->artisan('tickets:reconcile', ['--repair' => true])
            ->expectsOutputToContain('expired wallet の available 未ゼロ化')
            ->assertFailed();

        $fresh = $wallet->fresh();
        $this->assertSame(TicketWalletStatus::Expired, $fresh->status);
        $this->assertSame(5, $fresh->balance);
        $this->assertSame(5, $this->ledger()->available($fresh));
        $this->assertSame($transactionCount, TicketTransaction::query()->count());
    }

    public function test_held_usage_without_ledger_hold_is_reported(): void
    {
        $wallet = $this->walletWithGrant(5, 'held-mismatch');
        $reservation = Reservation::factory()->create(['customer_id' => $wallet->customer_id]);
        TicketReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'ticket_wallet_id' => $wallet->id,
            'status' => TicketReservationUsageStatus::Held,
        ]);

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain("held 不整合 wallet#{$wallet->id} customer#{$wallet->customer_id}")
            ->assertFailed();
    }

    public function test_consistent_unresolved_hold_is_summarized_without_being_a_discrepancy(): void
    {
        $wallet = $this->walletWithGrant(5, 'held-consistent');
        $reservation = Reservation::factory()->create(['customer_id' => $wallet->customer_id]);
        $this->ledger()->append(
            $wallet,
            TicketTransactionType::ReserveHold,
            -1,
            "resv:{$reservation->id}:RESERVE_HOLD",
            reservationId: (int) $reservation->id,
        );
        TicketReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'ticket_wallet_id' => $wallet->id,
            'status' => TicketReservationUsageStatus::Held,
        ]);

        $this->artisan('tickets:reconcile')
            ->expectsOutput('未解消 HOLD のある wallet: 1 件')
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();
    }

    public function test_wallet_option_limits_inspection_and_repair_to_one_wallet(): void
    {
        $target = $this->walletWithGrant(5, 'wallet-target');
        $other = $this->walletWithGrant(3, 'wallet-other');
        DB::table('ticket_wallets')->where('id', $target->id)->update(['balance' => 50]);
        DB::table('ticket_wallets')->where('id', $other->id)->update(['balance' => 30]);

        $this->artisan('tickets:reconcile', [
            '--wallet' => $target->id,
            '--repair' => true,
        ])
            ->expectsOutputToContain("wallet#{$target->id} customer#{$target->customer_id}")
            ->doesntExpectOutputToContain("wallet#{$other->id} customer#{$other->customer_id}")
            ->expectsOutputToContain('対象 wallet 1 件')
            ->assertSuccessful();

        $this->assertSame(5, $target->fresh()->balance);
        $this->assertSame(30, $other->fresh()->balance);
        $this->assertSame(1, $this->auditCount());
    }

    public function test_negative_available_is_reported(): void
    {
        $wallet = TicketWallet::factory()->create();
        TicketTransaction::query()->create([
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Adjust,
            'delta' => -1,
            'reservation_id' => null,
            'staff_id' => null,
            'reason' => '異常データ作成',
            'dedupe_key' => 'test:negative-available',
            'created_at' => now(),
        ]);

        $this->artisan('tickets:reconcile')
            ->expectsOutputToContain('負の available')
            ->expectsOutputToContain("wallet#{$wallet->id} customer#{$wallet->customer_id}")
            ->assertFailed();
    }

    private function walletWithGrant(
        int $balance,
        string $key,
        ?Customer $customer = null,
        string $expiresAt = '2026-12-31',
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

    private function auditCount(): int
    {
        return (int) AuditLog::query()
            ->where('action', 'ticket.reconcile.repaired')
            ->count();
    }
}
