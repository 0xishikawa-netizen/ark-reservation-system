<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * 「この端末を信頼する」による TOTP チャレンジ省略のテスト。
 */
final class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $g2fa;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->g2fa = new Google2FA;
        $this->secret = $this->g2fa->generateSecretKey();
        RateLimiter::clear('login');
    }

    private function withTotp(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt($this->secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function otp(): string
    {
        return $this->g2fa->getCurrentOtp($this->secret);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-pass')]);
        $admin->assignRole('admin');
        $this->withTotp($admin);

        return $admin;
    }

    public function test_trusting_the_device_skips_totp_on_next_login(): void
    {
        $admin = $this->admin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => $this->otp(),
            'trust_device' => true,
        ]);

        $this->assertAuthenticatedAs($admin->fresh());
        $this->assertDatabaseCount('trusted_devices', 1);

        $cookie = $challengeResponse->getCookie('ark_trusted_device');
        $this->assertNotNull($cookie);

        $this->post('/logout');
        $this->assertGuest();

        // 同じ端末（信頼済みCookieを持ったまま）で再ログイン → TOTP チャレンジを求められない。
        $this->withCookie('ark_trusted_device', $cookie->getValue())
            ->post('/login', ['email' => $admin->email, 'password' => 'admin-pass'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_without_checking_trust_device_the_next_login_is_still_challenged(): void
    {
        $admin = $this->admin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $this->post('/two-factor-challenge', [
            'code' => $this->otp(),
            'trust_device' => false,
        ]);

        $this->assertDatabaseCount('trusted_devices', 0);

        $this->post('/logout');

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_expired_trusted_device_requires_totp_again(): void
    {
        $admin = $this->admin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => $this->otp(),
            'trust_device' => true,
        ]);
        $cookie = $challengeResponse->getCookie('ark_trusted_device');

        TrustedDevice::query()->update(['expires_at' => now()->subDay()]);

        $this->post('/logout');

        $this->withCookie('ark_trusted_device', $cookie->getValue())
            ->post('/login', ['email' => $admin->email, 'password' => 'admin-pass'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_a_different_users_trusted_device_cookie_does_not_bypass_totp(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $challengeResponse = $this->post('/two-factor-challenge', [
            'code' => $this->otp(),
            'trust_device' => true,
        ]);
        $cookie = $challengeResponse->getCookie('ark_trusted_device');
        $this->post('/logout');

        $this->withCookie('ark_trusted_device', $cookie->getValue())
            ->post('/login', ['email' => $otherAdmin->email, 'password' => 'admin-pass'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_two_factor_disabled_event_revokes_all_trusted_devices(): void
    {
        // 業務ロールは two-factor.disable 自体を叩けない（PreventStaffTotpDisable）ため、
        // リスナー（AuditAuthEvents::onTwoFactorAuthenticationDisabled）の挙動を直接検証する。
        $admin = $this->admin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $this->post('/two-factor-challenge', ['code' => $this->otp(), 'trust_device' => true]);
        $this->assertDatabaseCount('trusted_devices', 1);

        event(new TwoFactorAuthenticationDisabled($admin->fresh()));

        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_regenerating_totp_secret_revokes_all_trusted_devices(): void
    {
        $admin = $this->admin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $this->post('/two-factor-challenge', ['code' => $this->otp(), 'trust_device' => true]);
        $this->assertDatabaseCount('trusted_devices', 1);

        $this->actingAs($admin->fresh())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post('/user/two-factor-authentication', ['force' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('trusted_devices', 0);
    }
}
