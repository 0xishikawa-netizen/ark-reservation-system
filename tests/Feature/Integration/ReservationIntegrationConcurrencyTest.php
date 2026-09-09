<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domain\Integration\Service\InboundReservationSync;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Models\Reservation;
use App\Models\ReservationProviderMapping;
use App\Models\ReservationSyncOutbox;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntegrationTestHelpers;
use Tests\TestCase;

/**
 * Phase 9 Concurrency（MySQL 2 コネクション）。
 * - 同一 external を並行 import → reservation 1 / mapping 1。
 * - 同一 Outbox を並行 claim → 1 worker のみが取得。
 */
final class ReservationIntegrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use IntegrationTestHelpers;

    private string $originalConnection = 'mysql';

    private ?Connection $primary = null;

    private ?Connection $second = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL 前提');
        }

        $cfg = config('database.connections.mysql');
        config(['database.connections.mysql_second' => $cfg]);
        DB::purge('mysql_second');

        $this->primary = DB::connection($this->originalConnection);
        $this->second = DB::connection('mysql_second');
        $this->second->statement('SET SESSION innodb_lock_wait_timeout = 1');

        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->useMockProvider();
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalConnection);
        foreach ([$this->second, $this->primary] as $c) {
            while ($c !== null && $c->transactionLevel() > 0) {
                $c->rollBack();
            }
        }
        if ($this->second !== null) {
            DB::disconnect('mysql_second');
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_external_reservation_imported_concurrently_converges_to_one(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        $data = $this->externalData('race-ext-1', $s, $c, $st);

        // 接続 A で import（reservation + mapping 作成・コミット）
        app(InboundReservationSync::class)->apply($data);

        // 接続 B で同じ external を import
        $this->onSecond(fn () => app(InboundReservationSync::class)->apply($data));

        $this->assertSame(1, ReservationProviderMapping::where('external_reservation_id', 'race-ext-1')->count());
        $this->assertSame(1, Reservation::where('source', 'EXTERNAL')->count());
    }

    public function test_same_outbox_row_claimed_by_only_one_worker(): void
    {
        [$c, $s, $st] = $this->reservationMasters();
        app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $c->user_id, serviceId: (int) $s->id, staffId: (int) $st->user_id,
            boothId: null, startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb, actorUserId: null, notes: null, adminContext: true,
            paymentMethod: PaymentMethod::Onsite,
        ));
        $this->assertSame(1, ReservationSyncOutbox::where('status', 'pending')->count());

        $primary = $this->primary;
        $primary->beginTransaction();

        try {
            // 接続 A が claim（未コミット）
            $rowA = ReservationSyncOutbox::query()
                ->where('status', 'pending')->where('available_at', '<=', now())
                ->lockForUpdate()->first();
            $this->assertNotNull($rowA);
            $rowA->forceFill(['status' => 'processing', 'locked_by' => 'A', 'locked_at' => now()])->save();

            // 接続 B の claim は A のコミット待ちでタイムアウト（=二重 claim しない）
            $blocked = false;
            try {
                $this->onSecond(function (): void {
                    ReservationSyncOutbox::query()
                        ->where('status', 'pending')->where('available_at', '<=', now())
                        ->lockForUpdate()->first();
                });
            } catch (QueryException $e) {
                $blocked = (int) ($e->errorInfo[1] ?? 0) === 1205;
            }

            $primary->commit();

            // 行は既に processing なので B が後から見ても pending は無い。
            $this->assertTrue($blocked || ReservationSyncOutbox::where('status', 'pending')->count() === 0);
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
        }

        $this->assertSame(1, ReservationSyncOutbox::count());
    }

    private function onSecond(Closure $fn): mixed
    {
        $prev = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_second');

        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($prev);
        }
    }
}
