<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9 / item 10: 消えない開発管理者。
 */
final class DevelopmentAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_it_creates_a_development_admin_with_the_admin_role(): void
    {
        $this->seed(DevelopmentAdminSeeder::class);

        $user = User::query()->where('email', config('dev_admin.email'))->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('admin'));
        // admin ロールは全 admin 権限を持つ（RolePermissionSeeder）。
        $this->assertTrue($user->can('admin.access'));
        $this->assertTrue($user->can('refund.execute'));
        $this->assertTrue($user->can('integrations.manage'));
        // MFA 要件を満たしていて /admin へ素通しできる。
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->email_verified_at);
        // ブラウザログインに 2FA チャレンジを挟まない（secret は持たせない）。
        $this->assertNull($user->two_factor_secret);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DevelopmentAdminSeeder::class);
        $this->seed(DevelopmentAdminSeeder::class);
        $this->seed(DevelopmentAdminSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', config('dev_admin.email'))->count(),
        );
    }

    public function test_password_matches_the_configured_dev_password(): void
    {
        config(['dev_admin.password' => 'super-secret-dev']);

        $this->seed(DevelopmentAdminSeeder::class);

        $this->post('/login', [
            'email' => config('dev_admin.email'),
            'password' => 'super-secret-dev',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_it_does_nothing_when_the_current_environment_is_not_allowed(): void
    {
        // production を含まない既定リストなので、production 相当では作成されない。
        config(['dev_admin.environments' => ['production']]);

        $this->seed(DevelopmentAdminSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => config('dev_admin.email')]);
    }

    public function test_default_config_excludes_production(): void
    {
        $this->assertNotContains('production', config('dev_admin.environments'));
    }
}
