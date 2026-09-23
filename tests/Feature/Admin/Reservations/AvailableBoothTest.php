<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Domain\Reservation\AvailabilityService;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Schedule\ScheduleBlockType;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffScheduleBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AvailableBoothTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_returns_the_first_active_booth_with_no_conflict(): void
    {
        $service = Service::factory()->create(['duration_min' => 60, 'is_active' => true]);
        $boothA = Booth::factory()->create(['is_active' => true, 'sort_order' => 1]);
        Booth::factory()->create(['is_active' => true, 'sort_order' => 2]);

        $result = app(AvailabilityService::class)->firstAvailableBooth(
            $service->id,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        );

        $this->assertSame($boothA->id, $result);
    }

    public function test_skips_a_booth_already_reserved_at_that_time(): void
    {
        $service = Service::factory()->create(['duration_min' => 60, 'is_active' => true]);
        $boothA = Booth::factory()->create(['is_active' => true, 'sort_order' => 1]);
        $boothB = Booth::factory()->create(['is_active' => true, 'sort_order' => 2]);
        Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $service->id,
            'booth_id' => $boothA->id,
            'staff_id' => null,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $result = app(AvailabilityService::class)->firstAvailableBooth(
            $service->id,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        );

        $this->assertSame($boothB->id, $result);
    }

    public function test_skips_a_booth_blocked_at_that_time(): void
    {
        $service = Service::factory()->create(['duration_min' => 60, 'is_active' => true]);
        $boothA = Booth::factory()->create(['is_active' => true, 'sort_order' => 1]);
        $boothB = Booth::factory()->create(['is_active' => true, 'sort_order' => 2]);
        StaffScheduleBlock::factory()->create([
            'staff_id' => null,
            'booth_id' => $boothA->id,
            'work_date' => '2026-10-01',
            'start_at' => '09:30:00',
            'end_at' => '10:30:00',
            'type' => ScheduleBlockType::Cleaning,
        ]);

        $result = app(AvailabilityService::class)->firstAvailableBooth(
            $service->id,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        );

        $this->assertSame($boothB->id, $result);
    }

    public function test_returns_null_when_no_booth_is_free(): void
    {
        $service = Service::factory()->create(['duration_min' => 60, 'is_active' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $service->id,
            'booth_id' => $booth->id,
            'staff_id' => null,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $result = app(AvailabilityService::class)->firstAvailableBooth(
            $service->id,
            CarbonImmutable::parse('2026-10-01 10:00:00'),
        );

        $this->assertNull($result);
    }

    public function test_endpoint_requires_reservations_manage_permission(): void
    {
        $service = Service::factory()->create(['duration_min' => 60, 'is_active' => true]);
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->assignRole('staff');

        $this->actingAs($viewer)
            ->getJson("/admin/reservations/available-booth?service_id={$service->id}&starts_at=2026-10-01 10:00:00")
            ->assertForbidden();
    }

    public function test_endpoint_returns_the_booth_id_for_a_manager(): void
    {
        $service = Service::factory()->create(['duration_min' => 30, 'is_active' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->getJson("/admin/reservations/available-booth?service_id={$service->id}&starts_at=2026-10-01 10:00:00")
            ->assertOk()
            ->assertJson(['booth_id' => $booth->id]);
    }
}
