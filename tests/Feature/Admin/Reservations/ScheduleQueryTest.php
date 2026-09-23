<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Queries\ScheduleQuery;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_the_selected_days_staff_shifts_and_visible_reservations(): void
    {
        app(Settings::class)->set('business_hours.open', '09:30');
        app(Settings::class)->set('business_hours.close', '19:00');
        app(Settings::class)->set('reservation.slot_minutes', 15, 'int');
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();
        $staff = Staff::factory()->create([
            'display_name' => '担当A',
            'is_bookable' => true,
            'sort_order' => 5,
        ]);
        $otherStaff = Staff::factory()->create([
            'display_name' => '担当B',
            'is_bookable' => true,
            'sort_order' => 10,
        ]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-02',
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);
        $target = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);
        Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => '2026-10-02 10:00:00',
            'ends_at' => '2026-10-02 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);
        Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $otherStaff->user_id,
            'booth_id' => null,
            'starts_at' => '2026-10-01 12:00:00',
            'ends_at' => '2026-10-01 13:00:00',
            'status' => ReservationStatus::Canceled,
        ]);

        $result = app(ScheduleQuery::class)->get(
            CarbonImmutable::parse('2026-10-01'),
            (int) $staff->user_id,
        );

        $this->assertCount(1, $result['staff']);
        $this->assertSame($staff->user_id, $result['staff'][0]['user_id']);
        $this->assertCount(1, $result['shifts']);
        $this->assertSame('10:00:00', $result['shifts'][0]['start_at']);
        $this->assertCount(1, $result['reservations']);
        $this->assertSame($target->id, $result['reservations'][0]['id']);
        $this->assertSame('09:30', $result['business_hours']['open']);
        $this->assertSame('19:00', $result['business_hours']['close']);
        $this->assertSame(15, $result['business_hours']['slot_minutes']);
    }

    public function test_it_flags_new_customers_and_exposes_gender_on_reservations(): void
    {
        $service = Service::factory()->create();
        $staff = Staff::factory()->create(['is_bookable' => true]);

        $firstTimer = Customer::factory()->create(['gender' => 'female']);
        $returning = Customer::factory()->create(['gender' => 'male']);

        // 常連客は台帳表示日より前に来店実績がある。
        Reservation::factory()->create([
            'customer_id' => $returning->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-09-20 10:00:00',
            'ends_at' => '2026-09-20 11:00:00',
            'status' => ReservationStatus::Completed,
        ]);

        $newBooking = Reservation::factory()->create([
            'customer_id' => $firstTimer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);
        $returningBooking = Reservation::factory()->create([
            'customer_id' => $returning->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-10-01 12:00:00',
            'ends_at' => '2026-10-01 13:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-01'));

        $byId = collect($result['reservations'])->keyBy('id');

        $this->assertTrue($byId[$newBooking->id]['is_new_customer']);
        $this->assertSame('female', $byId[$newBooking->id]['customer_gender']);
        $this->assertSame($firstTimer->user_id, $byId[$newBooking->id]['customer_id']);

        $this->assertFalse($byId[$returningBooking->id]['is_new_customer']);
        $this->assertSame('male', $byId[$returningBooking->id]['customer_gender']);
    }
}
