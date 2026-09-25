<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\DailyBusinessSummary;
use App\Domain\Reporting\DailyReportService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\PaymentMethod;
use App\Models\RevenueAllocation;
use App\Models\RevenueRecognitionContract;
use App\Models\Staff;
use App\Models\TaxCategory;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DailyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_day_preserves_zero_denominator_as_unknown_rate(): void
    {
        $summary = $this->report('2026-09-25');

        $this->assertSame('2026-09-25', $summary->businessDate);
        $this->assertSame(5, $summary->weekdayIso);
        $this->assertSame('金', $summary->weekday);
        $this->assertSame(0, $summary->visitCount);
        $this->assertNull($summary->futureReservationRate->value);
        $this->assertNull($summary->firstVisitReservationRate->value);
        $this->assertSame(['M' => 0, 'T' => 0, 'A' => 0, 'M&T' => 0, 'A&T' => 0], $summary->analysisCategoryVisitCounts);
    }

    public function test_visit_long_category_snapshot_and_unknown_metrics_are_aggregated_per_visit(): void
    {
        $sixty = $this->visit(['future_reservation_exists_at_checkout' => true, 'visit_sequence' => 1]);
        $this->treatment($sixty, 60, 'M');
        $this->treatment($sixty, 1, 'M', VisitTreatmentStatus::Voided);

        $sixtyOne = $this->visit(['future_reservation_exists_at_checkout' => false, 'visit_sequence' => 2]);
        $sixtyOneTreatment = $this->treatment($sixtyOne, 61, 'T');
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $sixtyOneTreatment->id, 'staff_id' => Staff::factory()]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $sixtyOneTreatment->id, 'staff_id' => Staff::factory()]);

        $thirtyThirty = $this->visit(['future_reservation_exists_at_checkout' => null, 'visit_sequence' => 1]);
        $this->treatment($thirtyThirty, 30, 'A');
        $this->treatment($thirtyThirty, 30, 'A');

        $thirtyFortyFive = $this->visit(['future_reservation_exists_at_checkout' => true, 'visit_sequence' => 1]);
        $this->treatment($thirtyFortyFive, 30, 'M');
        $this->treatment($thirtyFortyFive, 45, 'T');

        $setCourse = $this->visit();
        $this->treatment($setCourse, 75, 'M&T');
        $this->treatment($setCourse, 15, 'A&T');

        $unknown = $this->visit();
        $this->treatment($unknown, null, null);
        $this->visit(); // 施術実績自体がない過去来店もunknown。
        Visit::factory()->create(['business_date' => '2026-09-25', 'status' => VisitStatus::Draft]);
        Visit::factory()->create(['business_date' => '2026-09-25', 'status' => VisitStatus::Voided]);

        $summary = $this->report();

        $this->assertSame(7, $summary->visitCount);
        $this->assertSame(3, $summary->longVisitCount);
        $this->assertSame(2, $summary->unknownTreatmentMinutesVisitCount);
        $this->assertSame(2, $summary->futureReservationCount);
        $this->assertSame(1, $summary->futureReservationUnknownCount);
        $this->assertSame(2 / 7, $summary->futureReservationRate->value);
        $this->assertSame(3, $summary->firstVisitCount);
        $this->assertSame(2, $summary->firstVisitReservationCount);
        $this->assertSame(1, $summary->firstVisitReservationUnknownCount);
        $this->assertSame(2 / 3, $summary->firstVisitReservationRate->value);
        $this->assertSame(2, $summary->analysisCategoryVisitCounts['M']);
        $this->assertSame(2, $summary->analysisCategoryVisitCounts['T']);
        $this->assertSame(1, $summary->analysisCategoryVisitCounts['A']);
        $this->assertSame(1, $summary->analysisCategoryVisitCounts['M&T']);
        $this->assertSame(1, $summary->analysisCategoryVisitCounts['A&T']);
        $this->assertSame(2, $summary->unknownAnalysisCategoryVisitCount);
        $this->assertSame(7, $summary->accountingPendingVisitCount);
    }

    public function test_payment_methods_tax_snapshots_checkout_statuses_and_jst_boundaries_are_exact(): void
    {
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金', 'display_order' => 10]);
        $paypay = PaymentMethod::factory()->create(['code' => 'paypay', 'name' => 'PayPay', 'display_order' => 20]);
        $tax = TaxCategory::query()->create(['code' => 'standard', 'name' => '現在名']);

        $visitA = $this->visit();
        $checkoutA = $this->checkout($visitA, CheckoutStatus::Finalized, '2026-09-24 15:00:00', [
            $this->line(1000, 100, 1100, 'standard', '保存済み標準', 1000, $tax->id),
            $this->line(1000, 80, 1080, 'reduced', '軽減', 800),
            $this->line(1000, 0, 1000, 'exempt', '非課税', 0),
            $this->line(1000, 50, 1050, 'other', 'その他率', 500),
            $this->line(100, 0, 100),
        ], [
            ['method' => $cash, 'amount' => 2330, 'received_at' => '2026-09-24 15:00:00'],
            ['method' => $paypay, 'amount' => 2000, 'received_at' => '2026-09-25 14:59:59'],
        ]);

        $visitB = $this->visit();
        $this->checkout($visitB, CheckoutStatus::Finalized, '2026-09-25 03:00:00', [
            $this->line(7000, 700, 7700, 'standard', '保存済み標準', 1000),
        ], [
            ['method' => $cash, 'amount' => 5770, 'received_at' => '2026-09-25 03:00:00'],
            ['method' => $paypay, 'amount' => 1930, 'received_at' => '2026-09-25 03:00:00'],
        ]);

        $draft = $this->visit();
        $this->checkout($draft, CheckoutStatus::Draft, null, [$this->line(900, 100, 1000)], [
            ['method' => $cash, 'amount' => 1000, 'received_at' => '2026-09-25 03:00:00'],
        ]);
        $voided = $this->visit();
        $this->checkout($voided, CheckoutStatus::Voided, '2026-09-25 03:00:00', [$this->line(900, 100, 1000)], [
            ['method' => $cash, 'amount' => 1000, 'received_at' => '2026-09-25 03:00:00'],
        ]);
        $outside = $this->visit(['business_date' => '2026-09-24']);
        $this->checkout($outside, CheckoutStatus::Finalized, '2026-09-24 14:59:59', [$this->line(900, 100, 1000)], [
            ['method' => $cash, 'amount' => 1000, 'received_at' => '2026-09-24 14:59:59'],
        ]);

        $beforeRename = $this->report();
        $tax->update(['name' => '変更後名称']);
        $afterRename = $this->report();

        $this->assertSame(12_030, $beforeRename->paymentDateRevenue);
        $this->assertSame(8100, $beforeRename->paymentMethodTotals[0]['amount']);
        $this->assertSame(3930, $beforeRename->paymentMethodTotals[1]['amount']);
        $this->assertSame($beforeRename->taxTotals, $afterRename->taxTotals);
        $this->assertSame(12_030, array_sum(array_column($beforeRename->taxTotals, 'gross_amount')));
        $this->assertSame([null, 0, 500, 800, 1000], array_column($beforeRename->taxTotals, 'tax_rate_bps'));
        $standard = collect($beforeRename->taxTotals)->firstWhere('tax_rate_bps', 1000);
        $this->assertSame('保存済み標準', $standard['tax_category_name']);
        $this->assertSame(8000, $standard['net_amount']);
        $this->assertSame(800, $standard['tax_amount']);
        $this->assertSame(8800, $standard['gross_amount']);
        $this->assertSame($checkoutA, DB::table('checkouts')->where('id', $checkoutA)->value('id'));
    }

    public function test_payment_and_treatment_date_revenue_are_independent_and_never_inferred(): void
    {
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金']);
        $visit = $this->visit();
        $treatment = $this->treatment($visit, 60, 'M');
        $this->checkout($visit, CheckoutStatus::Finalized, '2026-10-01 01:00:00', [
            $this->line(9000, 900, 9900, visitTreatmentId: $treatment->id),
            $this->line(1000, 100, 1100, itemType: 'ticket'),
        ], [
            ['method' => $cash, 'amount' => 11_000, 'received_at' => '2026-10-01 01:00:00'],
        ]);
        $contract = RevenueRecognitionContract::factory()->create(['contract_amount' => 8800]);
        RevenueAllocation::factory()->create([
            'revenue_recognition_contract_id' => $contract->id,
            'visit_id' => $visit->id,
            'visit_treatment_id' => $treatment->id,
            'recognized_on' => '2026-09-25',
            'amount' => 1100,
            'allocation_no' => 1,
        ]);

        $treatmentDay = $this->report('2026-09-25');
        $paymentDay = $this->report('2026-10-01');

        $this->assertSame(0, $treatmentDay->paymentDateRevenue);
        $this->assertSame(9900, $treatmentDay->directTreatmentRevenue);
        $this->assertSame(1100, $treatmentDay->allocatedTreatmentRevenue);
        $this->assertSame(11_000, $treatmentDay->treatmentDateRevenue);
        $this->assertSame(11_000, $paymentDay->paymentDateRevenue);
        $this->assertSame(0, $paymentDay->treatmentDateRevenue);
    }

    public function test_join_multiplication_cannot_duplicate_visit_time_categories_or_sales(): void
    {
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金']);
        $paypay = PaymentMethod::factory()->create(['code' => 'paypay', 'name' => 'PayPay']);
        $visit = $this->visit(['future_reservation_exists_at_checkout' => true, 'visit_sequence' => 1]);
        $first = $this->treatment($visit, 30, 'M');
        $second = $this->treatment($visit, 45, 'T');
        foreach ([$first, $second] as $treatment) {
            VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'staff_id' => Staff::factory()]);
            VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'staff_id' => Staff::factory()]);
        }
        $this->checkout($visit, CheckoutStatus::Finalized, '2026-09-25 01:00:00', [
            $this->line(4000, 400, 4400, 'standard', '標準', 1000, visitTreatmentId: $first->id),
            $this->line(6000, 600, 6600, 'standard', '標準', 1000, visitTreatmentId: $second->id),
        ], [
            ['method' => $cash, 'amount' => 5000, 'received_at' => '2026-09-25 01:00:00'],
            ['method' => $paypay, 'amount' => 6000, 'received_at' => '2026-09-25 01:00:00'],
        ]);

        $summary = $this->report();

        $this->assertSame(1, $summary->visitCount);
        $this->assertSame(1, $summary->longVisitCount);
        $this->assertSame(1, $summary->analysisCategoryVisitCounts['M']);
        $this->assertSame(1, $summary->analysisCategoryVisitCounts['T']);
        $this->assertSame(11_000, $summary->paymentDateRevenue);
        $this->assertSame(11_000, $summary->directTreatmentRevenue);
        $this->assertSame(11_000, array_sum(array_column($summary->paymentMethodTotals, 'amount')));
        $this->assertSame(11_000, array_sum(array_column($summary->taxTotals, 'gross_amount')));
    }

    /** @param array<string, mixed> $attributes */
    private function visit(array $attributes = []): Visit
    {
        return Visit::factory()->create([
            'business_date' => '2026-09-25',
            'status' => VisitStatus::Completed,
            'future_reservation_exists_at_checkout' => false,
            'visit_sequence' => 2,
            ...$attributes,
        ]);
    }

    private function treatment(
        Visit $visit,
        ?int $minutes,
        ?string $category,
        VisitTreatmentStatus $status = VisitTreatmentStatus::Completed,
    ): VisitTreatment {
        return VisitTreatment::factory()->create([
            'visit_id' => $visit->id,
            'status' => $status,
            'actual_minutes' => $minutes,
            'analysis_category_code_snapshot' => $category,
            'analysis_category_name_snapshot' => $category,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{method: PaymentMethod, amount: int, received_at: string}>  $tenders
     */
    private function checkout(Visit $visit, CheckoutStatus $status, ?string $finalizedAt, array $lines, array $tenders): int
    {
        $subtotal = array_sum(array_column($lines, 'net_amount'));
        $tax = array_sum(array_column($lines, 'tax_amount'));
        $total = array_sum(array_column($lines, 'gross_amount'));
        $now = now()->format('Y-m-d H:i:s');
        $checkoutId = DB::table('checkouts')->insertGetId([
            'visit_id' => $visit->id,
            'status' => $status->value,
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'currency' => 'jpy',
            'finalized_at' => $finalizedAt,
            'voided_at' => $status === CheckoutStatus::Voided ? ($finalizedAt ?? $now) : null,
            'void_reason' => $status === CheckoutStatus::Voided ? 'test' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($lines as $index => $line) {
            DB::table('checkout_lines')->insert([
                'checkout_id' => $checkoutId,
                'item_type' => $line['item_type'],
                'visit_treatment_id' => $line['visit_treatment_id'],
                'item_name_snapshot' => '明細'.($index + 1),
                'quantity' => 1,
                'unit_amount' => $line['gross_amount'],
                'tax_category_id' => $line['tax_category_id'],
                'tax_category_code_snapshot' => $line['tax_category_code_snapshot'],
                'tax_category_name_snapshot' => $line['tax_category_name_snapshot'],
                'tax_rate_bps' => $line['tax_rate_bps'],
                'net_amount' => $line['net_amount'],
                'tax_amount' => $line['tax_amount'],
                'gross_amount' => $line['gross_amount'],
                'is_staff_allocatable' => false,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ($tenders as $tender) {
            DB::table('checkout_tenders')->insert([
                'checkout_id' => $checkoutId,
                'payment_method_id' => $tender['method']->id,
                'amount' => $tender['amount'],
                'status' => CheckoutTenderStatus::Received->value,
                'received_at' => $tender['received_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $checkoutId;
    }

    /** @return array<string, mixed> */
    private function line(
        int $net,
        int $tax,
        int $gross,
        ?string $taxCode = null,
        ?string $taxName = null,
        ?int $rateBps = null,
        ?int $taxCategoryId = null,
        ?int $visitTreatmentId = null,
        string $itemType = 'service',
    ): array {
        return [
            'item_type' => $itemType,
            'visit_treatment_id' => $visitTreatmentId,
            'tax_category_id' => $taxCategoryId,
            'tax_category_code_snapshot' => $taxCode,
            'tax_category_name_snapshot' => $taxName,
            'tax_rate_bps' => $rateBps,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'gross_amount' => $gross,
        ];
    }

    private function report(string $date = '2026-09-25'): DailyBusinessSummary
    {
        return app(DailyReportService::class)->forDate($date);
    }
}
