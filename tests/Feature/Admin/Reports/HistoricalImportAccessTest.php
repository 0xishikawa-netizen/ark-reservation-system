<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HistoricalImportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_requires_separate_permission_and_mutations_are_audited(): void
    {
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('staff');
        $csv = "record_type,metric_code,period_start,period_end,value\n"
            ."historical_aggregate,visit_count,2025-03-01,2025-03-31,8\n";
        $this->actingAs($user)->postJson('/admin/reports/historical-imports/preview', [
            'file' => UploadedFile::fake()->createWithContent('source.csv', $csv),
        ])->assertForbidden();
        $user->givePermissionTo('historical_data.import');
        $this->actingAs($user)->post('/admin/reports/historical-imports/preview', [
            'file' => UploadedFile::fake()->createWithContent('source.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('historical_import_batches', 0);
        $response = $this->actingAs($user)->post('/admin/reports/historical-imports', [
            'file' => UploadedFile::fake()->createWithContent('source.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated();
        $batch = $response->json('data.batch_id');
        $this->actingAs($user)->postJson("/admin/reports/historical-imports/{$batch}/commit")->assertOk();
        $this->actingAs($user)->postJson("/admin/reports/historical-imports/{$batch}/invalidate")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'historical_import.staged', 'actor_user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'historical_import.committed', 'actor_user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'historical_import.invalidated', 'actor_user_id' => $user->id]);
    }

    public function test_manual_customer_confirmation_requires_import_permission_and_existing_customer_id(): void
    {
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $customer = Customer::factory()->create();
        $csv = "record_type,name\ncustomer_detail,同姓同名\n";
        $staged = $this->actingAs($admin)->post('/admin/reports/historical-imports', [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated();
        $row = $staged->json('data.rows.1.id');
        $this->actingAs($admin)->postJson("/admin/reports/historical-import-rows/{$row}/customer-match", [
            'customer_id' => $customer->user_id,
        ])->assertOk()->assertJsonPath('data.rows.1.match_method', 'manual_review');
        $this->assertDatabaseHas('audit_logs', ['action' => 'historical_import.customer_match_reviewed', 'actor_user_id' => $admin->id]);
        $this->assertDatabaseCount('visits', 0);
    }
}
