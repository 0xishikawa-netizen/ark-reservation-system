<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReservationBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        $settings = app(Settings::class);
        $settings->set('reservation.slot_minutes', 15, 'int');
        $settings->set('business_hours.open', '10:00');
        $settings->set('business_hours.close', '18:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_customer_can_open_reserve_with_only_staff_required_online_services(): void
    {
        $customer = Customer::factory()->create();
        [$onlineService, $staff] = $this->bookableServiceAndStaff();
        $offlineService = Service::factory()->create(['is_online_bookable' => false]);
        $staffOptionalService = Service::factory()->create(['requires_staff' => false]);

        $this->actingAs($customer->user)
            ->get('/reserve')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Reserve/Index')
                ->has('services', 1)
                ->where('services.0.id', $onlineService->id)
                ->where('services.0.staff.0.id', $staff->user_id)
                ->missing('services.1'));

        $this->assertNotSame($onlineService->id, $offlineService->id);
        $this->assertNotSame($onlineService->id, $staffOptionalService->id);
    }

    public function test_availability_endpoint_returns_start_times_as_json(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff);

        $this->actingAs($customer->user)
            ->getJson('/reserve/availability?'.http_build_query([
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'date' => '2026-10-01',
            ]))
            ->assertOk()
            ->assertJsonPath('0.starts_at', '2026-10-01 10:00:00')
            ->assertJsonPath('0.ends_at', '2026-10-01 11:00:00');
    }

    public function test_customer_can_book_with_a_selected_staff_member(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $this->shift($staff);

        $response = $this->actingAs($customer->user)->post('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
        ]);

        $reservation = Reservation::query()->firstOrFail();

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.reservations.show', $reservation));
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame($customer->user_id, $reservation->customer_id);
        $this->assertSame($staff->user_id, $reservation->staff_id);
        $this->assertSame(4, $reservation->resourceSlots()->count());
    }

    public function test_booking_without_staff_assigns_the_first_available_staff_id(): void
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['duration_min' => 60]);
        $staffA = Staff::factory()->create(['is_bookable' => true]);
        $staffB = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach([$staffA->user_id, $staffB->user_id]);
        $this->shift($staffA);
        $this->shift($staffB);

        $this->actingAs($customer->user)
            ->post('/reserve', [
                'service_id' => $service->id,
                'staff_id' => null,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertSessionHasNoErrors();

        $reservation = Reservation::query()->firstOrFail();

        $this->assertSame($staffA->user_id, $reservation->staff_id);
        $this->assertSame(4, $reservation->resourceSlots()->count());
    }

    public function test_booking_without_staff_returns_409_when_no_staff_is_available(): void
    {
        $customer = Customer::factory()->create();
        [$service] = $this->bookableServiceAndStaff();

        $this->actingAs($customer->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => null,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertConflict()
            ->assertJsonPath('errors.reservation.0', '指定の時間帯は既に予約されています');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_past_start_is_rejected_with_422(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();

        $this->actingAs($customer->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-08-01 10:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_non_boundary_start_is_rejected_with_422(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();

        $this->actingAs($customer->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 10:07:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_non_online_service_is_rejected_with_422(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $service->update(['is_online_bookable' => false]);

        $this->actingAs($customer->user)
            ->postJson('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');
    }

    public function test_eleventh_booking_attempt_is_rate_limited(): void
    {
        $customer = Customer::factory()->create();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($customer->user)
                ->postJson('/reserve', [])
                ->assertUnprocessable();
        }

        $this->actingAs($customer->user)
            ->postJson('/reserve', [])
            ->assertTooManyRequests();
    }

    public function test_staff_without_customer_record_cannot_book(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staffUser = User::factory()->create();
        $staffUser->assignRole('staff');

        $this->actingAs($staffUser)
            ->postJson('/reserve', [])
            ->assertForbidden();
    }

    /** @return array{Service, Staff} */
    private function bookableServiceAndStaff(): array
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);

        return [$service, $staff];
    }

    private function shift(Staff $staff): void
    {
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);
    }
}
