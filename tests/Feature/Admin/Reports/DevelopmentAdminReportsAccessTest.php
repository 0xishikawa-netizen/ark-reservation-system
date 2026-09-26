<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reports;

use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DevelopmentAdminReportsAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> */
    private const PAGES = [
        'admin.reports.monthly' => 'Admin/Reports/Monthly',
        'admin.reports.customers' => 'Admin/Reports/Customers',
        'admin.reports.staff-utilization' => 'Admin/Reports/StaffUtilization',
        'admin.reports.time-bands' => 'Admin/Reports/TimeBands',
        'admin.reports.annual' => 'Admin/Reports/Annual',
    ];

    public function test_seeded_development_admin_can_open_all_reports_and_their_apis(): void
    {
        $this->seed(DevelopmentAdminSeeder::class);
        $admin = User::query()->where('email', config('dev_admin.email'))->firstOrFail();

        foreach (self::PAGES as $name => $component) {
            $parameters = ['year' => 2026, 'month' => 9, 'as_of_date' => '2026-09-15'];
            if ($name === 'admin.reports.annual') {
                unset($parameters['month']);
            }
            $this->actingAs($admin)->get(route($name, $parameters))
                ->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component($component));
            $this->actingAs($admin)->getJson(route($name.'.data', $parameters))->assertOk();
        }

        $this->actingAs($admin)->get(route('admin.reports.monthly'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('auth.can.reportsView', true)
                ->where('auth.can.reportsManage', true)
                ->where('auth.reportRoutes.dailyNotes', route('admin.reports.daily-notes'))
                ->where('auth.can.salesView', true)
                ->where('auth.reportRoutes.monthly', route('admin.reports.monthly'))
                ->where('auth.reportRoutes.customers', route('admin.reports.customers'))
                ->where('auth.reportRoutes.staffUtilization', route('admin.reports.staff-utilization'))
                ->where('auth.reportRoutes.timeBands', route('admin.reports.time-bands'))
                ->where('auth.reportRoutes.annual', route('admin.reports.annual')));
    }

    public function test_staff_without_reports_permission_cannot_open_reports_or_see_menu_permission(): void
    {
        $this->seed(DevelopmentAdminSeeder::class);
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        foreach (self::PAGES as $name => $component) {
            $this->actingAs($staff)->get(route($name))->assertForbidden();
            $this->actingAs($staff)->getJson(route($name.'.data'))->assertForbidden();
        }
        $this->actingAs($staff)->get(route('admin.dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.can.reportsView', false)
            ->where('auth.can.salesView', false));
    }
}
