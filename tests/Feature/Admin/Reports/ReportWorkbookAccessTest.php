<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportWorkbookAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_export_permission_is_independent_and_download_is_audited(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('staff');
        $url = '/admin/reports/excel?year=2026&month=10&as_of_date=2026-10-15';
        $this->actingAs($user)->get($url)->assertForbidden();
        $user->givePermissionTo('reports.view', 'sales.view');
        $this->actingAs($user)->get($url)->assertForbidden();
        $user->givePermissionTo('reports.export');
        $response = $this->actingAs($user)->get($url)->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(rawurlencode('ARK自由が丘店2026.10.xlsx'),
            (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.workbook_exported', 'actor_user_id' => $user->id]);
        $this->actingAs($user)->getJson('/admin/reports/excel?year=2026&month=10&as_of_date=2026-11-01')
            ->assertUnprocessable();
    }
}
