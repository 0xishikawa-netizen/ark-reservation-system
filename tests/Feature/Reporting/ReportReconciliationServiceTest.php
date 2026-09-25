<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\HistoricalImportService;
use App\Domain\Reporting\ReportReconciliationService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Visit\VisitStatus;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\Checkout;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReportReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_facts_are_not_falsely_claimed_as_source_verified(): void
    {
        $result = app(ReportReconciliationService::class)->forMonth(2025, 2);
        $this->assertSame('not_provided', $result['source_status']);
        $this->assertFalse($result['source_original_verified']);
        $this->assertSame([], $result['comparisons']);
        $this->assertSame(0, $result['check_summary']['failed']);
        $this->assertSame('pass', collect($result['checks'])->firstWhere('code', 'completed_visits_equal_visit_count')['status']);
    }

    public function test_provided_october_workbook_is_registered_as_structural_evidence_not_numeric_match(): void
    {
        $source = resource_path('report_templates/ark-jiyugaoka-2026-10-source.xlsx');
        $before = hash_file('sha256', $source);
        $result = app(ReportReconciliationService::class)->forMonth(2026, 10);
        $this->assertSame('provided_without_imported_actuals', $result['source_status']);
        $this->assertFalse($result['source_original_verified']);
        $this->assertSame([], $result['comparisons']);
        $evidence = $result['source_workbook_evidence'];
        $this->assertTrue($evidence['structure_verified']);
        $this->assertSame('2026-10', $evidence['period']);
        $this->assertSame(0, $evidence['source_actual_input_count']);
        $this->assertSame('blocked_no_actual_source_values', $evidence['numeric_reconciliation_status']);
        $this->assertSame('not_provided', $evidence['google_sheets_status']);
        $this->assertSame(2000000, (int) $evidence['target_candidates']['数値!C6']);
        $this->assertSame(2600000, (int) $evidence['target_candidates']['月計表!C36']);
        $this->assertSame(2080000, (int) $evidence['target_candidates']['年間計画書 (実数)!O4']);
        $this->assertTrue($evidence['target_conflict']);
        $this->assertSame($before, hash_file('sha256', $source));
    }

    public function test_fixture_source_is_compared_and_difference_requires_manual_classification(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Visit::factory()->create([
            'business_date' => '2025-02-12', 'status' => VisitStatus::Completed,
            'completed_at' => '2025-02-12 04:00:00', 'visit_sequence' => 1,
            'future_reservation_exists_at_checkout' => true,
            'future_reservation_snapshot_at' => '2025-02-12 04:00:00',
        ]);
        $csv = "record_type,metric_code,period_start,period_end,value,source_identifier\n"
            ."historical_aggregate,visit_count,2025-02-01,2025-02-28,2,fixture-visit\n"
            ."historical_aggregate,legacy_treatment_count,2025-02-01,2025-02-28,3,fixture-old\n";
        $imports = app(HistoricalImportService::class);
        $batch = $imports->stage(UploadedFile::fake()->createWithContent('fixture.csv', $csv), $user->id)['batch_id'];
        $imports->commit($batch);

        $service = app(ReportReconciliationService::class);
        $report = $service->forMonth(2025, 2, $batch);
        $this->assertSame('available', $report['source_status']);
        $this->assertFalse($report['source_original_verified']);
        $visit = collect($report['comparisons'])->firstWhere('metric_code', 'visit_count');
        $this->assertSame(2, $visit['source_value']);
        $this->assertSame(1, $visit['ark_value']);
        $this->assertSame(-1, $visit['difference']);
        $this->assertSame('different', $visit['comparison_status']);
        $this->assertSame('pending', $visit['review_status']);
        $legacy = collect($report['comparisons'])->firstWhere('metric_code', 'legacy_treatment_count');
        $this->assertNull($legacy['ark_value']);
        $this->assertSame('not_comparable', $legacy['comparison_status']);

        $service->review($visit['historical_metric_value_id'], 'migration_gap', 'needs_attention', 'fixture差分', $user->id);
        $reviewed = $service->forMonth(2025, 2, $batch);
        $this->assertSame('migration_gap', collect($reviewed['comparisons'])->firstWhere('metric_code', 'visit_count')['difference_category']);
        $this->assertDatabaseCount('historical_metric_reviews', 1);
        $imports->invalidate($batch);
        $this->expectException(\InvalidArgumentException::class);
        $service->forMonth(2025, 2, $batch);
    }

    public function test_reconciliation_flags_checkout_and_staff_time_inconsistency_without_join_multiplication(): void
    {
        $visit = Visit::factory()->create([
            'business_date' => '2025-03-10', 'status' => VisitStatus::Completed,
            'completed_at' => '2025-03-10 04:00:00', 'visit_sequence' => 1,
            'future_reservation_exists_at_checkout' => false,
        ]);
        Checkout::factory()->create([
            'visit_id' => $visit->id, 'status' => CheckoutStatus::Finalized,
            'finalized_at' => '2025-03-10 04:00:00',
        ]);
        $treatment = VisitTreatment::factory()->create([
            'visit_id' => $visit->id, 'status' => VisitTreatmentStatus::Completed,
            'actual_minutes' => 60,
        ]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $treatment->id, 'actual_minutes' => 30]);
        $second = VisitTreatment::factory()->create([
            'visit_id' => $visit->id, 'status' => VisitTreatmentStatus::Completed,
            'actual_minutes' => 45,
        ]);
        VisitTreatmentStaff::factory()->create(['visit_treatment_id' => $second->id, 'actual_minutes' => 45]);

        $result = app(ReportReconciliationService::class)->forMonth(2025, 3);
        $checks = collect($result['checks'])->keyBy('code');
        $this->assertSame('pass', $checks['completed_visits_equal_visit_count']['status']);
        $this->assertSame(1, $checks['completed_visits_equal_visit_count']['actual']);
        $this->assertSame(1, $checks['daily_to_monthly_long_visit_count']['actual']);
        $this->assertSame('fail', $checks['checkout_lines_tenders_equal_header']['status']);
        $this->assertSame('fail', $checks['staff_allocated_time_equal_treatment_time']['status']);
    }

    public function test_missing_snapshot_and_treatment_minutes_remain_unknown_not_zero(): void
    {
        $visit = Visit::factory()->create([
            'business_date' => '2025-04-03', 'status' => VisitStatus::Completed,
            'completed_at' => '2025-04-03 04:00:00', 'visit_sequence' => null,
            'future_reservation_exists_at_checkout' => null,
        ]);
        VisitTreatment::factory()->create([
            'visit_id' => $visit->id, 'status' => VisitTreatmentStatus::Completed,
            'actual_minutes' => null,
        ]);
        $checks = collect(app(ReportReconciliationService::class)->forMonth(2025, 4)['checks'])->keyBy('code');
        $this->assertSame('unknown', $checks['next_reservation_unknown_not_zeroed']['status']);
        $this->assertSame(1, $checks['next_reservation_unknown_not_zeroed']['unknown_count']);
        $this->assertSame('unknown', $checks['staff_allocated_time_equal_treatment_time']['status']);
        $this->assertSame(1, $checks['staff_allocated_time_equal_treatment_time']['unknown_count']);
    }
}
