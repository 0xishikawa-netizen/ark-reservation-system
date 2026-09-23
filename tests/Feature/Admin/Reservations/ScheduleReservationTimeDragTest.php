<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Booth;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleReservationTimeDragTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 08:00:00'));
        $settings = app(Settings::class);
        $settings->set('reservation.slot_minutes', 10, 'int');
        $settings->set('business_hours.open', '10:00');
        $settings->set('business_hours.close', '20:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_drag_moves_reservation_time_reusing_reschedule_and_writes_audit(): void
    {
        [$reservation, $admin] = $this->confirmedReservation('2026-09-14 11:00:00', '2026-09-14 12:00:00');

        $this->actingAs($admin)
            ->put("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:30:00',
                'version' => $reservation->version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame('2026-09-14 11:30:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-14 12:30:00', $reservation->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $reservation->version);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.rescheduled',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_stale_version_is_rejected_as_conflict(): void
    {
        [$reservation, $admin] = $this->confirmedReservation('2026-09-14 11:00:00', '2026-09-14 12:00:00');

        $this->actingAs($admin)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:30:00',
                'version' => $reservation->version + 5,
            ])
            ->assertStatus(409);

        $reservation->refresh();
        $this->assertSame('2026-09-14 11:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_move_onto_an_occupied_slot_is_rejected_and_does_not_change_the_reservation(): void
    {
        [$reservation, $admin, $staff, $service] = $this->confirmedReservation(
            '2026-09-14 11:00:00',
            '2026-09-14 12:00:00',
            withMeta: true,
        );

        // 同じスタッフの 13:00-14:00 を別予約で占有。
        $blocker = Reservation::factory()->create([
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-09-14 13:00:00',
            'ends_at' => '2026-09-14 14:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);
        $this->occupy($blocker, $staff->user_id);

        $this->actingAs($admin)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 13:00:00',
                'version' => $reservation->version,
            ])
            ->assertStatus(409);

        $reservation->refresh();
        $this->assertSame('2026-09-14 11:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_move_outside_staff_shift_is_rejected(): void
    {
        [$reservation, $admin] = $this->confirmedReservation('2026-09-14 11:00:00', '2026-09-14 12:00:00');

        $this->actingAs($admin)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 18:30:00',
                'version' => $reservation->version,
            ])
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_completed_reservation_cannot_be_dragged(): void
    {
        [$reservation, $admin] = $this->confirmedReservation('2026-09-14 11:00:00', '2026-09-14 12:00:00');
        $reservation->forceFill(['status' => ReservationStatus::Completed->value])->save();

        $this->actingAs($admin)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:30:00',
                'version' => $reservation->version,
            ])
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_past_reservation_cannot_be_dragged(): void
    {
        [$reservation, $admin] = $this->confirmedReservation('2026-09-14 07:00:00', '2026-09-14 08:00:00');

        $this->actingAs($admin)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:30:00',
                'version' => $reservation->version,
            ])
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_drag_onto_another_qualified_staff_row_reassigns_staff_and_writes_audit(): void
    {
        [$reservation, $admin, , $service] = $this->confirmedReservation(
            '2026-09-14 11:00:00',
            '2026-09-14 12:00:00',
            withMeta: true,
        );

        $newStaff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($newStaff->user_id);
        StaffShift::query()->create([
            'staff_id' => $newStaff->user_id,
            'work_date' => '2026-09-14',
            'start_at' => '10:00',
            'end_at' => '18:00',
        ]);

        $this->actingAs($admin)
            ->put("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:00:00',
                'version' => $reservation->version,
                'staff_id' => $newStaff->user_id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame($newStaff->user_id, $reservation->staff_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.rescheduled',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_move_to_a_non_adjacent_future_date_and_another_staff_in_one_request(): void
    {
        [$reservation, $admin, , $service] = $this->confirmedReservation(
            '2026-09-14 11:00:00',
            '2026-09-14 12:00:00',
            withMeta: true,
        );

        $newStaff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($newStaff->user_id);
        StaffShift::query()->create([
            'staff_id' => $newStaff->user_id,
            'work_date' => '2026-09-21',
            'start_at' => '10:00',
            'end_at' => '18:00',
        ]);

        $this->actingAs($admin)
            ->put("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-21 13:30:00',
                'version' => $reservation->version,
                'staff_id' => $newStaff->user_id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame('2026-09-21 13:30:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 14:30:00', $reservation->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame($newStaff->user_id, $reservation->staff_id);
        $this->assertSame(1, $reservation->version);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation.rescheduled',
            'entity_id' => (string) $reservation->id,
        ]);
    }

    public function test_drag_onto_a_staff_row_not_qualified_for_the_menu_is_rejected(): void
    {
        [$reservation, $admin] = $this->confirmedReservation(
            '2026-09-14 11:00:00',
            '2026-09-14 12:00:00',
            withMeta: true,
        );

        // メニューに紐づいていない別スタッフ（対応不可）。
        $unqualifiedStaff = Staff::factory()->create(['is_bookable' => true]);
        StaffShift::query()->create([
            'staff_id' => $unqualifiedStaff->user_id,
            'work_date' => '2026-09-14',
            'start_at' => '10:00',
            'end_at' => '18:00',
        ]);

        $this->actingAs($admin)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:00:00',
                'version' => $reservation->version,
                'staff_id' => $unqualifiedStaff->user_id,
            ])
            ->assertStatus(422);

        $reservation->refresh();
        $this->assertNotSame($unqualifiedStaff->user_id, $reservation->staff_id);
    }

    public function test_drag_onto_a_different_booth_reassigns_booth(): void
    {
        [$reservation, $admin] = $this->confirmedReservation('2026-09-14 11:00:00', '2026-09-14 12:00:00');
        $newBooth = Booth::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->put("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:00:00',
                'version' => $reservation->version,
                'booth_id' => $newBooth->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame($newBooth->id, $reservation->booth_id);
    }

    public function test_omitting_staff_id_and_booth_id_preserves_current_assignment(): void
    {
        [$reservation, $admin, $staff] = $this->confirmedReservation(
            '2026-09-14 11:00:00',
            '2026-09-14 12:00:00',
            withMeta: true,
        );

        $this->actingAs($admin)
            ->put("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:30:00',
                'version' => $reservation->version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reservation->refresh();
        $this->assertSame($staff->user_id, $reservation->staff_id);
    }

    public function test_staff_role_without_manage_permission_is_forbidden(): void
    {
        [$reservation] = $this->confirmedReservation('2026-09-14 11:00:00', '2026-09-14 12:00:00');
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->assignRole('staff');

        $this->actingAs($viewer)
            ->putJson("/admin/schedule/reservations/{$reservation->id}/time", [
                'starts_at' => '2026-09-14 11:30:00',
                'version' => $reservation->version,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Reservation, 1: User, 2?: Staff, 3?: Service}
     */
    private function confirmedReservation(string $startsAt, string $endsAt, bool $withMeta = false): array
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-09-14',
            'start_at' => '10:00',
            'end_at' => '18:00',
        ]);

        $reservation = Reservation::factory()->create([
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => ReservationStatus::Confirmed,
            'version' => 0,
        ]);
        $this->occupy($reservation, $staff->user_id);

        return $withMeta ? [$reservation, $admin, $staff, $service] : [$reservation, $admin];
    }

    private function occupy(Reservation $reservation, int $staffId): void
    {
        $rows = [];

        foreach (SlotKey::fromSettings()->occupiedSlots(
            CarbonImmutable::parse($reservation->starts_at->format('Y-m-d H:i:s')),
            CarbonImmutable::parse($reservation->ends_at->format('Y-m-d H:i:s')),
            false,
        ) as $slot) {
            $rows[] = [
                'resource_type' => 'staff',
                'resource_id' => $staffId,
                'slot_start' => $slot,
                'reservation_id' => $reservation->id,
            ];
        }

        ReservationResourceSlot::query()->insert($rows);
    }
}
