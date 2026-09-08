<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Enums\Reservation\ResourceType;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReservationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        config()->set('reservation.allow_admin_free_time', false);
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_customer_non_boundary_start_is_rejected_with_422(): void
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 45,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);

        $this->actingAs($customer->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 10:07:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_admin_non_boundary_start_is_rejected_when_free_time_is_disabled(): void
    {
        [$manager, $customer, $service, $booth] = $this->adminFixture();

        $this->actingAs($manager)
            ->postJson('/admin/reservations', $this->adminPayload($customer, $service, $booth))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_admin_free_time_occupies_floor_to_ceil_slot_key_boundaries(): void
    {
        config()->set('reservation.allow_admin_free_time', true);
        [$manager, $customer, $service, $booth] = $this->adminFixture();

        $this->actingAs($manager)
            ->post('/admin/reservations', $this->adminPayload($customer, $service, $booth))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.schedule.index', ['date' => '2026-10-01']));

        $reservation = Reservation::query()->firstOrFail();
        $this->assertSame('2026-10-01 10:07:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 10:52:00', $reservation->ends_at->format('Y-m-d H:i:s'));

        $slotStarts = DB::table('reservation_resource_slots')
            ->where('reservation_id', $reservation->id)
            ->where('resource_type', ResourceType::Booth->value)
            ->orderBy('slot_start')
            ->pluck('slot_start')
            ->map(static fn (mixed $slot): string => (string) $slot)
            ->all();

        $this->assertSame([
            '2026-10-01 10:00:00',
            '2026-10-01 10:15:00',
            '2026-10-01 10:30:00',
            '2026-10-01 10:45:00',
        ], $slotStarts);
        $this->assertDatabaseCount('reservation_resource_slots', 4);
    }

    /** @return array{User, Customer, Service, Booth} */
    private function adminFixture(): array
    {
        $manager = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $manager->assignRole('manager');
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 45,
            'is_active' => true,
            'is_online_bookable' => false,
            'requires_staff' => false,
        ]);
        $booth = Booth::factory()->create(['is_active' => true]);

        return [$manager, $customer, $service, $booth];
    }

    /** @return array<string, int|string|null> */
    private function adminPayload(Customer $customer, Service $service, Booth $booth): array
    {
        return [
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => null,
            'booth_id' => $booth->id,
            'starts_at' => '2026-10-01 10:07:00',
        ];
    }
}
