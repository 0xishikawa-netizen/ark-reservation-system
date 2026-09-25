<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AnnualReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_both_reporting_and_sales_permissions_are_required(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('staff');
        $url = '/admin/reports/annual?year=2026&as_of_date=2026-10-15';
        $this->actingAs($user)->get($url)->assertForbidden();
        $user->givePermissionTo('reports.view');
        $this->actingAs($user)->get($url)->assertForbidden();
        $user->givePermissionTo('sales.view');
        $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Reports/Annual')->where('report.year', 2026));
        $this->actingAs($user)->getJson('/admin/reports/annual/data?year=2026&basis=treatment_date&as_of_date=2026-10-15')
            ->assertOk()->assertJsonPath('data.sales_basis', 'treatment_date')->assertJsonCount(12, 'data.months');
        $this->actingAs($user)->getJson('/admin/reports/annual/data?year=2026&basis=invalid')->assertUnprocessable();
        $this->actingAs($user)->getJson('/admin/reports/annual/data?year=2026&as_of_date=2027-01-01')->assertUnprocessable();
    }
}
