<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Domain\Reporting\HistoricalImportService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReportReconciliationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_requires_its_own_permission_in_addition_to_report_and_sales(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('staff');
        $url = '/admin/reports/reconciliation?year=2025&month=1';
        $this->actingAs($user)->getJson($url)->assertForbidden();
        $user->givePermissionTo('reports.view', 'sales.view');
        $this->actingAs($user)->getJson($url)->assertForbidden();
        $user->givePermissionTo('reports.reconcile');
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('data.source_status', 'not_provided')
            ->assertJsonPath('data.source_original_verified', false);
    }

    public function test_difference_review_requires_reason_and_is_audited(): void
    {
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $csv = "record_type,metric_code,period_start,period_end,value\n"
            ."historical_aggregate,visit_count,2025-04-01,2025-04-30,2\n";
        $imports = app(HistoricalImportService::class);
        $batch = $imports->stage(UploadedFile::fake()->createWithContent('fixture.csv', $csv), $admin->id)['batch_id'];
        $imports->commit($batch);
        $metric = (int) DB::table('historical_metric_values')->value('id');
        $this->actingAs($admin)->postJson("/admin/reports/reconciliation/{$metric}/review", [
            'difference_category' => 'implementation_bug', 'review_status' => 'needs_attention', 'reason' => '',
        ])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/admin/reports/reconciliation/{$metric}/review", [
            'difference_category' => 'migration_gap', 'review_status' => 'needs_attention', 'reason' => 'fixture差分',
        ])->assertOk()->assertJsonPath('data.difference_category', 'migration_gap');
        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.reconciliation_reviewed', 'actor_user_id' => $admin->id]);
    }
}
