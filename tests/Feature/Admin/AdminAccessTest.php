<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
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
            // 無操作時間の計測（旧 AdminIdleTimeout の最終操作時刻）は行わない。
            ->assertSessionMissing('admin.last_activity')
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

    /**
     * 管理画面は無操作・時間経過で自動ログアウトしない（docs/SESSION_POLICY.md）。
     * 旧 AdminIdleTimeout が使っていた最終操作時刻が古くても、長時間後でも、そのまま使える。
     */
    public function test_long_inactivity_does_not_log_out_the_admin(): void
    {
        $staff = $this->staffWithMfa();

        $this->actingAs($staff)
            ->withSession(['admin.last_activity' => now()->subDay()->timestamp])
            ->get('/admin')
            ->assertOk();

        $this->travel(10)->hours();

        $this->actingAs($staff)->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_admin_routes_have_no_idle_timeout_middleware(): void
    {
        $this->assertFalse(class_exists('App\\Http\\Middleware\\AdminIdleTimeout'));
        $this->assertNull(config('admin.idle_timeout'));

        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.'));
        $this->assertNotEmpty($adminRoutes);
        foreach ($adminRoutes as $route) {
            $middleware = implode(' ', $route->gatherMiddleware());
            $this->assertDoesNotMatchRegularExpression('/idle|inactiv|timeout/i', $middleware, (string) $route->getName());
            // 認証・管理画面権限・アカウント有効性・MFA は維持する。
            $this->assertStringContainsString('auth', $middleware);
            $this->assertStringContainsString('AdminAccess', $middleware);
            $this->assertStringContainsString('EnsureAccountIsActive', $middleware);
        }
    }

    public function test_keep_alive_requires_an_authenticated_admin_and_keeps_the_session(): void
    {
        $this->getJson('/admin/session/keep-alive')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/admin/session/keep-alive')
            ->assertForbidden();

        $staff = $this->staffWithMfa();
        $this->actingAs($staff)->getJson('/admin/session/keep-alive')->assertNoContent();
        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_disabled_account_is_still_logged_out_through_keep_alive(): void
    {
        $staff = $this->staffWithMfa();
        $staff->forceFill(['is_active' => false])->save();

        $this->actingAs($staff)
            ->getJson('/admin/session/keep-alive')
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_explicit_logout_ends_admin_access(): void
    {
        $staff = $this->staffWithMfa();

        $this->actingAs($staff)->get('/admin')->assertOk();
        $this->post('/logout')->assertRedirect();

        $this->assertGuest('web');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->getJson('/admin/session/keep-alive')->assertUnauthorized();
    }

    public function test_expired_csrf_session_on_inertia_request_redirects_to_login_with_japanese_message(): void
    {
        Route::middleware('web')->post('/__test/token-mismatch', function (): never {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $this->from('/admin/reports/monthly')
            ->post('/__test/token-mismatch', [], ['X-Inertia' => 'true'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('messages.auth.session_ended'));
        $this->assertSame(url('/admin/reports/monthly'), session('url.intended'));

        // Inertia 以外（通常のフォーム・API）は従来どおり 419 を返す。
        $this->post('/__test/token-mismatch')->assertStatus(419);
    }

    private function staffWithMfa(): User
    {
        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');

        return $staff;
    }
}
