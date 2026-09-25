<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\User;
use App\Models\Visit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerAnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_reports_permission_can_open_page_and_json_without_sales_permission(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');
        Visit::factory()->create(['business_date' => '2026-10-03', 'status' => 'completed', 'visit_sequence' => 1]);

        $this->actingAs($staff)->get('/admin/reports/customers')->assertForbidden();
        $staff->givePermissionTo('reports.view');
        $this->actingAs($staff)->get('/admin/reports/customers?year=2026&month=10&as_of_date=2026-10-31')
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Reports/Customers')
            ->where('report.cohort_month', '2026-10')
            ->where('report.new_customers', 1));
        $this->actingAs($staff)->getJson('/admin/reports/customers/data?year=2026&month=10&as_of_date=2026-10-31')
            ->assertOk()->assertJsonPath('data.new_customers', 1)->assertJsonPath('data.reach.2.denominator', 1);
    }

    public function test_query_inputs_are_validated(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->getJson('/admin/reports/customers/data?year=1999&month=13')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/reports/customers/data?as_of_date=2026-02-31')->assertUnprocessable();
    }
}
