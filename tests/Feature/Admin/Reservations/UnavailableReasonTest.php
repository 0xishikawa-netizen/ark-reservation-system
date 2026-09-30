<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Domain\Business\StoreCalendarService;
use App\Domain\Reservation\AvailabilityService;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ResourceType;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\StoreCalendarDay;
use App\Models\User;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ブッキングボードで「予約できません」の時に出す理由と、毎週の定休日。
 */
final class UnavailableReasonTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->service = Service::factory()->create(['name' => '整体60', 'duration_min' => 60, 'is_active' => true, 'requires_staff' => true]);
        $this->staff = Staff::factory()->create(['display_name' => '本間']);
        $this->service->staff()->attach($this->staff->user_id);
        // 2026-10-01 は木曜日。
        StaffShift::query()->create(['staff_id' => $this->staff->user_id, 'work_date' => '2026-10-01', 'start_at' => '10:00:00', 'end_at' => '18:00:00']);
    }

    /** @return list<string> */
    private function explain(?int $staffId, string $startsAt): array
    {
        return app(AvailabilityService::class)->explainUnavailable($this->service->id, $staffId, null, CarbonImmutable::parse($startsAt, 'Asia/Tokyo'));
    }

    public function test_staff_who_cannot_perform_the_service_is_explained(): void
    {
        $other = Staff::factory()->create(['display_name' => '斉藤']);

        $this->assertSame(
            [__('messages.availability_reason.staff_cannot_perform', ['staff' => '斉藤', 'service' => '整体60'])],
            $this->explain($other->user_id, '2026-10-01 11:00'),
        );
    }

    public function test_conflicting_reservation_and_outside_shift_are_explained(): void
    {
        $startsAt = CarbonImmutable::parse('2026-10-01 11:00', 'Asia/Tokyo');
        $endsAt = CarbonImmutable::parse('2026-10-01 12:00', 'Asia/Tokyo');
        $reservation = Reservation::factory()->create([
            'customer_id' => Customer::factory()->create()->user_id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->user_id,
            'booth_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => ReservationStatus::Confirmed,
        ]);
        // 実際の予約作成と同じく、担当スタッフの占有枠も持たせる。
        foreach (SlotKey::fromSettings()->occupiedSlots($startsAt, $endsAt) as $slot) {
            ReservationResourceSlot::query()->create([
                'resource_type' => ResourceType::Staff,
                'resource_id' => $this->staff->user_id,
                'slot_start' => $slot,
                'reservation_id' => $reservation->id,
            ]);
        }

        $booked = $this->explain($this->staff->user_id, '2026-10-01 11:30');
        $this->assertTrue(collect($booked)->contains(fn (string $reason): bool => str_contains($reason, '本間さんは') && str_contains($reason, '別の予約')), implode("\n", $booked));

        $late = $this->explain($this->staff->user_id, '2026-10-01 17:30');
        $this->assertTrue(collect($late)->contains(fn (string $reason): bool => str_contains($reason, '勤務時間（10:00〜18:00）')), implode("\n", $late));
    }

    public function test_menu_whose_mapped_booths_are_all_inactive_is_explained(): void
    {
        $booth = Booth::factory()->create(['is_active' => false]);
        $this->service->booths()->attach($booth->id);

        $this->assertSame(
            [__('messages.availability_reason.no_active_booth', ['service' => '整体60'])],
            $this->explain($this->staff->user_id, '2026-10-01 11:00'),
        );
    }

    public function test_weekly_closed_day_closes_the_store_unless_opened_as_exception(): void
    {
        $calendar = app(StoreCalendarService::class);
        $calendar->setClosedWeekdays([4], null);

        $this->assertSame(StoreCalendarDay::STATUS_CLOSED, $calendar->resolve('2026-10-01')['status']);
        $this->assertSame(StoreCalendarDay::STATUS_CLOSED, $calendar->resolve('2026-10-08')['status']);
        $this->assertNotSame(StoreCalendarDay::STATUS_CLOSED, $calendar->resolve('2026-10-02')['status']);
        $this->assertSame([__('messages.availability_reason.store_closed')], $this->explain($this->staff->user_id, '2026-10-01 11:00'));

        // 定休日の曜日でも「営業」の例外日は通常営業。
        $calendar->save(['business_date' => '2026-10-01', 'status' => StoreCalendarDay::STATUS_OPEN], null);
        $this->assertNotSame(StoreCalendarDay::STATUS_CLOSED, $calendar->resolve('2026-10-01')['status']);
        $this->assertSame(StoreCalendarDay::STATUS_CLOSED, $calendar->resolve('2026-10-08')['status']);
    }

    public function test_closed_weekdays_endpoint_requires_valid_weekdays(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->put('/admin/settings/business-masters/calendar/closed-weekdays', ['weekdays' => [8]])
            ->assertSessionHasErrors('weekdays.0');
        $this->actingAs($admin)->put('/admin/settings/business-masters/calendar/closed-weekdays', ['weekdays' => [2, 3]])
            ->assertSessionHasNoErrors();

        $this->assertSame([2, 3], app(StoreCalendarService::class)->closedWeekdays());
    }

    public function test_reasons_endpoint_returns_json(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->getJson('/admin/reservations/unavailable-reasons?'.http_build_query([
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->user_id,
            'starts_at' => '2026-10-01 17:30',
        ]))->assertOk()->assertJsonStructure(['reasons']);
    }
}
