<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Settings\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get('/admin')
            ->assertRedirect(route('login'));
    }

    public function test_user_without_admin_access_receives_forbidden_response(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_customer_role_receives_forbidden_response(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->get('/admin')
            ->assertForbidden();
    }

    /**
     * Phase 9.6: MFA 手段（TOTP）が無い staff の誘導先は統合 MFA 画面。
     */
    public function test_staff_without_any_mfa_method_is_redirected_to_mfa_setup(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get('/admin')
            ->assertRedirect(route('admin.mfa.show'));
    }

    public function test_two_factor_setup_page_is_excluded_from_the_mfa_redirect(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff);

        $this->get('/admin/two-factor-setup')
            ->assertOk()
            ->assertSessionHas('url.intended', route('admin.two-factor-setup'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Profile/TwoFactorSetup')
                ->where('twoFactorPending', false)
                ->where('twoFactorEnabled', false));

        $this->post('/user/confirm-password', [
            'password' => 'password',
        ])->assertRedirect(route('admin.two-factor-setup'));
    }

    public function test_staff_with_confirmed_two_factor_can_view_admin_dashboard(): void
    {
        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->get('/admin')
            ->assertOk()
            ->assertSessionHas(
                'admin.last_activity',
                fn (mixed $value): bool => is_int($value),
            )
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('failedJobsCount', 0)
                ->where('auth.can.staffManage', false)
                ->where('auth.can.servicesManage', false)
                ->where('auth.can.boothsManage', false)
                ->where('auth.can.shiftsManage', false)
                ->where('auth.can.customersView', true)
                ->where('auth.can.customersManage', false)
                ->where('auth.can.failedJobsView', false)
                ->where('auth.can.auditLogsView', false));
    }

    public function test_expired_admin_session_is_logged_out(): void
    {
        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->withSession(['admin.last_activity' => now()->subHour()->timestamp])
            ->get('/admin')
            ->assertRedirect(route('login', ['expired' => 1]));

        $this->assertGuest('web');
    }

    public function test_idle_timeout_prefers_the_database_setting(): void
    {
        app(Settings::class)->set('admin.idle_timeout', 60);

        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)
            ->withSession(['admin.last_activity' => now()->subSeconds(61)->timestamp])
            ->get('/admin')
            ->assertRedirect(route('login', ['expired' => 1]));

        $this->assertGuest('web');
    }
}
