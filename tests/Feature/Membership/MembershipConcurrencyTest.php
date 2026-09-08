<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipReservationService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MembershipConcurrencyTest extends TestCase
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
            $this->markTestSkipped('MySQL 前提の利用権並行テスト');
        }

        $mysqlConfig = config('database.connections.mysql');
        $this->assertIsArray($mysqlConfig);

        config(['database.connections.mysql_second' => $mysqlConfig]);
        DB::purge('mysql_second');

        $this->primaryConnection = DB::connection($this->originalDefaultConnection);
        $this->secondConnection = DB::connection('mysql_second');
        $this->secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);
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

    public function test_two_concurrent_reservations_for_the_last_usage_allow_exactly_one_success(): void
    {
        [$customer, $membership, $first, $contenderInput] = $this->lastUsageFixture('uncommitted');
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $this->reservations()->reserve($first);

            try {
                $this->onSecondConnection(
                    fn (): Reservation => $this->reservationService()->create($contenderInput),
                );
                $this->fail('接続 A が最後の1回を未コミットで保持中に接続 B の予約が成功しました。');
            } catch (QueryException|DeadlockException $exception) {
                $this->assertLockWaitTimeout($exception);
            }

            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $this->assertSingleReserveOutcome($membership, $first);
        $this->assertSame((int) $customer->user_id, (int) $membership->customer_id);
    }

    public function test_reservation_after_the_last_usage_is_committed_fails_with_conflict(): void
    {
        [, $membership, $first, $contenderInput] = $this->lastUsageFixture('committed');
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $this->reservations()->reserve($first);
            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        try {
            $this->onSecondConnection(
                fn (): Reservation => $this->reservationService()->create($contenderInput),
            );
            $this->fail('最後の1回が commit 済みなのに接続 B の利用権予約が成功しました。');
        } catch (InsufficientMembershipBalanceException $exception) {
            $this->assertSame('当期の利用可能回数が不足しています', $exception->getMessage());
        }

        $this->assertSingleReserveOutcome($membership, $first);
    }

    public function test_reserve_retry_for_the_same_reservation_does_not_double_decrement(): void
    {
        $customer = Customer::factory()->create();
        $membership = $this->membershipWithBalance($customer, 1, 'reserve-retry');
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);

        $first = $this->reservations()->reserve($reservation);
        $retried = $this->reservations()->reserve($reservation);

        $this->assertSame($first->id, $retried->id);
        $this->assertSame(1, MembershipReservationUsage::query()->count());
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('dedupe_key', "reserve:{$reservation->id}")
            ->count());
        $this->assertSame(0, $this->ledger()->available($membership));
        $this->assertSame(0, (int) $membership->fresh()->period_available);
        $this->assertNonNegativeHistory($membership);
    }

    public function test_dedupe_unique_constraint_is_the_final_defence_across_connections(): void
    {
        $customer = Customer::factory()->create();
        $membership = $this->membershipWithBalance($customer, 1, 'dedupe-race');
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $dedupeKey = "reserve:{$reservation->id}";
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $primary->beginTransaction();

        try {
            $primary->table('membership_usage_transactions')->insert([
                'membership_id' => $membership->id,
                'period_start' => '2026-09-01',
                'type' => MembershipUsageType::Reserve->value,
                'delta' => -1,
                'reservation_id' => $reservation->id,
                'staff_id' => null,
                'reason' => null,
                'dedupe_key' => $dedupeKey,
                'created_at' => now(),
            ]);

            try {
                $this->onSecondConnection(fn (): MembershipUsageTransaction => $this->ledger()->append(
                    membership: $membership,
                    type: MembershipUsageType::Reserve,
                    delta: -1,
                    dedupeKey: $dedupeKey,
                    periodStart: '2026-09-01',
                    reservationId: (int) $reservation->id,
                ));
                $this->fail('未コミットの同一 dedupe_key に対して接続 B の append が成功しました。');
            } catch (QueryException|DeadlockException $exception) {
                $this->assertLockWaitTimeout($exception);
            }

            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $existing = MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        $transactionCount = MembershipUsageTransaction::query()->count();
        $availableBeforeRetry = (int) $membership->fresh()->period_available;

        try {
            $this->onSecondConnection(function () use ($membership, $reservation, $dedupeKey): void {
                DB::table('membership_usage_transactions')->insert([
                    'membership_id' => $membership->id,
                    'period_start' => '2026-09-01',
                    'type' => MembershipUsageType::Reserve->value,
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

        $retried = $this->onSecondConnection(fn (): MembershipUsageTransaction => $this->ledger()->append(
            membership: $membership,
            type: MembershipUsageType::Reserve,
            delta: -1,
            dedupeKey: $dedupeKey,
            periodStart: '2026-09-01',
            reservationId: (int) $reservation->id,
        ));

        $this->assertSame($existing->id, $retried->id);
        $this->assertSame($transactionCount, MembershipUsageTransaction::query()->count());
        $this->assertSame($availableBeforeRetry, (int) $membership->fresh()->period_available);
        $this->assertSame(1, MembershipUsageTransaction::query()->where('dedupe_key', $dedupeKey)->count());
    }

    public function test_scheduler_and_webhook_grant_converge_to_one_transaction(): void
    {
        $membership = Membership::factory()->create([
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);

        // invoice.paid 側が先行した直後に scheduler が同じ当期を処理する状況。
        $this->ledger()->grant($membership, '2026-09-01', 4, 'invoice in_concurrency');
        $this->assertSame(0, Artisan::call('memberships:grant-current'));

        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('period_start', '2026-09-01')
            ->where('type', MembershipUsageType::Grant->value)
            ->count());
        $this->assertSame(4, $this->ledger()->available($membership));
        $this->assertSame(4, (int) $membership->fresh()->period_available);
    }

    /**
     * Q-01: 「1 顧客 1 有効 membership」を守る唯一の砦は startSubscription TX1 の
     * `where(status != canceled)->lockForUpdate()->exists()`。並行で 2 本入ると別々の
     * membership_operation_id ＝別 Idempotency-Key で Stripe subscription が二重に作られる。
     * 既定の REPEATABLE READ では空集合への FOR UPDATE でも gap lock が効き、後発が待たされることを確認する。
     */
    public function test_concurrent_duplicate_subscription_check_is_serialised_by_gap_lock(): void
    {
        $customer = Customer::factory()->create();
        $plan = MembershipPlan::factory()->create();
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);

        $primary->beginTransaction();

        try {
            $exists = $primary->table('memberships')
                ->where('customer_id', $customer->user_id)
                ->where('status', '!=', 'canceled')
                ->lockForUpdate()
                ->exists();
            $this->assertFalse($exists);

            $primary->table('memberships')->insert([
                'customer_id' => $customer->user_id,
                'membership_plan_id' => $plan->id,
                'stripe_subscription_id' => null,
                'membership_operation_id' => (string) Str::uuid(),
                'pending_operation' => 'create',
                'status' => 'pending',
                'period_available' => 0,
                'cancel_at_period_end' => false,
                'needs_attention' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $this->onSecondConnection(fn (): bool => DB::table('memberships')
                    ->where('customer_id', $customer->user_id)
                    ->where('status', '!=', 'canceled')
                    ->lockForUpdate()
                    ->exists());
                $this->fail('後発コネクションの重複検査が待たされませんでした（二重 subscription の余地）。');
            } catch (QueryException|DeadlockException $exception) {
                $this->assertLockWaitTimeout($exception);
            }

            $primary->commit();
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $this->assertSame(1, Membership::query()->where('customer_id', $customer->user_id)->count());
    }

    /** @return array{Customer, Membership, Reservation, ReservationInput} */
    private function lastUsageFixture(string $key): array
    {
        [$customer, $service, $staff] = $this->reservationMasters();
        $membership = $this->membershipWithBalance($customer, 1, $key);
        $first = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => CarbonImmutable::parse('2026-10-01 10:00:00'),
            'ends_at' => CarbonImmutable::parse('2026-10-01 11:00:00'),
            'payment_method' => PaymentMethod::Membership,
        ]);

        return [
            $customer,
            $membership,
            $first,
            $this->input($customer, $service, $staff, CarbonImmutable::parse('2026-10-01 12:00:00')),
        ];
    }

    private function membershipWithBalance(Customer $customer, int $balance, string $key): Membership
    {
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
        $this->ledger()->grant($membership, '2026-09-01', $balance, "test:{$key}");

        return $membership;
    }

    /** @return array{Customer, Service, Staff} */
    private function reservationMasters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }

    private function input(
        Customer $customer,
        Service $service,
        Staff $staff,
        CarbonImmutable $startsAt,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: $startsAt,
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: PaymentMethod::Membership,
        );
    }

    private function assertSingleReserveOutcome(Membership $membership, Reservation $successful): void
    {
        $this->assertSame(1, MembershipReservationUsage::query()->count());
        $this->assertDatabaseHas('membership_reservation_usages', [
            'reservation_id' => $successful->id,
            'membership_id' => $membership->id,
            'status' => MembershipReservationUsageStatus::Reserved->value,
        ]);
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('type', MembershipUsageType::Reserve->value)
            ->count());
        $this->assertSame(0, $this->ledger()->available($membership));
        $this->assertSame(0, (int) $membership->fresh()->period_available);
        $this->assertNonNegativeHistory($membership);
    }

    private function assertNonNegativeHistory(Membership $membership): void
    {
        $balance = 0;

        foreach (MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->orderBy('id')
            ->pluck('delta') as $delta) {
            $balance += (int) $delta;
            $this->assertGreaterThanOrEqual(0, $balance);
        }
    }

    private function assertLockWaitTimeout(QueryException|DeadlockException $exception): void
    {
        $queryException = $exception instanceof DeadlockException
            ? $exception->getPrevious()
            : $exception;
        $this->assertInstanceOf(QueryException::class, $queryException);
        $this->assertSame(1205, (int) ($queryException->errorInfo[1] ?? 0));
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

    private function reservations(): MembershipReservationService
    {
        return app(MembershipReservationService::class);
    }

    private function reservationService(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function ledger(): MembershipLedgerService
    {
        return app(MembershipLedgerService::class);
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
