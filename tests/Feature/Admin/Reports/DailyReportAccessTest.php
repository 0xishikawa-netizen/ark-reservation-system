<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\User;
use App\Models\Visit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_get_the_reusable_daily_summary_json(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');
        Visit::factory()->create([
            'business_date' => '2026-09-25',
            'status' => 'completed',
            'visit_sequence' => 1,
            'future_reservation_exists_at_checkout' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/reports/daily?date=2026-09-25')
            ->assertOk()
            ->assertJsonPath('data.business_date', '2026-09-25')
            ->assertJsonPath('data.visit_count', 1)
            ->assertJsonPath('data.future_reservation_rate.numerator', 1)
            ->assertJsonPath('data.future_reservation_rate.denominator', 1);
    }

    public function test_report_requires_both_report_and_sales_permissions(): void
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->getJson('/admin/reports/daily?date=2026-09-25')
            ->assertForbidden();

        $staff->givePermissionTo('reports.view');
        $this->actingAs($staff)
            ->getJson('/admin/reports/daily?date=2026-09-25')
            ->assertForbidden();

        $staff->givePermissionTo('sales.view');
        $this->actingAs($staff)
            ->getJson('/admin/reports/daily?date=2026-09-25')
            ->assertOk();
    }

    public function test_date_is_required_and_strictly_validated(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->getJson('/admin/reports/daily')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/reports/daily?date=2026-02-30')->assertUnprocessable();
    }
}
