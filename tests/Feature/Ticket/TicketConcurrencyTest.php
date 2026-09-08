<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\TicketLedgerService;
use App\Domain\Ticket\TicketReservationService;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TicketConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?Connection $primaryConnection = null;

    private ?Connection $secondConnection = null;

    private string $originalDefaultConnection = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = DB::getDefaultConnection();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL 前提の回数券並行テスト');
        }

        $mysqlConfig = config('database.connections.mysql');
        $this->assertIsArray($mysqlConfig);

        config(['database.connections.mysql_second' => $mysqlConfig]);
        DB::purge('mysql_second');

        $this->primaryConnection = DB::connection($this->originalDefaultConnection);
        $this->secondConnection = DB::connection('mysql_second');
        $this->secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefaultConnection);
        $this->rollBackOpenTransactions($this->secondConnection);
        $this->rollBackOpenTransactions($this->primaryConnection);

        if ($this->secondConnection !== null) {
            DB::disconnect('mysql_second');
        }

        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_two_uncommitted_holds_for_the_last_ticket_allow_exactly_one_success(): void
    {
        [$wallet, $first, $second] = $this->lastTicketFixture('last-ticket-uncommitted');
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $this->reservations()->hold($first);

            try {
                $this->onSecondConnection(
                    fn (): TicketReservationUsage => $this->reservations()->hold($second),
                );
                $this->fail('接続 A が最後の1回を未コミットで保持中に接続 B の HOLD が成功しました。');
            } catch (QueryException $exception) {
                $this->assertLockWaitTimeout($exception);
            }

            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $this->assertSingleHoldOutcome($wallet, $first);
    }

    public function test_hold_after_the_last_ticket_is_committed_fails_with_insufficient_balance(): void
    {
        [$wallet, $first, $second] = $this->lastTicketFixture('last-ticket-committed');
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $this->reservations()->hold($first);
            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        try {
            $this->onSecondConnection(
                fn (): TicketReservationUsage => $this->reservations()->hold($second),
            );
            $this->fail('最後の1回が commit 済みなのに接続 B の HOLD が成功しました。');
        } catch (InsufficientTicketBalanceException) {
            $this->assertTrue(true);
        }

        $this->assertSingleHoldOutcome($wallet, $first);
    }

    public function test_concurrent_releases_do_not_restore_the_same_hold_twice(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $wallet = $this->walletWithBalance($customer, 3, 'release-race');
        $this->reservations()->hold($reservation);
        $this->assertSame(2, $this->ledger()->available($wallet));
        $this->assertSame(1, $this->ledger()->held($wallet));

        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $this->reservations()->release($reservation);

            try {
                $this->onSecondConnection(function () use ($reservation): void {
                    $this->reservations()->release($reservation);
                });
                $this->fail('接続 A の RELEASE が未コミットの間に接続 B の RELEASE が成功しました。');
            } catch (QueryException $exception) {
                $this->assertLockWaitTimeout($exception);
            }

            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $this->onSecondConnection(function () use ($reservation): void {
            $this->reservations()->release($reservation);
        });

        $usage = TicketReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
        $releaseKey = "resv:{$reservation->id}:RESERVE_RELEASE";

        $this->assertSame(TicketReservationUsageStatus::Released, $usage->status);
        $this->assertSame(3, $this->ledger()->available($wallet));
        $this->assertSame(0, $this->ledger()->held($wallet));
        $this->assertSame(1, TicketTransaction::query()
            ->where('type', TicketTransactionType::ReserveRelease->value)
            ->where('dedupe_key', $releaseKey)
            ->count());
        $this->assertLessThanOrEqual(3, max($this->runningBalances($wallet)));
        $this->assertNonNegativeHistory($wallet);
    }

    public function test_dedupe_unique_constraint_is_the_final_defence_across_connections(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $wallet = $this->walletWithBalance($customer, 1, 'dedupe-race');
        $dedupeKey = "resv:{$reservation->id}:RESERVE_HOLD";
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $primary->table('ticket_transactions')->insert([
                'ticket_wallet_id' => $wallet->id,
                'type' => TicketTransactionType::ReserveHold->value,
                'delta' => -1,
                'reservation_id' => $reservation->id,
                'staff_id' => null,
                'reason' => null,
                'dedupe_key' => $dedupeKey,
                'created_at' => now(),
            ]);

            try {
                $this->onSecondConnection(fn (): TicketTransaction => $this->ledger()->append(
                    wallet: $wallet,
                    type: TicketTransactionType::ReserveHold,
                    delta: -1,
                    dedupeKey: $dedupeKey,
                    reservationId: (int) $reservation->id,
                ));
                $this->fail('未コミットの同一 dedupe_key に対して接続 B の append が成功しました。');
            } catch (QueryException $exception) {
                $this->assertLockWaitTimeout($exception);
            }

            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $existing = TicketTransaction::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        $transactionCount = TicketTransaction::query()->count();
        $balanceBeforeRetry = (int) $wallet->fresh()->balance;

        try {
            $this->onSecondConnection(function () use ($wallet, $reservation, $dedupeKey): void {
                DB::table('ticket_transactions')->insert([
                    'ticket_wallet_id' => $wallet->id,
                    'type' => TicketTransactionType::ReserveHold->value,
                    'delta' => -1,
                    'reservation_id' => $reservation->id,
                    'staff_id' => null,
                    'reason' => null,
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                ]);
            });
            $this->fail('DB UNIQUE 制約が同一 dedupe_key の直 INSERT を許可しました。');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0] ?? null);
            $this->assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
        }

        $retried = $this->onSecondConnection(fn (): TicketTransaction => $this->ledger()->append(
            wallet: $wallet,
            type: TicketTransactionType::ReserveHold,
            delta: -1,
            dedupeKey: $dedupeKey,
            reservationId: (int) $reservation->id,
        ));

        $this->assertSame($existing->id, $retried->id);
        $this->assertSame($transactionCount, TicketTransaction::query()->count());
        $this->assertSame($balanceBeforeRetry, (int) $wallet->fresh()->balance);
        $this->assertSame(1, TicketTransaction::query()->where('dedupe_key', $dedupeKey)->count());
    }

    /** @return array{TicketWallet, Reservation, Reservation} */
    private function lastTicketFixture(string $key): array
    {
        $customer = Customer::factory()->create();
        $wallet = $this->walletWithBalance($customer, 1, $key);
        $first = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $second = Reservation::factory()->create(['customer_id' => $customer->user_id]);

        return [$wallet, $first, $second];
    }

    private function walletWithBalance(Customer $customer, int $balance, string $key): TicketWallet
    {
        $product = TicketProduct::factory()->create(['total_count' => $balance]);
        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => $balance,
            'balance' => 0,
            'expires_at' => '2026-12-31',
            'status' => TicketWalletStatus::Active,
        ]);

        $this->ledger()->append(
            wallet: $wallet,
            type: TicketTransactionType::Grant,
            delta: $balance,
            dedupeKey: "grant:{$key}",
        );

        return $wallet;
    }

    private function assertSingleHoldOutcome(TicketWallet $wallet, Reservation $successful): void
    {
        $this->assertSame(1, TicketReservationUsage::query()->count());
        $this->assertDatabaseHas('ticket_reservation_usages', [
            'reservation_id' => $successful->id,
            'ticket_wallet_id' => $wallet->id,
            'status' => TicketReservationUsageStatus::Held->value,
        ]);
        $this->assertSame(1, TicketTransaction::query()
            ->where('type', TicketTransactionType::ReserveHold->value)
            ->count());
        $this->assertSame(0, $this->ledger()->available($wallet));
        $this->assertSame(0, (int) $wallet->fresh()->balance);
        $this->assertNonNegativeHistory($wallet);
    }

    private function assertNonNegativeHistory(TicketWallet $wallet): void
    {
        foreach ($this->runningBalances($wallet) as $balance) {
            $this->assertGreaterThanOrEqual(0, $balance);
        }
    }

    /** @return list<int> */
    private function runningBalances(TicketWallet $wallet): array
    {
        $balance = 0;

        return TicketTransaction::query()
            ->where('ticket_wallet_id', $wallet->id)
            ->orderBy('id')
            ->pluck('delta')
            ->map(function (mixed $delta) use (&$balance): int {
                $balance += (int) $delta;

                return $balance;
            })
            ->all();
    }

    private function assertLockWaitTimeout(QueryException $exception): void
    {
        $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private function onSecondConnection(Closure $callback): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_second');

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function reservations(): TicketReservationService
    {
        return app(TicketReservationService::class);
    }

    private function ledger(): TicketLedgerService
    {
        return app(TicketLedgerService::class);
    }

    private function rollBackOpenTransactions(?Connection $connection): void
    {
        if ($connection === null) {
            return;
        }

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}
