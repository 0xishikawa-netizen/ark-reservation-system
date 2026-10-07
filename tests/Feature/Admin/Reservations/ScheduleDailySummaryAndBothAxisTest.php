<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\Booth;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\ServiceAnalysisCategory;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\Visit;
use App\Models\VisitTreatment;
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
        ServiceAnalysisCategory::query()->create(['code' => 'M', 'name' => 'メンテナンス', 'is_active' => true, 'sort_order' => 1]);

        // 新規客。会計は翌日に入金されても、当日の客単価には来店紐づけで含める。
        $newCustomer = Customer::factory()->create();
        $newReservation = $this->makeReservation($newCustomer, $service, $staffA, '2026-10-05 10:00:00', ReservationStatus::Completed, 4500);
        $newReservation->update(['source' => ReservationSource::ArkWeb]);
        $this->makeVisitAndSale($newReservation, '2026-10-05', 1, 4500, $cash, '2026-10-06 01:00:00', true, 'M');

        // リピーター（過去に来店実績あり・当日は来店完了）
        $repeatCustomer = Customer::factory()->create();
        $pastReservation = $this->makeReservation($repeatCustomer, $service, $staffA, '2026-09-01 10:00:00', ReservationStatus::Completed);
        $this->makeVisitAndSale($pastReservation, '2026-09-01', 1, null, $cash);
        $currentReservation = $this->makeReservation($repeatCustomer, $service, $staffB, '2026-10-05 11:00:00', ReservationStatus::Completed);
        $this->makeVisitAndSale($currentReservation, '2026-10-05', 2, 5000, $cash, '2026-10-05 01:00:00');

        // 来店完了だが会計待ち。完了来店数には含み、客単価の分母にも含める。
        $pendingCustomer = Customer::factory()->create();
        $pendingReservation = $this->makeReservation($pendingCustomer, $service, $staffB, '2026-10-05 11:30:00', ReservationStatus::Completed);
        $this->makeVisitAndSale($pendingReservation, '2026-10-05', 2, null, $cash);

        // キャンセルされたネット予約は online に含めない。
        $canceledCustomer = Customer::factory()->create();
        $canceled = $this->makeReservation($canceledCustomer, $service, $staffB, '2026-10-05 12:00:00', ReservationStatus::Canceled);
        $canceled->update(['source' => ReservationSource::ArkWeb]);
        $noShowCustomer = Customer::factory()->create();
        $this->makeReservation($noShowCustomer, $service, $staffB, '2026-10-05 13:00:00', ReservationStatus::NoShow);

        // 来店に紐づかない店頭物販は決済日売上には入るが、客単価の分子には入らない。
        $this->makeStoreSale($repeatCustomer, $cash, 3000, '2026-10-05 02:00:00');

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-05'), staffId: $staffA->user_id, includeSales: true);

        $this->assertNotNull($result['summary']);
        $this->assertSame(5, $result['summary']['total']);
        $this->assertSame(3, $result['summary']['completed']);
        // 新規・リピーターは予約予定ではなく、完了Visitのsequenceを正本にする。
        $this->assertSame(1, $result['summary']['new_customers']);
        $this->assertSame(2, $result['summary']['repeat_customers']);
        $this->assertSame(1, $result['summary']['canceled']);
        $this->assertSame(1, $result['summary']['no_show']);
        $this->assertSame(5000 + 3000, $result['summary']['revenue']);
        // 客単価は決済日売上ではなく、当日の完了来店に紐づく確定会計 9,500 円 ÷ 3 来店。
        $this->assertSame(0, $result['summary']['upcoming']);
        $this->assertSame(intdiv(4500 + 5000, 3), $result['summary']['average_spend']);
        $this->assertSame([['name' => '現金', 'amount' => 5000 + 3000]], $result['summary']['payment_methods']);
        $this->assertSame([
            ['code' => 'M', 'name' => 'メンテナンス', 'count' => 1],
            ['code' => null, 'name' => null, 'count' => 2],
        ], $result['summary']['categories']);
        $this->assertSame(5000, $result['summary']['treatment_revenue']);
        $this->assertSame(3000, $result['summary']['retail_revenue']);
        $this->assertSame(1, $result['summary']['accounting_pending']);
        $this->assertSame(['count' => 1, 'rate' => 1 / 3], $result['summary']['future_reservation']);
        $this->assertSame(1, $result['summary']['online']);
        $this->assertSame(0, $result['summary']['nominated']);
    }

    public function test_summary_hides_money_without_sales_permission_and_counts_upcoming(): void
    {
        $service = Service::factory()->create(['price' => 5000]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $customer = Customer::factory()->create();
        $reservation = $this->makeReservation($customer, $service, $staff, '2026-10-05 15:00:00', ReservationStatus::Confirmed);
        $reservation->update(['is_staff_requested' => true]);

        $result = app(ScheduleQuery::class)->get(CarbonImmutable::parse('2026-10-05'));

        $this->assertSame(1, $result['summary']['upcoming']);
        $this->assertSame(1, $result['summary']['nominated']);
        $this->assertNull($result['summary']['revenue']);
        $this->assertNull($result['summary']['average_spend']);
        $this->assertSame([], $result['summary']['payment_methods']);
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
        ?string $receivedAt = null,
        bool $futureReservation = false,
        ?string $categoryCode = null,
    ): Visit {
        $visit = Visit::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'business_date' => $businessDate,
            'status' => VisitStatus::Completed,
            'visit_sequence' => $sequence,
            'future_reservation_exists_at_checkout' => $futureReservation,
        ]);
        if ($categoryCode !== null) {
            VisitTreatment::factory()->create([
                'visit_id' => $visit->id,
                'status' => VisitTreatmentStatus::Completed,
                'analysis_category_code_snapshot' => $categoryCode,
                'analysis_category_name_snapshot' => $categoryCode,
            ]);
        }
        if ($amount === null) {
            return $visit;
        }

        $now = $receivedAt ?? "{$businessDate} 01:00:00";
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
        DB::table('checkout_lines')->insert([
            'checkout_id' => $checkoutId,
            'item_type' => 'service',
            'item_name_snapshot' => '施術',
            'quantity' => 1,
            'unit_amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => 0,
            'gross_amount' => $amount,
            'is_staff_allocatable' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $visit;
    }

    private function makeStoreSale(Customer $customer, PaymentMethod $method, int $amount, string $receivedAt): void
    {
        $checkoutId = DB::table('checkouts')->insertGetId([
            'visit_id' => null,
            'customer_id' => $customer->user_id,
            'sale_date' => '2026-10-05',
            'status' => 'finalized',
            'subtotal_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'currency' => 'jpy',
            'finalized_at' => $receivedAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);
        DB::table('checkout_lines')->insert([
            'checkout_id' => $checkoutId,
            'item_type' => 'product',
            'item_name_snapshot' => '物販',
            'quantity' => 1,
            'unit_amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => 0,
            'gross_amount' => $amount,
            'is_staff_allocatable' => false,
            'sort_order' => 0,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);
        DB::table('checkout_tenders')->insert([
            'checkout_id' => $checkoutId,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'status' => 'received',
            'received_at' => $receivedAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);
    }
}
