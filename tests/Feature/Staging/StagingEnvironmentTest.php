<?php

declare(strict_types=1);

namespace Tests\Feature\Staging;

use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Support\DemoPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * お試し環境（APP_ENV=staging）の入口制御・開発用メニュー・デモパスワード（Task 11-36）。
 */
final class StagingEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaging(): void
    {
        $this->app['env'] = 'staging';
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public function test_gate_does_nothing_outside_staging(): void
    {
        config(['staging.basic_auth.user' => 'ark', 'staging.basic_auth.password' => 'secret-pass']);

        $this->get('/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_staging_without_basic_auth_config_is_open_but_noindex(): void
    {
        $this->actAsStaging();
        config(['staging.basic_auth.user' => null, 'staging.basic_auth.password' => null]);

        $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_staging_requires_basic_auth_when_configured(): void
    {
        $this->actAsStaging();
        config(['staging.basic_auth.user' => 'ark', 'staging.basic_auth.password' => 'secret-pass']);

        $this->get('/')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->withServerVariables(['PHP_AUTH_USER' => 'ark', 'PHP_AUTH_PW' => 'wrong'])
            ->get('/')
            ->assertStatus(401);

        $this->withServerVariables(['PHP_AUTH_USER' => 'ark', 'PHP_AUTH_PW' => 'secret-pass'])
            ->get('/')
            ->assertOk();
    }

    public function test_health_check_and_stripe_webhook_skip_basic_auth(): void
    {
        $this->actAsStaging();
        config(['staging.basic_auth.user' => 'ark', 'staging.basic_auth.password' => 'secret-pass']);

        $this->get('/up')->assertOk();
        // 署名なしなので webhook 自体は 400 になるが、Basic 認証の 401 では止まらない。
        $this->assertNotSame(401, $this->postJson('/stripe/webhook', [])->status());
    }

    public function test_dev_menu_is_hidden_unless_enabled_in_local_or_staging(): void
    {
        config(['staging.dev_menu' => true]);
        // testing 環境では有効化しても出さない。
        $this->get('/dev-menu')->assertNotFound();

        $this->actAsStaging();
        config(['staging.dev_menu' => false, 'staging.basic_auth.user' => null]);
        $this->get('/dev-menu')->assertNotFound();

        config(['staging.dev_menu' => true]);
        $this->get('/dev-menu')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DevMenu')
                ->where('environment', 'staging')
                ->where('accounts.admin', config('dev_admin.email')));
    }

    public function test_demo_password_keeps_local_default_outside_staging(): void
    {
        $this->assertSame('password', DemoPassword::resolve());
    }

    public function test_demo_password_is_required_and_long_enough_in_staging(): void
    {
        $this->actAsStaging();

        config(['staging.demo_password' => null]);
        try {
            DemoPassword::resolve();
            $this->fail('未設定なら止まるべき');
        } catch (RuntimeException) {
        }

        config(['staging.demo_password' => 'short']);
        $this->expectException(RuntimeException::class);
        DemoPassword::resolve();
    }

    public function test_development_admin_in_staging_uses_the_staging_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actAsStaging();
        config(['staging.demo_password' => 'staging-pass-1234', 'staging.basic_auth.user' => null]);

        $this->seed(DevelopmentAdminSeeder::class);

        // staging では CSRF が有効になるためログイン POST ではなくハッシュで確認する。
        $hash = (string) User::query()->where('email', config('dev_admin.email'))->value('password');
        $this->assertTrue(Hash::check('staging-pass-1234', $hash));
        $this->assertFalse(Hash::check('password', $hash));
    }
}
