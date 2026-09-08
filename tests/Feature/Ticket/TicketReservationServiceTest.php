<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\TicketLedgerService;
use App\Domain\Ticket\TicketPolicyResolver;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Ticket\TicketExpirationHoldPolicy;
use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TicketReservationServiceTest extends TestCase
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

    public function test_hold_selects_the_first_wallet_by_fefo(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $first = $this->walletWithBalance($customer, 2, '2026-09-30', 'fefo-first');
        $second = $this->walletWithBalance($customer, 2, '2026-10-31', 'fefo-second');

        $usage = $this->reservationService()->hold($reservation);

        $this->assertSame($first->id, $usage->ticket_wallet_id);
        $this->assertSame(1, $this->ledger()->available($first));
        $this->assertSame(2, $this->ledger()->available($second));
        $this->assertSame(TicketReservationUsageStatus::Held, $usage->status);
    }

    public function test_hold_skips_an_earlier_wallet_with_no_available_balance(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $first = $this->walletWithBalance($customer, 0, '2026-09-30', 'fefo-empty');
        $second = $this->walletWithBalance($customer, 2, '2026-10-31', 'fefo-available');

        $usage = $this->reservationService()->hold($reservation);

        $this->assertSame($second->id, $usage->ticket_wallet_id);
        $this->assertSame(0, $this->ledger()->available($first));
        $this->assertSame(1, $this->ledger()->available($second));
    }

    public function test_hold_fails_when_every_wallet_is_expired_or_empty(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $this->walletWithBalance($customer, 2, '2026-09-07', 'expired-wallet');
        $this->walletWithBalance($customer, 0, '2026-09-30', 'empty-wallet');

        try {
            $this->reservationService()->hold($reservation);
            $this->fail('利用可能残数がない状態で HOLD が成功しました。');
        } catch (InsufficientTicketBalanceException $exception) {
            $this->assertSame('利用可能な回数券がありません', $exception->getMessage());
        }

        $this->assertSame(0, TicketReservationUsage::query()->count());
        $this->assertSame(0, TicketTransaction::query()
            ->where('type', TicketTransactionType::ReserveHold->value)
            ->count());
    }

    public function test_hold_is_idempotent_for_a_reservation(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $wallet = $this->walletWithBalance($customer, 2, '2026-09-30', 'hold-idempotent');

        $first = $this->reservationService()->hold($reservation);
        $second = $this->reservationService()->hold($reservation);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, TicketReservationUsage::query()->count());
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveHold));
        $this->assertSame(1, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->auditCount('ticket.held'));
    }

    public function test_hold_snapshots_the_current_no_show_policy(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'consume');
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $this->walletWithBalance($customer, 2, '2026-09-30', 'snapshot');

        $usage = $this->reservationService()->hold($reservation);

        $this->assertSame(TicketNoShowPolicy::Consume, $usage->no_show_policy);
    }

    public function test_policy_resolver_falls_back_for_unknown_values(): void
    {
        $settings = app(Settings::class);
        $settings->set('ticket.no_show_policy', 'unknown');
        $settings->set('ticket.expiration_hold_policy', 'unknown');
        $resolver = app(TicketPolicyResolver::class);

        $this->assertSame(TicketNoShowPolicy::Restore, $resolver->noShowPolicy());
        $this->assertSame(TicketExpirationHoldPolicy::PreserveHold, $resolver->expirationHoldPolicy());
        $this->assertSame(['restore', 'consume'], TicketPolicyResolver::ALLOWED_NO_SHOW);
        $this->assertSame(['preserve_hold'], TicketPolicyResolver::ALLOWED_EXPIRATION_HOLD);
    }

    public function test_release_restores_the_held_ticket(): void
    {
        [$reservation, $wallet] = $this->heldReservation(3, 'release');

        $this->reservationService()->release($reservation);

        $usage = $this->usage($reservation);
        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(0, $this->ledger()->held($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $usage->status);
        $this->assertNotNull($usage->released_at);
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
    }

    public function test_release_is_idempotent(): void
    {
        [$reservation, $wallet] = $this->heldReservation(3, 'release-idempotent');

        $this->reservationService()->release($reservation);
        $this->reservationService()->release($reservation);

        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
        $this->assertSame(1, $this->auditCount('ticket.released'));
    }

    public function test_consume_releases_the_hold_before_consuming_once(): void
    {
        [$reservation, $wallet] = $this->heldReservation(3, 'consume');

        $this->reservationService()->consume($reservation);

        $usage = $this->usage($reservation);
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(0, $this->ledger()->held($wallet));
        $this->assertSame(TicketReservationUsageStatus::Consumed, $usage->status);
        $this->assertNotNull($usage->released_at);
        $this->assertNotNull($usage->consumed_at);
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::ReserveRelease));
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::Consume));
    }

    public function test_consume_is_idempotent(): void
    {
        [$reservation, $wallet] = $this->heldReservation(3, 'consume-idempotent');

        $this->reservationService()->consume($reservation);
        $transactionCount = TicketTransaction::query()->count();
        $this->reservationService()->consume($reservation);

        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->auditCount('ticket.consumed'));
    }

    public function test_no_show_restore_snapshot_is_not_changed_by_a_later_setting_change(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'restore');
        [$reservation, $wallet] = $this->heldReservation(3, 'no-show-restore');
        app(Settings::class)->set('ticket.no_show_policy', 'consume');

        $this->reservationService()->handleNoShow($reservation);

        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertSame(0, $this->transactionCount(TicketTransactionType::Consume));
    }

    public function test_no_show_consume_snapshot_is_not_changed_by_a_later_setting_change(): void
    {
        app(Settings::class)->set('ticket.no_show_policy', 'consume');
        [$reservation, $wallet] = $this->heldReservation(3, 'no-show-consume');
        app(Settings::class)->set('ticket.no_show_policy', 'restore');

        $this->reservationService()->handleNoShow($reservation);

        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(TicketReservationUsageStatus::Consumed, $this->usage($reservation)->status);
        $this->assertSame(1, $this->transactionCount(TicketTransactionType::Consume));
    }

    public function test_release_after_expiration_offsets_the_restored_ticket(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $wallet = $this->walletWithBalance($customer, 5, '2026-09-09', 'preserve-hold');
        $this->reservationService()->hold($reservation);

        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $this->artisan('tickets:expire')->assertSuccessful();

        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->ledger()->held($wallet));
        $this->assertSame(TicketWalletStatus::Expired, $wallet->fresh()->status);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Expire->value,
            'delta' => -4,
            'dedupe_key' => "expire:{$wallet->id}:202609",
        ]);

        $this->reservationService()->release($reservation);

        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(0, $this->ledger()->held($wallet));
        $this->assertSame(TicketReservationUsageStatus::Released, $this->usage($reservation)->status);
        $this->assertDatabaseHas('ticket_transactions', [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Expire->value,
            'delta' => -1,
            'dedupe_key' => "expire:{$wallet->id}:resv:{$reservation->id}",
        ]);
        $this->assertSame(1, TicketTransaction::query()
            ->where('dedupe_key', "expire:{$wallet->id}:resv:{$reservation->id}")
            ->count());
    }

    /** @return array{Reservation, TicketWallet} */
    private function heldReservation(int $balance, string $key): array
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $wallet = $this->walletWithBalance($customer, $balance, '2026-09-30', $key);
        $this->reservationService()->hold($reservation);

        return [$reservation, $wallet];
    }

    private function walletWithBalance(
        Customer $customer,
        int $balance,
        string $expiresAt,
        string $key,
    ): TicketWallet {
        $product = TicketProduct::factory()->create();
        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => max(1, $balance),
            'expires_at' => $expiresAt,
            'status' => TicketWalletStatus::Active,
        ]);

        if ($balance > 0) {
            $this->ledger()->append(
                $wallet,
                TicketTransactionType::Grant,
                $balance,
                "grant:{$key}",
            );
        }

        return $wallet;
    }

    private function reservationService(): TicketReservationService
    {
        return app(TicketReservationService::class);
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
