<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Queries\ScheduleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleDailySummaryAndBothAxisTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_counts_and_revenue_are_exact_and_ignore_staff_filter(): void
    {
        $service = Service::factory()->create(['price' => 5000]);
        $staffA = Staff::factory()->create(['is_bookable' => true]);
        $staffB = Staff::factory()->create(['is_bookable' => true]);

        // 新規客（当日が最初の予約・来店完了・final_amount優先）
        $newCustomer = Customer::factory()->create();
        $this->makeReservation($newCustomer, $service, $staffA, '2026-10-05 10:00:00', ReservationStatus::Completed, 4500);

        // リピーター（過去に来店実績あり・当日は来店完了）
        $repeatCustomer = Customer::factory()->create();
        $this->makeReservation($repeatCustomer, $service, $staffA, '2026-09-01 10:00:00', ReservationStatus::Completed);
        $this->makeReservation($repeatCustomer, $service, $staffB, '2026-10-05 11:00:00', ReservationStatus::Completed);

        // キャンセル・無断キャンセル（別の顧客、staffB に絞ってもカウント対象）
        $canceledCustomer = Customer::factory()->create();
        $this->makeReservation($canceledCustomer, $service, $staffB, '2026-10-05 12:00:00', ReservationStatus::Canceled);
        $noShowCustomer = Customer::factory()->create();
        $this->makeReservation($noShowCustomer, $service, $staffB, '2026-10-05 13:00:00', ReservationStatus::NoShow);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-05'), staffId: $staffA->user_id);

        $this->assertNotNull($result['summary']);
        $this->assertSame(4, $result['summary']['total']);
        $this->assertSame(2, $result['summary']['completed']);
        // 「新規」は当日が最初の来店実績（Completed/Confirmed/NoShow 等）である顧客。
        // 無断キャンセル客もその予約が最初の実績のため新規側に入る（キャンセルは実績に数えない）。
        $this->assertSame(2, $result['summary']['new_customers']);
        $this->assertSame(2, $result['summary']['repeat_customers']);
        $this->assertSame(1, $result['summary']['canceled']);
        $this->assertSame(1, $result['summary']['no_show']);
        $this->assertSame(4500 + 5000, $result['summary']['revenue']);
    }

    public function test_summary_is_null_for_week_view(): void
    {
        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-05'), view: 'week');

        $this->assertNull($result['summary']);
    }

    public function test_axis_both_returns_staff_and_booths_together_with_staff_shifts(): void
    {
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $booth = Booth::factory()->create(['is_active' => true]);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-05',
            'start_at' => '10:00:00',
            'end_at' => '18:00:00',
        ]);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-05'), axis: 'both');

        $this->assertSame('both', $result['axis']);
        $this->assertNotEmpty($result['staff']);
        $this->assertNotEmpty($result['booths']);
        $this->assertNotEmpty($result['shifts']);
    }

    private function makeReservation(
        Customer $customer,
        Service $service,
        Staff $staff,
        string $startsAt,
        ReservationStatus $status,
        ?int $finalAmount = null,
    ): Reservation {
        return Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes((int) $service->duration_min),
            'status' => $status,
            'final_amount' => $finalAmount,
        ]);
    }
}
