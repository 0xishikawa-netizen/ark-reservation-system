<?php

declare(strict_types=1);

namespace Tests\Feature\Visit;

use App\Domain\Visit\VisitCompletionService;
use App\Models\Customer;
use App\Models\Reservation;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VisitCompletionConcurrencyTest extends TestCase
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
            $this->markTestSkipped('MySQL前提の来店完了並行テスト');
        }
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        DB::purge('mysql_second');
        $this->primary = DB::connection($this->originalConnection);
        $this->second = DB::connection('mysql_second');
        $this->second->statement('SET SESSION innodb_lock_wait_timeout = 1');
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
        parent::tearDown();
    }

    public function test_two_workers_cannot_complete_the_same_reservation_twice(): void
    {
        $reservation = Reservation::factory()->create();
        $this->primary?->beginTransaction();
        $first = app(VisitCompletionService::class)->completeReservation($reservation);

        $blocked = false;
        DB::setDefaultConnection('mysql_second');
        try {
            app(VisitCompletionService::class)->completeReservation($reservation);
        } catch (QueryException|DeadlockException $exception) {
            $blocked = (int) ($exception->errorInfo[1] ?? 0) === 1205
                || str_contains(strtolower($exception->getMessage()), 'lock wait timeout');
        } finally {
            DB::setDefaultConnection($this->originalConnection);
        }

        $this->assertTrue($blocked, '後発workerが未commitの完了処理を追い越しました。');
        $this->primary?->commit();

        DB::setDefaultConnection('mysql_second');
        $retry = app(VisitCompletionService::class)->completeReservation($reservation);
        DB::setDefaultConnection($this->originalConnection);

        $this->assertSame($first->visit->id, $retry->visit->id);
        $this->assertSame(1, DB::table('visits')->where('reservation_id', $reservation->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'reservation.completed')->where('entity_id', (string) $reservation->id)->count());
    }

    public function test_customer_lock_serializes_sequence_for_two_different_reservations(): void
    {
        $customer = Customer::factory()->create();
        $firstReservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $secondReservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $this->primary?->beginTransaction();
        $first = app(VisitCompletionService::class)->completeReservation($firstReservation);

        $blocked = false;
        DB::setDefaultConnection('mysql_second');
        try {
            app(VisitCompletionService::class)->completeReservation($secondReservation);
        } catch (QueryException|DeadlockException $exception) {
            $blocked = (int) ($exception->errorInfo[1] ?? 0) === 1205
                || str_contains(strtolower($exception->getMessage()), 'lock wait timeout');
        } finally {
            DB::setDefaultConnection($this->originalConnection);
        }

        $this->assertTrue($blocked, '同一顧客の後発完了が来店順採番を追い越しました。');
        $this->primary?->commit();

        DB::setDefaultConnection('mysql_second');
        $second = app(VisitCompletionService::class)->completeReservation($secondReservation);
        DB::setDefaultConnection($this->originalConnection);

        $this->assertSame(1, $first->visit->visit_sequence);
        $this->assertSame(2, $second->visit->visit_sequence);
        $this->assertSame(2, DB::table('visits')->where('customer_id', $customer->user_id)->distinct()->count('visit_sequence'));
    }
}
