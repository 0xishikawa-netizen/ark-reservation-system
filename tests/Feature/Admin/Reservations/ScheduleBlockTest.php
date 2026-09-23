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
use App\Models\Staff;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\User;
use App\Queries\ScheduleQuery;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));
        app(Settings::class)->set('reservation.slot_minutes', 10, 'int');
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '20:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_add_a_break_block_for_a_staff_member(): void
    {
        $staff = $this->staffWithShift();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/schedule/blocks', [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '12:00',
                'end_at' => '13:00',
                'type' => 'BREAK',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('staff_schedule_blocks', [
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'type' => 'BREAK',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule_block.created']);
    }

    public function test_other_type_requires_a_title(): void
    {
        $staff = $this->staffWithShift();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/schedule/blocks', [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '12:00',
                'end_at' => '13:00',
                'type' => 'OTHER',
            ])
            ->assertJsonValidationErrors('title');
    }

    public function test_must_specify_either_staff_or_booth(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/schedule/blocks', [
                'work_date' => '2026-10-01',
                'start_at' => '12:00',
                'end_at' => '13:00',
                'type' => 'BREAK',
            ])
            ->assertStatus(422);
    }

    public function test_block_outside_staff_shift_is_rejected(): void
    {
        $staff = $this->staffWithShift();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/schedule/blocks', [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '21:00',
                'end_at' => '21:30',
                'type' => 'BREAK',
            ])
            ->assertStatus(422);
    }

    public function test_block_cannot_be_created_over_an_existing_reservation(): void
    {
        $staff = $this->staffWithShift();
        $service = Service::factory()->create(['duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $service->staff()->attach($staff->user_id);
        Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 12:00:00',
            'ends_at' => '2026-10-01 13:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/schedule/blocks', [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '12:30',
                'end_at' => '13:00',
                'type' => 'BREAK',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_schedule_blocks', 0);
    }

    public function test_reservation_cannot_be_created_over_an_existing_block(): void
    {
        $staff = $this->staffWithShift();
        $service = Service::factory()->create(['duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $service->staff()->attach($staff->user_id);
        StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
        ]);
        $customer = Customer::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/reservations', [
                'customer_id' => $customer->user_id,
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => '2026-10-01 12:30:00',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_two_blocks_cannot_overlap_for_the_same_staff(): void
    {
        $staff = $this->staffWithShift();
        StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/admin/schedule/blocks', [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '12:30',
                'end_at' => '13:30',
                'type' => 'MEETING',
            ])
            ->assertStatus(422);
    }

    public function test_availability_excludes_slots_overlapping_a_block(): void
    {
        $staff = $this->staffWithShift();
        $service = Service::factory()->create(['duration_min' => 60, 'requires_staff' => true, 'is_active' => true]);
        $service->staff()->attach($staff->user_id);
        StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
        ]);

        $slots = app(AvailabilityService::class)->openStartTimes(
            $service->id,
            $staff->user_id,
            null,
            CarbonImmutable::parse('2026-10-01'),
        );

        $overlapping = collect($slots)->first(
            fn (array $slot): bool => $slot['starts_at'] === '2026-10-01 12:30:00',
        );
        $this->assertNull($overlapping);

        $before = collect($slots)->first(
            fn (array $slot): bool => $slot['starts_at'] === '2026-10-01 10:30:00',
        );
        $this->assertNotNull($before);
    }

    public function test_booth_block_makes_the_booth_unavailable(): void
    {
        $staff = $this->staffWithShift();
        $booth = Booth::factory()->create(['is_active' => true]);
        $service = Service::factory()->create(['duration_min' => 30, 'requires_staff' => true, 'is_active' => true]);
        $service->staff()->attach($staff->user_id);
        StaffScheduleBlock::factory()->create([
            'staff_id' => null,
            'booth_id' => $booth->id,
            'work_date' => '2026-10-01',
            'start_at' => '14:00:00',
            'end_at' => '14:30:00',
            'type' => ScheduleBlockType::Cleaning,
        ]);

        $slots = app(AvailabilityService::class)->openStartTimes(
            $service->id,
            $staff->user_id,
            $booth->id,
            CarbonImmutable::parse('2026-10-01'),
        );

        $overlapping = collect($slots)->first(
            fn (array $slot): bool => $slot['starts_at'] === '2026-10-01 14:00:00',
        );
        $this->assertNull($overlapping);
    }

    public function test_update_reassigns_time_and_validates_again(): void
    {
        $staff = $this->staffWithShift();
        $block = StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/admin/schedule/blocks/{$block->id}", [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '14:00',
                'end_at' => '15:00',
                'type' => 'MEETING',
            ])
            ->assertSessionHasNoErrors();

        $block->refresh();
        $this->assertSame('14:00:00', $block->start_at);
        $this->assertSame('MEETING', $block->type->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule_block.updated']);
    }

    public function test_drag_and_drop_time_endpoint_moves_the_block(): void
    {
        $staff = $this->staffWithShift();
        $block = StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/admin/schedule/blocks/{$block->id}/time", [
                'work_date' => '2026-10-01',
                'start_at' => '15:00',
                'end_at' => '16:00',
            ])
            ->assertSessionHasNoErrors();

        $block->refresh();
        $this->assertSame('15:00:00', $block->start_at);
    }

    public function test_delete_removes_the_block_and_writes_audit(): void
    {
        $staff = $this->staffWithShift();
        $block = StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete("/admin/schedule/blocks/{$block->id}")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('staff_schedule_blocks', ['id' => $block->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule_block.deleted']);
    }

    public function test_staff_role_without_manage_permission_cannot_create_blocks(): void
    {
        $staff = $this->staffWithShift();
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->assignRole('staff');

        $this->actingAs($viewer)
            ->postJson('/admin/schedule/blocks', [
                'staff_id' => $staff->user_id,
                'work_date' => '2026-10-01',
                'start_at' => '12:00',
                'end_at' => '13:00',
                'type' => 'BREAK',
            ])
            ->assertForbidden();
    }

    public function test_creating_a_block_never_touches_payment_ticket_or_membership_tables(): void
    {
        $staff = $this->staffWithShift();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/schedule/blocks', [
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00',
            'end_at' => '13:00',
            'type' => 'BREAK',
        ]);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('ticket_transactions', 0);
    }

    public function test_schedule_query_returns_blocks_for_the_board(): void
    {
        $staff = $this->staffWithShift();
        StaffScheduleBlock::factory()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '12:00:00',
            'end_at' => '13:00:00',
            'type' => ScheduleBlockType::Break,
        ]);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $this->assertCount(1, $result['blocks']);
        $this->assertSame('休憩', $result['blocks'][0]['type_label']);
        $this->assertSame('12:00', $result['blocks'][0]['start_at']);
    }

    private function staffWithShift(): Staff
    {
        $staff = Staff::factory()->create(['is_bookable' => true]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);

        return $staff;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
