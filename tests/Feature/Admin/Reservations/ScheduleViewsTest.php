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
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ScheduleViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_view_returns_reservations_in_the_monday_to_sunday_range(): void
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $monday = $this->reservationAt($customer, $service, $staff, '2026-09-28 10:00:00');
        $sunday = $this->reservationAt($customer, $service, $staff, '2026-10-04 15:00:00');
        $this->reservationAt($customer, $service, $staff, '2026-09-27 10:00:00');
        $this->reservationAt($customer, $service, $staff, '2026-10-05 10:00:00');

        $result = app(ScheduleQuery::class)->get(
            CarbonImmutable::parse('2026-10-01'),
            null,
            'week',
        );

        $this->assertSame('week', $result['view']);
        $this->assertSame(['start' => '2026-09-28', 'end' => '2026-10-04'], $result['range']);
        $this->assertSame([
            '2026-09-28',
            '2026-09-29',
            '2026-09-30',
            '2026-10-01',
            '2026-10-02',
            '2026-10-03',
            '2026-10-04',
        ], $result['days']);
        $this->assertSame([$monday->id, $sunday->id], array_column($result['reservations'], 'id'));
        $this->assertSame([], $result['shifts']);
    }

    public function test_booth_axis_returns_only_active_booths_in_order_and_keeps_booth_ids(): void
    {
        $inactive = Booth::factory()->create([
            'name' => '休止中',
            'sort_order' => 0,
            'is_active' => false,
        ]);
        $first = Booth::factory()->create([
            'name' => 'ブースA',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $second = Booth::factory()->create([
            'name' => 'ブースB',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $last = Booth::factory()->create([
            'name' => 'ブースC',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => $second->id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
        ]);

        $result = app(ScheduleQuery::class)->get(
            CarbonImmutable::parse('2026-10-01'),
            null,
            'day',
            'booth',
        );

        $this->assertSame('booth', $result['axis']);
        $this->assertSame([$first->id, $second->id, $last->id], array_column($result['booths'], 'id'));
        $this->assertNotContains($inactive->id, array_column($result['booths'], 'id'));
        $this->assertSame($reservation->id, $result['reservations'][0]['id']);
        $this->assertSame($second->id, $result['reservations'][0]['booth_id']);
        $this->assertSame([], $result['shifts']);
    }

    public function test_two_argument_call_keeps_day_staff_defaults_and_loads_shifts(): void
    {
        $staff = Staff::factory()->create(['is_bookable' => true]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);

        $result = app(ScheduleQuery::class)->get(
            CarbonImmutable::parse('2026-10-01'),
            (int) $staff->user_id,
        );

        $this->assertSame('day', $result['view']);
        $this->assertSame('staff', $result['axis']);
        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-01'], $result['range']);
        $this->assertSame(['2026-10-01'], $result['days']);
        $this->assertSame([], $result['booths']);
        $this->assertCount(1, $result['shifts']);
    }

    public function test_controller_passes_week_and_booth_filters_to_inertia(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = $this->reservationViewer();

        $this->actingAs($actor)
            ->get('/admin/schedule?date=2026-10-01&view=week&axis=booth')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Schedule/Index')
                ->where('filters.view', 'week')
                ->where('filters.axis', 'booth')
                ->where('view', 'week')
                ->where('axis', 'booth'));
    }

    public function test_controller_rejects_invalid_view_and_axis_values(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = $this->reservationViewer();

        $this->actingAs($actor)
            ->getJson('/admin/schedule?view=month')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('view');
        $this->actingAs($actor)
            ->getJson('/admin/schedule?axis=room')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('axis');
    }

    private function reservationAt(
        Customer $customer,
        Service $service,
        Staff $staff,
        string $startsAt,
    ): Reservation {
        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addHour(),
        ]);
    }

    private function reservationViewer(): User
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('staff');

        return $user;
    }
}
