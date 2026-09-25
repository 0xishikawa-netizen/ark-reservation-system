<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessFacts;

use App\Models\RevenueRecognitionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RevenueRecognitionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?Connection $primary = null;

    private ?Connection $second = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL前提の収益配賦並行テスト');
        }
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        DB::purge('mysql_second');
        $this->primary = DB::connection();
        $this->second = DB::connection('mysql_second');
        $this->second->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
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

    public function test_contract_row_lock_serializes_concurrent_allocation_writers(): void
    {
        $contract = RevenueRecognitionContract::factory()->create();
        $this->primary?->beginTransaction();
        $this->primary?->table('revenue_recognition_contracts')->where('id', $contract->id)->lockForUpdate()->first();

        $blocked = false;
        try {
            $this->second?->table('revenue_recognition_contracts')->where('id', $contract->id)->lockForUpdate()->first();
        } catch (QueryException|DeadlockException $exception) {
            $blocked = (int) ($exception->errorInfo[1] ?? 0) === 1205
                || str_contains(strtolower($exception->getMessage()), 'lock wait timeout');
        }

        $this->assertTrue($blocked, '後発の配賦writerが同じ契約ロックを同時取得しました。');
        $this->primary?->commit();
    }
}
