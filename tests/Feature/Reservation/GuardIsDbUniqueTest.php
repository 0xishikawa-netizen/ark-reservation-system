<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ResourceType;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GuardIsDbUniqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_db_unique_violation_becomes_domain_conflict_and_rolls_back_reservation(): void
    {
        [$holder, $contender, $service, $staff] = $this->fixtureWithManuallyOccupiedSlots();
        $reservationsBefore = Reservation::query()->count();

        try {
            app(ReservationService::class)->create($this->input($contender, $service, $staff));
            $this->fail('DB UNIQUE と競合する予約が作成されました。');
        } catch (SlotUnavailableException $exception) {
            $this->assertSame('指定の時間帯は既に予約されています', $exception->getMessage());
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(QueryException::class, $previous);
            $this->assertSame('23000', $previous->errorInfo[0] ?? null);
        }

        $this->assertSame($reservationsBefore, Reservation::query()->count());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_resource_slots', 4);
        $this->assertSame(4, $holder->resourceSlots()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'reservation.created']);
    }

    public function test_actual_http_booking_returns_409_instead_of_500_and_rolls_back(): void
    {
        [$holder, $contender, $service, $staff] = $this->fixtureWithManuallyOccupiedSlots();

        // staff 指名時は AvailabilityService を通らず、DB UNIQUE が最終防衛線になる。
        $this->actingAs($contender->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertConflict()
            ->assertJsonPath('errors.reservation.0', '指定の時間帯は既に予約されています');

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_resource_slots', 4);
        $this->assertSame(4, $holder->resourceSlots()->count());
    }

    /** @return array{Reservation, Customer, Service, Staff} */
    private function fixtureWithManuallyOccupiedSlots(): array
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

        $startsAt = CarbonImmutable::parse('2026-10-01 10:00:00');
        $endsAt = $startsAt->addHour();
        $holder = Reservation::factory()->create([
            'customer_id' => $holderCustomer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $createdAt = now();
        DB::table('reservation_resource_slots')->insert(array_map(
            static fn (CarbonImmutable $slot): array => [
                'resource_type' => ResourceType::Staff->value,
                'resource_id' => (int) $staff->user_id,
                'slot_start' => $slot,
                'reservation_id' => (int) $holder->id,
                'created_at' => $createdAt,
            ],
            SlotKey::fromSettings()->occupiedSlots($startsAt, $endsAt),
        ));

        return [$holder, $contender, $service, $staff];
    }

    private function input(Customer $customer, Service $service, Staff $staff): ReservationInput
    {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
        );
    }
}
