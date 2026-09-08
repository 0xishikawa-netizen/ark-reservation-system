<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Booth;
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
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class ReservationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_customer_cannot_view_update_or_delete_another_customers_reservation(): void
    {
        $customerA = $this->roleCustomer();
        $customerB = $this->roleCustomer();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservationB = $this->createCustomerReservation(
            $customerB,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );

        $this->actingAs($customerA->user)
            ->get("/mypage/reservations/{$reservationB->id}")
            ->assertForbidden();
        $this->actingAs($customerA->user)
            ->put("/mypage/reservations/{$reservationB->id}", [
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 12:00:00',
                'version' => 0,
            ])
            ->assertForbidden();
        $this->actingAs($customerA->user)
            ->delete("/mypage/reservations/{$reservationB->id}")
            ->assertForbidden();

        $this->assertSame(ReservationStatus::Confirmed, $reservationB->fresh()?->status);
        $this->assertSame(0, $reservationB->fresh()?->version);
    }

    public function test_customer_can_view_reschedule_and_cancel_their_own_reservation(): void
    {
        $customer = $this->roleCustomer();
        [$service, $staff] = $this->bookableServiceAndStaff();
        $reservation = $this->createCustomerReservation(
            $customer,
            $service,
            $staff,
            '2026-10-01 10:00:00',
        );

        $this->actingAs($customer->user)
            ->get("/mypage/reservations/{$reservation->id}")
            ->assertOk();
        $this->actingAs($customer->user)
            ->put("/mypage/reservations/{$reservation->id}", [
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 12:00:00',
                'version' => 0,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.reservations.show', $reservation));

        $reservation->refresh();
        $this->assertSame(1, $reservation->version);
        $this->assertSame('2026-10-01 12:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));

        $this->actingAs($customer->user)
            ->delete("/mypage/reservations/{$reservation->id}")
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mypage.reservations.index'));

        $this->assertSame(ReservationStatus::Canceled, $reservation->fresh()?->status);
        $this->assertSame(0, $reservation->resourceSlots()->count());
    }

    public function test_staff_can_view_schedule_but_cannot_manage_reservations(): void
    {
        $staffUser = $this->roleUser('staff');
        $reservation = Reservation::factory()->create();

        $this->actingAs($staffUser)
            ->get('/admin/schedule?date=2026-10-01')
            ->assertOk();
        $this->actingAs($staffUser)
            ->get('/admin/reservations/create')
            ->assertForbidden();
        $this->actingAs($staffUser)
            ->post('/admin/reservations')
            ->assertForbidden();
        $this->actingAs($staffUser)
            ->patch("/admin/reservations/{$reservation->id}/cancel")
            ->assertForbidden();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()?->status);
    }

    public function test_manager_and_admin_can_view_create_and_cancel_reservations(): void
    {
        foreach (['manager', 'admin'] as $index => $role) {
            $actor = $this->roleUser($role);
            [$customer, $service, $staff, $booth] = $this->adminMasters($role);
            $startsAt = sprintf('2026-10-01 %02d:00:00', 10 + ($index * 2));

            $this->actingAs($actor)
                ->get('/admin/schedule?date=2026-10-01')
                ->assertOk();
            $this->actingAs($actor)
                ->get('/admin/reservations/create')
                ->assertOk();
            $this->actingAs($actor)
                ->post('/admin/reservations', [
                    'customer_id' => $customer->user_id,
                    'service_id' => $service->id,
                    'staff_id' => $staff->user_id,
                    'booth_id' => $booth->id,
                    'starts_at' => $startsAt,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('admin.schedule.index', ['date' => '2026-10-01']));

            $reservation = Reservation::query()
                ->where('customer_id', $customer->user_id)
                ->firstOrFail();

            $this->actingAs($actor)
                ->patch("/admin/reservations/{$reservation->id}/cancel")
                ->assertSessionHasNoErrors()
                ->assertRedirect();
            $this->assertSame(ReservationStatus::Canceled, $reservation->fresh()?->status);
        }
    }

    public function test_customer_role_has_no_gate_before_permission_bypass(): void
    {
        $customer = $this->roleCustomer();

        $this->assertFalse(Gate::forUser($customer->user)->allows('reservations.view'));
        $this->assertFalse(Gate::forUser($customer->user)->allows('reservations.manage'));
        $this->assertFalse(Gate::forUser($customer->user)->allows('admin.access'));
    }

    private function roleCustomer(): Customer
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        return $customer;
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
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
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$service, $staff];
    }

    /** @return array{Customer, Service, Staff, Booth} */
    private function adminMasters(string $suffix): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'name' => "{$suffix}-authorization-service",
            'duration_min' => 60,
            'is_active' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff, $booth];
    }

    private function createCustomerReservation(
        Customer $customer,
        Service $service,
        Staff $staff,
        string $startsAt,
    ): Reservation {
        return app(ReservationService::class)->create(new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse($startsAt),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
        ));
    }
}
