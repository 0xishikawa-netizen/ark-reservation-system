<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
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
}
