<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\Visit;
use App\Queries\ScheduleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ScheduleDailySummaryAndBothAxisTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_counts_and_revenue_are_exact_and_ignore_staff_filter(): void
    {
        $service = Service::factory()->create(['price' => 5000]);
        $staffA = Staff::factory()->create(['is_bookable' => true]);
        $staffB = Staff::factory()->create(['is_bookable' => true]);
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金']);

        // 新規客（当日が最初の予約・来店完了・final_amount優先）
        $newCustomer = Customer::factory()->create();
        $newReservation = $this->makeReservation($newCustomer, $service, $staffA, '2026-10-05 10:00:00', ReservationStatus::Completed, 4500);
        $this->makeVisitAndSale($newReservation, '2026-10-05', 1, 4500, $cash);

        // リピーター（過去に来店実績あり・当日は来店完了）
        $repeatCustomer = Customer::factory()->create();
        $pastReservation = $this->makeReservation($repeatCustomer, $service, $staffA, '2026-09-01 10:00:00', ReservationStatus::Completed);
        $this->makeVisitAndSale($pastReservation, '2026-09-01', 1, null, $cash);
        $currentReservation = $this->makeReservation($repeatCustomer, $service, $staffB, '2026-10-05 11:00:00', ReservationStatus::Completed);
        $this->makeVisitAndSale($currentReservation, '2026-10-05', 2, 5000, $cash);

        // キャンセル・無断キャンセル（別の顧客、staffB に絞ってもカウント対象）
        $canceledCustomer = Customer::factory()->create();
        $this->makeReservation($canceledCustomer, $service, $staffB, '2026-10-05 12:00:00', ReservationStatus::Canceled);
        $noShowCustomer = Customer::factory()->create();
        $this->makeReservation($noShowCustomer, $service, $staffB, '2026-10-05 13:00:00', ReservationStatus::NoShow);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-05'), staffId: $staffA->user_id, includeSales: true);

        $this->assertNotNull($result['summary']);
        $this->assertSame(4, $result['summary']['total']);
        $this->assertSame(2, $result['summary']['completed']);
        // 新規・リピーターは予約予定ではなく、完了Visitのsequenceを正本にする。
        $this->assertSame(1, $result['summary']['new_customers']);
        $this->assertSame(1, $result['summary']['repeat_customers']);
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

    private function makeVisitAndSale(
        Reservation $reservation,
        string $businessDate,
        int $sequence,
        ?int $amount,
        PaymentMethod $method,
    ): Visit {
        $visit = Visit::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'business_date' => $businessDate,
            'status' => VisitStatus::Completed,
            'visit_sequence' => $sequence,
            'future_reservation_exists_at_checkout' => false,
        ]);
        if ($amount === null) {
            return $visit;
        }

        $now = "{$businessDate} 01:00:00";
        $checkoutId = DB::table('checkouts')->insertGetId([
            'visit_id' => $visit->id,
            'status' => 'finalized',
            'subtotal_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'currency' => 'jpy',
            'finalized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('checkout_tenders')->insert([
            'checkout_id' => $checkoutId,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'status' => 'received',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $visit;
    }
}
