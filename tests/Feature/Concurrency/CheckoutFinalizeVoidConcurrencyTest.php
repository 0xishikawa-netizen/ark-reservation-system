<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Accounting\CheckoutService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 同一会計の確定・取消へ2 workerが同時に入っても、回数券付与／取消と監査が一組だけに
 * 収束し、利用可能な回数券が残る事故を防ぐ真の2接続テスト。
 */
final class CheckoutFinalizeVoidConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $originalConnection = 'mysql';

    private ?Connection $primary = null;

    private ?Connection $second = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL前提の会計確定・取消並行テスト');
        }
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        DB::purge('mysql_second');
        $this->primary = DB::connection($this->originalConnection);
        $this->second = DB::connection('mysql_second');
        $this->second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00'));
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalConnection);
        foreach ([$this->second, $this->primary] as $connection) {
            while ($connection !== null && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        if ($this->second !== null) {
            DB::disconnect('mysql_second');
        }
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 事故防止: 確定と取消の未commit中に別workerが追い越さず、GRANT/REVOKEと監査を二重作成しない。
     */
    public function test_interleaved_finalize_and_void_converge_to_one_grant_and_one_revoke(): void
    {
        [$checkout, $actor] = $this->ticketCheckout();
        $primary = $this->primary;
        $this->assertNotNull($primary);

        $primary->beginTransaction();
        try {
            app(CheckoutService::class)->finalize($checkout, $actor);
            $this->assertSecondWorkerIsBlocked(
                fn (): Checkout => app(CheckoutService::class)->finalize($checkout, $actor),
                '未commitの会計確定を後発workerが追い越しました。',
            );
            $primary->commit();
        } finally {
            $this->rollBackOpenTransaction($primary);
        }

        $this->onSecondConnection(
            fn (): Checkout => app(CheckoutService::class)->finalize($checkout, $actor),
        );
        $wallet = TicketWallet::query()->sole();
        $this->assertSame(1, TicketTransaction::query()->where('type', TicketTransactionType::Grant->value)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.finalized')
            ->where('entity_id', (string) $checkout->id)->count());

        $primary->beginTransaction();
        try {
            app(CheckoutService::class)->void($checkout, '入力取消', $actor);
            $this->assertSecondWorkerIsBlocked(
                fn (): Checkout => app(CheckoutService::class)->void($checkout, '重複取消', $actor),
                '未commitの会計取消を後発workerが追い越しました。',
            );
            $primary->commit();
        } finally {
            $this->rollBackOpenTransaction($primary);
        }

        $this->onSecondConnection(
            fn (): Checkout => app(CheckoutService::class)->void($checkout, '再送取消', $actor),
        );

        $this->assertSame(CheckoutStatus::Voided, $checkout->refresh()->status);
        $this->assertSame(0, (int) $wallet->refresh()->balance);
        $this->assertSame(1, TicketTransaction::query()->where('type', TicketTransactionType::Revoke->value)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.voided')
            ->where('entity_id', (string) $checkout->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ticket.revoked')
            ->where('entity_id', (string) $wallet->id)->count());
    }

    /** @return array{Checkout, User} */
    private function ticketCheckout(): array
    {
        $customer = Customer::factory()->create();
        $actor = User::factory()->create();
        $product = TicketProduct::factory()->create(['total_count' => 5, 'price' => 33000]);
        $checkout = Checkout::factory()->create([
            'visit_id' => null,
            'customer_id' => $customer->user_id,
            'sale_date' => '2026-10-07',
            'status' => CheckoutStatus::Draft,
            'subtotal_amount' => 33000,
            'tax_amount' => 0,
            'total_amount' => 33000,
        ]);
        CheckoutLine::factory()->create([
            'checkout_id' => $checkout->id,
            'item_type' => 'ticket',
            'ticket_product_id' => $product->id,
            'item_name_snapshot' => $product->name,
            'quantity' => 1,
            'unit_amount' => 33000,
            'net_amount' => 33000,
            'tax_amount' => 0,
            'gross_amount' => 33000,
            'is_staff_allocatable' => false,
        ]);
        CheckoutTender::factory()->create([
            'checkout_id' => $checkout->id,
            'amount' => 33000,
            'received_at' => '2026-10-07 03:00:00',
        ]);

        return [$checkout, $actor];
    }

    /** @param callable(): Checkout $operation */
    private function assertSecondWorkerIsBlocked(callable $operation, string $message): void
    {
        try {
            $this->onSecondConnection($operation);
            $this->fail($message);
        } catch (QueryException|DeadlockException $exception) {
            $this->assertTrue(
                (int) ($exception->errorInfo[1] ?? 0) === 1205
                    || str_contains(strtolower($exception->getMessage()), 'lock wait timeout'),
                '期待した行ロック待ちタイムアウトではありません。',
            );
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function onSecondConnection(callable $operation): mixed
    {
        DB::setDefaultConnection('mysql_second');
        try {
            return $operation();
        } finally {
            DB::setDefaultConnection($this->originalConnection);
        }
    }

    private function rollBackOpenTransaction(Connection $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}
