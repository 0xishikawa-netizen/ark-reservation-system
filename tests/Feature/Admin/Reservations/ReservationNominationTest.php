<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Queries\ScheduleQuery;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReservationNominationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '18:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_creating_a_reservation_with_nomination_persists_the_flag(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-create');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/reservations', [
                'customer_id' => $customer->user_id,
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'is_staff_requested' => true,
                'booth_id' => $booth->id,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation = Reservation::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertTrue($reservation->is_staff_requested);
    }

    public function test_creating_without_nomination_defaults_to_false(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-default');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/reservations', [
                'customer_id' => $customer->user_id,
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'booth_id' => $booth->id,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertSessionHasNoErrors();

        $reservation = Reservation::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertFalse($reservation->is_staff_requested);
    }

    public function test_nomination_cannot_be_true_without_a_staff(): void
    {
        [$customer, $service, , $booth] = $this->masters('nom-nostaff');
        $service->update(['requires_staff' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/reservations', [
                'customer_id' => $customer->user_id,
                'service_id' => $service->id,
                'is_staff_requested' => true,
                'booth_id' => $booth->id,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertSessionHasNoErrors();

        $reservation = Reservation::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertFalse($reservation->is_staff_requested);
    }

    public function test_editing_nomination_flag_alone_does_not_require_schedule_change(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-edit');
        $admin = $this->admin();
        $reservation = $this->createReservation($customer, $service, $staff, $booth, $admin);

        $this->actingAs($admin)
            ->put("/admin/reservations/{$reservation->id}", [
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
                'staff_id' => $staff->user_id,
                'booth_id' => $booth->id,
                'version' => $reservation->version,
                'is_staff_requested' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertTrue($reservation->is_staff_requested);
        $this->assertSame(1, $reservation->version);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.nomination_updated',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_admin_can_change_the_menu_and_the_duration_follows_it(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('menu-change');
        $admin = $this->admin();
        $reservation = $this->createReservation($customer, $service, $staff, $booth, $admin);
        $other = Service::factory()->create(['name' => 'service-menu-other', 'duration_min' => 30, 'requires_staff' => true, 'is_active' => true]);
        $other->staff()->attach($staff->user_id);

        $this->actingAs($admin)
            ->put("/admin/reservations/{$reservation->id}", [
                'service_id' => $other->id,
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
                'staff_id' => $staff->user_id,
                'booth_id' => $booth->id,
                'version' => $reservation->version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame($other->id, (int) $reservation->service_id);
        $this->assertSame(30 + (int) $reservation->buffer_min, (int) $reservation->starts_at->diffInMinutes($reservation->ends_at));
    }

    public function test_menu_cannot_change_to_one_the_staff_cannot_perform(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('menu-reject');
        $admin = $this->admin();
        $reservation = $this->createReservation($customer, $service, $staff, $booth, $admin);
        $other = Service::factory()->create(['name' => 'service-menu-noassign', 'duration_min' => 30, 'requires_staff' => true, 'is_active' => true]);

        $this->actingAs($admin)
            ->put("/admin/reservations/{$reservation->id}", [
                'service_id' => $other->id,
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
                'staff_id' => $staff->user_id,
                'booth_id' => $booth->id,
                'version' => $reservation->version,
            ])
            ->assertSessionHasErrors('staff_id');

        $this->assertSame($service->id, (int) $reservation->fresh()->service_id);
    }

    public function test_nomination_and_gender_preference_cannot_be_combined(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-exclusive');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/reservations', [
                'customer_id' => $customer->user_id,
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'is_staff_requested' => true,
                'staff_gender_preference' => 'female',
                'booth_id' => $booth->id,
                'starts_at' => '2026-10-01 10:00:00',
            ])
            ->assertSessionHasErrors('staff_gender_preference');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_edit_screen_receives_the_nomination_flag_so_it_can_be_changed(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-screen');
        $admin = $this->admin();
        $reservation = $this->createReservation($customer, $service, $staff, $booth, $admin);

        $this->actingAs($admin)->get("/admin/reservations/{$reservation->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reservation.is_staff_requested', false));
    }

    public function test_staff_gender_preference_is_saved_on_create_and_editable_alone(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('gender-pref');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/reservations', [
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'starts_at' => '2026-10-01 10:00:00',
            'staff_gender_preference' => 'female',
        ])->assertSessionHasNoErrors();

        $reservation = Reservation::query()->where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertSame('female', $reservation->staff_gender_preference);

        // 日時・担当を変えず、性別希望だけ「男性」に変える（版が1つ進む）。
        $this->actingAs($admin)->put("/admin/reservations/{$reservation->id}", [
            'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'version' => $reservation->version,
            'staff_gender_preference' => 'male',
        ])->assertSessionHasNoErrors();
        $this->assertSame('male', $reservation->fresh()->staff_gender_preference);

        // 空にすると希望なしへ戻る。
        $reservation->refresh();
        $this->actingAs($admin)->put("/admin/reservations/{$reservation->id}", [
            'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
            'staff_id' => $staff->user_id,
            'booth_id' => $booth->id,
            'version' => $reservation->version,
            'staff_gender_preference' => null,
        ])->assertSessionHasNoErrors();
        $this->assertNull($reservation->fresh()->staff_gender_preference);

        $this->actingAs($admin)->post('/admin/reservations', [
            'customer_id' => $customer->user_id, 'service_id' => $service->id, 'staff_id' => $staff->user_id,
            'booth_id' => $booth->id, 'starts_at' => '2026-10-02 10:00:00', 'staff_gender_preference' => 'robot',
        ])->assertSessionHasErrors('staff_gender_preference');
    }

    public function test_dragging_a_reservation_to_a_new_time_preserves_the_nomination_flag(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-drag');
        $admin = $this->admin();
        $reservation = $this->createReservation($customer, $service, $staff, $booth, $admin, isStaffRequested: true);

        $this->actingAs($admin)
            ->put("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-10-01 10:30:00',
                'version' => $reservation->version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertTrue($reservation->is_staff_requested);
    }

    public function test_removing_staff_assignment_clears_the_nomination_flag(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-clear');
        $service->update(['requires_staff' => false]);
        $admin = $this->admin();
        $reservation = $this->createReservation($customer, $service, $staff, $booth, $admin, isStaffRequested: true);

        $this->actingAs($admin)
            ->put("/admin/reservations/{$reservation->id}", [
                'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
                'booth_id' => $booth->id,
                'version' => $reservation->version,
            ])
            ->assertSessionHasNoErrors();

        $reservation->refresh();
        $this->assertNull($reservation->staff_id);
        $this->assertFalse($reservation->is_staff_requested);
    }

    public function test_schedule_query_exposes_is_staff_requested_for_the_board_card(): void
    {
        [$customer, $service, $staff, $booth] = $this->masters('nom-board');
        $admin = $this->admin();
        $this->createReservation($customer, $service, $staff, $booth, $admin, isStaffRequested: true);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertTrue($result['reservations'][0]['is_staff_requested']);
    }

    /** @return array{0: Customer, 1: Service, 2: Staff, 3: Booth} */
    private function masters(string $suffix): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'name' => "service-{$suffix}",
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
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

    private function createReservation(
        Customer $customer,
        Service $service,
        Staff $staff,
        Booth $booth,
        User $actor,
        bool $isStaffRequested = false,
    ): Reservation {
        $this->actingAs($actor)->post('/admin/reservations', [
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'is_staff_requested' => $isStaffRequested,
            'booth_id' => $booth->id,
            'starts_at' => '2026-10-01 10:00:00',
        ]);

        return Reservation::query()->where('customer_id', $customer->user_id)->firstOrFail();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
