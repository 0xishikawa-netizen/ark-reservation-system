<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'limited@example.com',
            'password' => 'correct-password',
        ]);
        $key = Str::lower($user->email).'|127.0.0.1';
        RateLimiter::clear($key);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(302);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /**
     * 全面検証（2026-09-30）の再発防止：画面（Inertia）からの送信が試行回数超過になった時、
     * 素の 429 画面をモーダルで出さず、ログイン画面へ戻してメール欄に残り秒数つきの案内を出す。
     */
    public function test_inertia_login_throttle_returns_to_the_form_with_a_japanese_message(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear(Str::lower($user->email).'|127.0.0.1');
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => url('/login')];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'], $inertia)->assertStatus(302);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'], $inertia)
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression('/ログイン試行回数が上限を超えました。\d+ 秒後/u', (string) session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_registration_is_rate_limited_after_five_attempts(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $email = 'registration-limit@example.com';
        $payload = [
            'name' => '予約 太郎',
            'kana' => 'ヨヤク タロウ',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
        RateLimiter::clear(Str::lower($email).'|127.0.0.1');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/register', $payload)->assertStatus(302);
        }

        $this->post('/register', $payload)->assertStatus(429);
    }

    public function test_password_reset_requests_are_rate_limited_after_five_attempts(): void
    {
        $email = 'password-reset-limit@example.com';
        User::factory()->create(['email' => $email]);
        RateLimiter::clear(Str::lower($email).'|127.0.0.1');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/forgot-password', [
                'email' => $email,
            ])->assertStatus(302);
        }

        $this->post('/forgot-password', [
            'email' => $email,
        ])->assertStatus(429);
    }
}
