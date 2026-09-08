<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Reservation\RescheduleInput;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReservationConcurrencyTest extends TestCase
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
            $this->markTestSkipped('MySQL 前提の並行テスト');
        }

        $mysqlConfig = config('database.connections.mysql');
        $this->assertIsArray($mysqlConfig);

        config(['database.connections.mysql_second' => $mysqlConfig]);
        DB::purge('mysql_second');

        $this->primaryConnection = DB::connection($this->originalDefaultConnection);
        $this->secondConnection = DB::connection('mysql_second');
        $this->secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.allow_admin_free_time', false);
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
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

    public function test_same_staff_and_same_time_allows_exactly_one_reservation(): void
    {
        [$holderCustomer, $contender, $service, $staff] = $this->staffFixture();
        $startsAt = CarbonImmutable::parse('2026-10-01 10:00:00');
        $endsAt = $startsAt->addHour();
        $holder = $this->holderReservation(
            $holderCustomer,
            $service,
            $staff,
            null,
            $startsAt,
            $endsAt,
        );

        $this->assertUncommittedSlotConflict(
            holder: $holder,
            resourceType: ResourceType::Staff,
            resourceId: (int) $staff->user_id,
            startsAt: $startsAt,
            endsAt: $endsAt,
            contenderInput: $this->input($contender, $service, $staff, null, $startsAt),
        );
    }

    public function test_same_booth_and_same_time_allows_exactly_one_reservation(): void
    {
        $holderCustomer = Customer::factory()->create();
        $contender = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => false,
        ]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $startsAt = CarbonImmutable::parse('2026-10-01 10:00:00');
        $endsAt = $startsAt->addHour();
        $holder = $this->holderReservation(
            $holderCustomer,
            $service,
            null,
            $booth,
            $startsAt,
            $endsAt,
        );

        $this->assertUncommittedSlotConflict(
            holder: $holder,
            resourceType: ResourceType::Booth,
            resourceId: (int) $booth->id,
            startsAt: $startsAt,
            endsAt: $endsAt,
            contenderInput: $this->input($contender, $service, null, $booth, $startsAt),
        );
    }

    public function test_overlapping_times_with_different_starts_allow_exactly_one_reservation(): void
    {
        [$holderCustomer, $contender, $service, $staff] = $this->staffFixture();
        $holderStartsAt = CarbonImmutable::parse('2026-10-01 10:00:00');
        $holderEndsAt = $holderStartsAt->addHour();
        $contenderStartsAt = CarbonImmutable::parse('2026-10-01 10:30:00');
        $holder = $this->holderReservation(
            $holderCustomer,
            $service,
            $staff,
            null,
            $holderStartsAt,
            $holderEndsAt,
        );

        $this->assertUncommittedSlotConflict(
            holder: $holder,
            resourceType: ResourceType::Staff,
            resourceId: (int) $staff->user_id,
            startsAt: $holderStartsAt,
            endsAt: $holderEndsAt,
            contenderInput: $this->input(
                $contender,
                $service,
                $staff,
                null,
                $contenderStartsAt,
            ),
        );
    }

    public function test_adjacent_reservations_both_succeed_without_slot_intersection(): void
    {
        [$firstCustomer, $secondCustomer, $service, $staff] = $this->staffFixture();
        $first = $this->reservationService()->create($this->input(
            $firstCustomer,
            $service,
            $staff,
            null,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        ));
        $second = $this->reservationService()->create($this->input(
            $secondCustomer,
            $service,
            $staff,
            null,
            CarbonImmutable::parse('2026-10-01 11:00:00'),
        ));

        $firstSlots = $this->slotStartsFor($first);
        $secondSlots = $this->slotStartsFor($second);

        $this->assertDatabaseCount('reservations', 2);
        $this->assertCount(4, $firstSlots);
        $this->assertCount(4, $secondSlots);
        $this->assertSame([], array_values(array_intersect($firstSlots, $secondSlots)));
    }

    public function test_canceled_reservation_releases_slots_for_reuse(): void
    {
        [$firstCustomer, $secondCustomer, $service, $staff] = $this->staffFixture();
        $startsAt = CarbonImmutable::parse('2026-10-01 10:00:00');
        $first = $this->reservationService()->create(
            $this->input($firstCustomer, $service, $staff, null, $startsAt),
        );

        $this->reservationService()->cancel($first, '並行テストでの再利用確認', $firstCustomer->user);
        $second = $this->reservationService()->create(
            $this->input($secondCustomer, $service, $staff, null, $startsAt),
        );

        $this->assertSame(ReservationStatus::Canceled, $first->fresh()?->status);
        $this->assertSame(0, $first->resourceSlots()->count());
        $this->assertSame(ReservationStatus::Confirmed, $second->status);
        $this->assertSame([
            '2026-10-01 10:00:00',
            '2026-10-01 10:15:00',
            '2026-10-01 10:30:00',
            '2026-10-01 10:45:00',
        ], $this->slotStartsFor($second));
    }

    public function test_reschedule_releases_old_slots_reserves_new_slots_and_increments_version(): void
    {
        [$firstCustomer, $secondCustomer, $service, $staff] = $this->staffFixture();
        $first = $this->reservationService()->create($this->input(
            $firstCustomer,
            $service,
            $staff,
            null,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        ));

        $first = $this->reservationService()->reschedule(new RescheduleInput(
            reservationId: (int) $first->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 14:00:00'),
            expectedVersion: 0,
            actorUserId: (int) $firstCustomer->user_id,
            adminContext: false,
        ));

        $this->assertSame(1, $first->version);
        $this->assertSame([
            '2026-10-01 14:00:00',
            '2026-10-01 14:15:00',
            '2026-10-01 14:30:00',
            '2026-10-01 14:45:00',
        ], $this->slotStartsFor($first));
        $this->assertSame(0, DB::table('reservation_resource_slots')
            ->where('reservation_id', $first->id)
            ->whereBetween('slot_start', [
                '2026-10-01 10:00:00',
                '2026-10-01 10:59:59',
            ])
            ->count());

        $second = $this->reservationService()->create($this->input(
            $secondCustomer,
            $service,
            $staff,
            null,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        ));

        $this->assertSame(ReservationStatus::Confirmed, $second->status);
        $this->assertSame(4, $second->resourceSlots()->count());
        $this->assertDatabaseCount('reservations', 2);
    }

    private function assertUncommittedSlotConflict(
        Reservation $holder,
        ResourceType $resourceType,
        int $resourceId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ReservationInput $contenderInput,
    ): void {
        $primary = $this->primaryConnection;
        $this->assertNotNull($primary);
        $slots = SlotKey::fromSettings()->occupiedSlots($startsAt, $endsAt);
        $primary->beginTransaction();

        try {
            $createdAt = now();
            $primary->table('reservation_resource_slots')->insert(array_map(
                static fn (CarbonImmutable $slot): array => [
                    'resource_type' => $resourceType->value,
                    'resource_id' => $resourceId,
                    'slot_start' => $slot,
                    'reservation_id' => (int) $holder->id,
                    'created_at' => $createdAt,
                ],
                $slots,
            ));

            try {
                $this->onSecondConnection(
                    fn (): Reservation => $this->reservationService()->create($contenderInput),
                );
                $this->fail('未コミットの競合 slot がある間に接続 B の予約が成功しました。');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            }

            $primary->commit();

            try {
                $this->onSecondConnection(
                    fn (): Reservation => $this->reservationService()->create($contenderInput),
                );
                $this->fail('接続 A の commit 後に競合予約が作成されました。');
            } catch (SlotUnavailableException $exception) {
                $previous = $exception->getPrevious();
                $this->assertInstanceOf(QueryException::class, $previous);
                $this->assertSame('23000', $previous->errorInfo[0] ?? null);
                $this->assertSame(1062, (int) ($previous->errorInfo[1] ?? 0));
            }
        } finally {
            $this->rollBackOpenTransactions($primary);
        }

        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(count($slots), DB::table('reservation_resource_slots')
            ->where('resource_type', $resourceType->value)
            ->where('resource_id', $resourceId)
            ->count());
        $this->assertSame(1, DB::table('reservation_resource_slots')
            ->where('resource_type', $resourceType->value)
            ->where('resource_id', $resourceId)
            ->distinct()
            ->count('reservation_id'));
        $this->assertSame(count($slots), $holder->resourceSlots()->count());
    }

    /**
     * @template TValue
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

    /** @return array{Customer, Customer, Service, Staff} */
    private function staffFixture(): array
    {
        $holderCustomer = Customer::factory()->create();
        $contender = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$holderCustomer, $contender, $service, $staff];
    }

    private function holderReservation(
        Customer $customer,
        Service $service,
        ?Staff $staff,
        ?Booth $booth,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): Reservation {
        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff?->user_id,
            'booth_id' => $booth?->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    private function input(
        Customer $customer,
        Service $service,
        ?Staff $staff,
        ?Booth $booth,
        CarbonImmutable $startsAt,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: $staff === null ? null : (int) $staff->user_id,
            boothId: $booth === null ? null : (int) $booth->id,
            startsAt: $startsAt,
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
        );
    }

    /** @return list<string> */
    private function slotStartsFor(Reservation $reservation): array
    {
        return DB::table('reservation_resource_slots')
            ->where('reservation_id', $reservation->id)
            ->orderBy('slot_start')
            ->pluck('slot_start')
            ->map(static fn (mixed $slot): string => (string) $slot)
            ->all();
    }

    private function reservationService(): ReservationService
    {
        return app(ReservationService::class);
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
