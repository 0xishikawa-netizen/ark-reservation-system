<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\User;
use App\Models\Visit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MonthlyReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_open_page_and_change_month_and_sales_basis_through_json_api(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        Visit::factory()->create([
            'business_date' => '2026-10-03', 'status' => 'completed',
            'visit_sequence' => 1, 'future_reservation_exists_at_checkout' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/reports/monthly?year=2026&month=10&basis=payment_date&as_of_date=2026-10-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Admin/Reports/Monthly')
                ->where('report.month_key', '2026-10')
                ->where('report.as_of_date', '2026-10-15')
                ->where('report.daily_rows.2.visit_count', 1)
                ->where('report.sales_basis', 'payment_date'));

        $this->actingAs($admin)
            ->getJson('/admin/reports/monthly/data?year=2026&month=10&basis=treatment_date&as_of_date=2026-10-15')
            ->assertOk()
            ->assertJsonPath('data.month_key', '2026-10')
            ->assertJsonPath('data.sales_basis', 'treatment_date')
            ->assertJsonCount(31, 'data.daily_rows');
    }

    public function test_monthly_report_requires_both_report_and_sales_permissions(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/admin/reports/monthly')->assertForbidden();
        $staff->givePermissionTo('reports.view');
        $this->actingAs($staff)->get('/admin/reports/monthly')->assertForbidden();
        $staff->givePermissionTo('sales.view');
        $this->actingAs($staff)->get('/admin/reports/monthly')->assertOk();
    }

    public function test_query_parameters_and_as_of_month_boundary_are_validated(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->getJson('/admin/reports/monthly/data?year=1999&month=13')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/reports/monthly/data?year=2026&month=10&basis=mixed')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/reports/monthly/data?year=2026&month=10&as_of_date=2026-11-01')->assertUnprocessable();
    }
}
