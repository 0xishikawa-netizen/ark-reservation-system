<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TimeBandUtilizationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_report_requires_permission_and_exposes_same_data_to_api(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $user->assignRole('staff');
        $staff = Staff::factory()->create();
        $url = '/admin/reports/time-bands?year=2026&month=9&as_of_date=2026-09-15';
        $this->actingAs($user)->get($url)->assertForbidden();
        $user->givePermissionTo('reports.view');
        $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Reports/TimeBands')->where('report.month_key', '2026-09'));
        $this->actingAs($user)->getJson('/admin/reports/time-bands/data?year=2026&month=9&staff_id='.$staff->user_id.'&as_of_date=2026-09-15')
            ->assertOk()->assertJsonPath('data.daily_rows.0.staff_id', $staff->user_id);
        $this->actingAs($user)->getJson('/admin/reports/time-bands/data?year=2026&month=9&as_of_date=2026-10-01')
            ->assertUnprocessable();
    }
}
