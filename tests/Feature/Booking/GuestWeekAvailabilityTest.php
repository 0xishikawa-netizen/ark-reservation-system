<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\Reservation\ResourceType;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GuestWeekAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22 09:00:00'));
        $settings = app(Settings::class);
        $settings->set('reservation.slot_minutes', 5, 'int');
        $settings->set('business_hours.open', '10:00');
        $settings->set('business_hours.close', '12:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_week_response_has_seven_days_business_times_and_staff_count_statuses(): void
    {
        [$service, $staff] = $this->serviceWithStaff(3);
        $this->shiftAll($staff, '2026-09-22');
        $this->occupy($service, $staff[0], '2026-09-22 10:00:00', '2026-09-22 11:00:00');

        $this->getJson(route('booking.availability.week', [
            'service_id' => $service->id,
            'start_date' => '2026-09-22',
        ]))
            ->assertOk()
            ->assertJsonCount(7, 'days')
            ->assertJsonPath('days.0.date', '2026-09-22')
            ->assertJsonPath('days.0.label', '9/22(火)')
            ->assertJsonPath('days.6.date', '2026-09-28')
            ->assertJsonPath('times', ['10:00', '10:30', '11:00', '11:30'])
            ->assertJsonPath('cells.2026-09-22.10:00', 'some')
            ->assertJsonPath('cells.2026-09-22.11:00', 'open')
            ->assertJsonPath('cells.2026-09-22.11:30', 'full');
    }

    public function test_specific_staff_filter_is_binary_open_or_full(): void
    {
        [$service, $staff] = $this->serviceWithStaff(3);
        $this->shiftAll($staff, '2026-09-22');
        $this->occupy($service, $staff[0], '2026-09-22 10:00:00', '2026-09-22 11:00:00');

        $response = $this->getJson(route('booking.availability.week', [
            'service_id' => $service->id,
            'staff_id' => $staff[0]->user_id,
            'start_date' => '2026-09-22',
        ]))->assertOk();

        $response
            ->assertJsonPath('cells.2026-09-22.10:00', 'full')
            ->assertJsonPath('cells.2026-09-22.11:00', 'open');

        foreach ($response->json('cells') as $cells) {
            $this->assertNotContains('some', $cells);
        }
    }

    public function test_past_times_today_are_full(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22 10:15:00'));
        [$service, $staff] = $this->serviceWithStaff(3);
        $this->shiftAll($staff, '2026-09-22');

        $this->getJson(route('booking.availability.week', [
            'service_id' => $service->id,
            'start_date' => '2026-09-22',
        ]))
            ->assertOk()
            ->assertJsonPath('cells.2026-09-22.10:00', 'full')
            ->assertJsonPath('cells.2026-09-22.10:30', 'open');
    }

    public function test_day_without_any_available_shift_is_all_full(): void
    {
        [$service] = $this->serviceWithStaff(3);

        $cells = $this->getJson(route('booking.availability.week', [
            'service_id' => $service->id,
            'start_date' => '2026-09-22',
        ]))
            ->assertOk()
            ->json('cells.2026-09-22');

        $this->assertSame(['full'], array_values(array_unique($cells)));
    }

    public function test_week_request_rejects_missing_and_invalid_parameters(): void
    {
        [$service] = $this->serviceWithStaff(1);

        $this->getJson('/booking/availability/week')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id', 'start_date']);

        $this->getJson(route('booking.availability.week', [
            'service_id' => $service->id,
            'staff_id' => 999999,
            'start_date' => '22-09-2026',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['staff_id', 'start_date']);
    }

    public function test_member_endpoint_reuses_the_same_week_response_builder(): void
    {
        $customer = Customer::factory()->create();
        [$service, $staff] = $this->serviceWithStaff(3);
        $this->shiftAll($staff, '2026-09-22');
        $params = [
            'service_id' => $service->id,
            'start_date' => '2026-09-22',
        ];
        $guestResponse = $this->getJson(route('booking.availability.week', $params))
            ->assertOk()
            ->json();

        $this->actingAs($customer->user)
            ->getJson(route('reserve.availability.week', $params))
            ->assertOk()
            ->assertExactJson($guestResponse);
    }

    /** @return array{Service, list<Staff>} */
    private function serviceWithStaff(int $staffCount): array
    {
        $service = Service::factory()->create([
            'duration_min' => 60,
            'is_active' => true,
            'is_online_bookable' => true,
            'requires_staff' => true,
        ]);
        $staff = Staff::factory()->count($staffCount)->create(['is_bookable' => true])->all();

        foreach ($staff as $member) {
            $service->staff()->attach($member->user_id);
        }

        return [$service, $staff];
    }

    /** @param  list<Staff>  $staff */
    private function shiftAll(array $staff, string $date): void
    {
        foreach ($staff as $member) {
            StaffShift::query()->create([
                'staff_id' => $member->user_id,
                'work_date' => $date,
                'start_at' => '10:00:00',
                'end_at' => '12:00:00',
            ]);
        }
    }

    private function occupy(Service $service, Staff $staff, string $startsAt, string $endsAt): void
    {
        $reservation = Reservation::factory()->create([
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        foreach (SlotKey::fromSettings()->occupiedSlots(
            CarbonImmutable::parse($startsAt),
            CarbonImmutable::parse($endsAt),
        ) as $slot) {
            ReservationResourceSlot::query()->create([
                'resource_type' => ResourceType::Staff,
                'resource_id' => $staff->user_id,
                'slot_start' => $slot,
                'reservation_id' => $reservation->id,
            ]);
        }
    }
}
