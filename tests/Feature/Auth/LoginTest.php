<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ログイン可否（is_active）のテスト。§ログインできるスタッフを設定する画面。
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('staff');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_log_in_even_with_correct_password(): void
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
            'is_active' => false,
        ]);
        $user->assignRole('staff');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_wrong_password_still_fails_normally_for_active_user(): void
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('staff');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivating_a_logged_in_staff_forces_logout_on_next_admin_request(): void
    {
        $user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('staff');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/admin')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
