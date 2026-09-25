<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessFacts;

use App\Domain\Visit\VisitFactService;
use App\Enums\Visit\VisitTreatmentStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\ServiceAnalysisCategory;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\VisitTreatment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class VisitFactServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_and_walk_in_visits_use_jst_business_date_without_guessing_unknown_values(): void
    {
        $customer = Customer::factory()->create();
        $reservation = Reservation::factory()->for($customer, 'customer')->create();
        $service = app(VisitFactService::class);

        $reserved = $service->createDraft($customer, $reservation, [
            'started_at' => CarbonImmutable::parse('2026-09-24 15:30:00', 'UTC'),
        ]);
        $walkIn = $service->createDraft($customer, null, ['business_date' => '2026-09-25']);

        $this->assertSame('2026-09-25', $reserved->business_date->toDateString());
        $this->assertSame($reservation->id, $reserved->reservation_id);
        $this->assertNull($walkIn->reservation_id);
        $this->assertNull($walkIn->visit_sequence);
        $this->assertNull($walkIn->future_reservation_exists_at_checkout);

        $this->expectException(QueryException::class);
        Visit::factory()->create(['customer_id' => $customer->user_id, 'reservation_id' => $reservation->id]);
    }

    public function test_customer_is_required_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        Visit::query()->create(['business_date' => '2026-09-24', 'status' => 'draft']);
    }

    public function test_service_category_and_staff_names_are_snapshotted_and_actual_minutes_must_balance(): void
    {
        $category = ServiceAnalysisCategory::query()->create(['code' => 'M', 'name' => 'マッサージ', 'is_active' => true]);
        $master = Service::factory()->create(['name' => '整体60', 'analysis_category_id' => $category->id]);
        $staffA = Staff::factory()->create(['display_name' => '担当A']);
        $staffB = Staff::factory()->create(['display_name' => '担当B']);
        $visit = Visit::factory()->create([
            'primary_staff_id' => $staffA->user_id,
            'primary_staff_name_snapshot' => $staffA->display_name,
        ]);
        $facts = app(VisitFactService::class);

        $treatment = $facts->addTreatment($visit, $master, ['actual_minutes' => 75, 'operation_key' => 'treatment:1']);
        $facts->assignStaff($treatment, $staffA, ['actual_minutes' => 45]);
        $facts->assignStaff($treatment, $staffB, ['actual_minutes' => 15]);

        try {
            $facts->completeTreatment($treatment);
            $this->fail('担当時間の不一致を拒否する必要があります。');
        } catch (ValidationException) {
            $this->assertSame('draft', $treatment->fresh()->status->value);
        }

        $facts->assignStaff($treatment, $staffB, ['actual_minutes' => 30]);
        $completed = $facts->completeTreatment($treatment);
        $category->update(['name' => '変更後']);
        $staffA->update(['display_name' => '変更後A']);

        $this->assertSame(VisitTreatmentStatus::Completed, $completed->status);
        $this->assertSame($staffA->user_id, $visit->primary_staff_id);
        $this->assertSame('マッサージ', $completed->analysis_category_name_snapshot);
        $this->assertSame('担当A', $completed->staffAssignments()->where('staff_id', $staffA->user_id)->value('staff_name_snapshot'));
        $this->expectException(RuntimeException::class);
        $completed->update(['actual_minutes' => 60]);
    }

    public function test_long_visit_boundary_uses_total_treatment_minutes_and_counts_each_visit_once(): void
    {
        foreach ([[60], [61], [30, 30], [30, 45], [75]] as $index => $minutes) {
            $visit = Visit::factory()->create();
            foreach ($minutes as $part) {
                VisitTreatment::factory()->create([
                    'visit_id' => $visit->id,
                    'actual_minutes' => $part,
                    'status' => VisitTreatmentStatus::Completed,
                ]);
            }
            $isLong = (int) $visit->treatments()->where('status', 'completed')->sum('actual_minutes') > 60;
            $this->assertSame(in_array($index, [1, 3, 4], true), $isLong);
        }
    }

    public function test_existing_reservation_does_not_create_fact_rows_implicitly(): void
    {
        Reservation::factory()->create();
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('checkouts', 0);
        $this->assertDatabaseCount('revenue_allocations', 0);
    }
}
