<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Phase 9.6: 「メール・パスワード → 業務ロールのみ TOTP チャレンジ」フローの検証。
 */
final class PasswordTotpFlowTest extends TestCase
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

    // ---------- customer: チャレンジなし ----------

    public function test_customer_password_login_goes_straight_through(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill([
            'email_verified_at' => now(),
            'password' => Hash::make('customer-pass'),
        ])->save();

        $this->post('/login', [
            'email' => $customer->user->email,
            'password' => 'customer-pass',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($customer->user->fresh());
    }

    // ---------- staff / admin: チャレンジあり ----------

    public function test_staff_password_login_is_challenged_for_totp(): void
    {
        $staff = User::factory()->create(['password' => Hash::make('staff-pass')]);
        $staff->assignRole('staff');
        Staff::factory()->create(['user_id' => $staff->id]);
        $this->withTotp($staff);

        $this->post('/login', [
            'email' => $staff->email,
            'password' => 'staff-pass',
        ])->assertRedirect(route('two-factor.login'));

        // コード入力前はログイン確定していない
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_password_login_is_challenged_for_totp(): void
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-pass')]);
        $admin->assignRole('admin');
        $this->withTotp($admin);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'admin-pass',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_wrong_totp_code_is_rejected(): void
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-pass')]);
        $admin->assignRole('admin');
        $this->withTotp($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);

        $this->post('/two-factor-challenge', ['code' => '000000'])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_correct_totp_code_completes_login(): void
    {
        $admin = User::factory()->create([
            'password' => Hash::make('admin-pass'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');
        $this->withTotp($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $this->post('/two-factor-challenge', ['code' => $this->otp()]);

        $this->assertAuthenticatedAs($admin->fresh());
        $this->get('/admin')->assertOk();
    }

    // ---------- MFA 未設定の業務ロール ----------

    public function test_staff_without_totp_can_log_in_but_is_confined_to_setup(): void
    {
        $staff = User::factory()->create([
            'password' => Hash::make('staff-pass'),
            'email_verified_at' => now(),
        ]);
        $staff->assignRole('staff');
        Staff::factory()->create(['user_id' => $staff->id]);

        $this->post('/login', ['email' => $staff->email, 'password' => 'staff-pass']);

        $this->assertAuthenticatedAs($staff->fresh());
        $this->get('/admin')->assertRedirect(route('admin.mfa.show'));
    }

    // ---------- チャレンジ画面への直接アクセス ----------

    public function test_two_factor_challenge_is_not_reachable_without_primary_auth(): void
    {
        // 画面は主認証（login.id）が無いとログインへ戻される。
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));

        // POST も主認証が無ければ誰もログインさせない（コードの当て推量で突破できない）。
        $this->post('/two-factor-challenge', ['code' => '123456']);
        $this->assertGuest();
    }

    public function test_login_id_does_not_survive_logout(): void
    {
        $admin = User::factory()->create([
            'password' => Hash::make('admin-pass'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');
        $this->withTotp($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-pass']);
        $this->post('/two-factor-challenge', ['code' => $this->otp()]);
        $this->assertAuthenticatedAs($admin->fresh());

        $this->post('/logout');
        $this->assertGuest();
        $this->assertNull(session('login.id'));
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    }
}
