<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 8: /admin/* の deny-by-default を全 GET ルートで機械的に検証する。
 * 個別機能テストの穴を塞ぐ回帰ガード。
 */
final class AdminRouteAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * ルートパラメータを持たない admin.* の GET ルート一覧。
     *
     * @return list<string>
     */
    private function parameterlessAdminGetRoutes(): array
    {
        $paths = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';

            if (! str_starts_with($name, 'admin.')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if ($route->parameterNames() !== []) {
                continue;
            }

            $paths[] = '/'.ltrim($route->uri(), '/');
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    public function test_customer_role_is_denied_every_parameterless_admin_get_route(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $routes = $this->parameterlessAdminGetRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $path) {
            $response = $this->actingAs($customer)->get($path);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 302],
                "customer が {$path} に到達できてはならない (status {$response->getStatusCode()})",
            );

            if ($response->getStatusCode() === 302) {
                $this->assertStringNotContainsString(
                    '/admin',
                    (string) $response->headers->get('Location'),
                    "{$path} のリダイレクト先が /admin であってはならない",
                );
            }
        }
    }

    public function test_unauthenticated_user_is_denied_every_parameterless_admin_get_route(): void
    {
        foreach ($this->parameterlessAdminGetRoutes() as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    /**
     * Phase 9 / item 10-2: DevelopmentAdminSeeder が用意した開発管理者は、
     * どの /admin GET 画面でも 403 にも MFA setup リダイレクトにもならない。
     * 「URL 直打ちなら入れるがメニューに出ない」「メニューには出るが 403」を残さないための保証。
     */
    public function test_seeded_development_admin_can_reach_every_parameterless_admin_get_route(): void
    {
        $this->seed(DevelopmentAdminSeeder::class);
        $admin = User::query()->where('email', config('dev_admin.email'))->firstOrFail();

        $routes = $this->parameterlessAdminGetRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $path) {
            $response = $this->actingAs($admin)->get($path);
            $status = $response->getStatusCode();
            $location = (string) $response->headers->get('Location');

            $this->assertNotSame(403, $status, "開発管理者が {$path} で 403 になってはならない");

            // 機微操作画面は password.confirm へ飛ぶのは正常。MFA setup へ飛ぶのは NG。
            if ($status === 302) {
                $this->assertStringNotContainsString(
                    '/admin/mfa',
                    $location,
                    "開発管理者が {$path} で MFA setup へ飛ばされてはならない",
                );
                $this->assertStringNotContainsString(
                    'two-factor',
                    $location,
                    "開発管理者が {$path} で 2FA setup へ飛ばされてはならない",
                );
                $this->assertStringNotContainsString(
                    route('login'),
                    $location,
                    "開発管理者が {$path} でログインへ飛ばされてはならない",
                );
            } else {
                // 200（画面）か 422（クエリ検証を伴う一覧 API）のみ許容。
                $this->assertContains(
                    $status,
                    [200, 422],
                    "開発管理者の {$path} が想定外のステータス {$status}",
                );
            }
        }
    }

    public function test_seeded_development_admin_menu_matches_reachable_routes(): void
    {
        $this->seed(DevelopmentAdminSeeder::class);
        $admin = User::query()->where('email', config('dev_admin.email'))->firstOrFail();

        // Inertia 共有の can.* が admin では全て true（メニュー表示制御と route 認可の一致）。
        $can = [
            'admin.access', 'staff.manage', 'services.manage', 'booths.manage',
            'shifts.manage', 'customers.view', 'customers.manage', 'reservations.view',
            'reservations.manage', 'settings.manage', 'refund.execute', 'ticket.grant',
            'membership.manage', 'ticket_policy.manage', 'ticket_products.manage',
            'failed_jobs.view', 'audit_logs.view', 'integrations.view', 'integrations.manage',
        ];

        foreach ($can as $permission) {
            $this->assertTrue(
                $admin->can($permission),
                "開発管理者は {$permission} を持つべき（メニュー表示と認可の一致）",
            );
        }
    }
}
