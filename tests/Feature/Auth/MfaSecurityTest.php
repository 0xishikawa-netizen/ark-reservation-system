<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\Sms\FakeSmsSender;
use App\Domain\Auth\Sms\SmsSender;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 9.6: Passkey 撤去後の MFA（TOTP + SMS フォールバック）セキュリティ。
 */
final class MfaSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('mfa-sms-send:127.0.0.1');
    }

    // ---------- 自己ロックアウト対策（最重要） ----------

    public function test_staff_cannot_disable_their_only_mfa_method(): void
    {
        $user = $this->staffUser(confirmedTotp: true);

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->delete('/user/two-factor-authentication')
            ->assertSessionHasErrors('two_factor');

        $this->assertNotNull($user->refresh()->two_factor_confirmed_at, 'staff の TOTP が無効化された');
    }

    public function test_manager_and_admin_cannot_disable_totp(): void
    {
        foreach (['manager', 'admin'] as $role) {
            $user = $this->staffUser($role, confirmedTotp: true);

            $this->actingAs($user)
                ->withSession($this->confirmedPassword())
                ->delete('/user/two-factor-authentication')
                ->assertSessionHasErrors('two_factor');

            $this->assertNotNull($user->refresh()->two_factor_confirmed_at, $role);
        }
    }

    public function test_regenerating_the_totp_secret_forces_reconfirmation(): void
    {
        $user = $this->staffUser(confirmedTotp: true);

        // force=1 で秘密鍵を作り直す（端末変更などの再設定）。
        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->post('/user/two-factor-authentication', ['force' => '1'])
            ->assertSessionHasNoErrors();

        // 新しい秘密鍵は「未確認」に戻り、確認するまで /admin へ入れない。
        $this->assertNull($user->refresh()->two_factor_confirmed_at);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.mfa.show'));

        $log = AuditLog::query()->where('action', 'auth.two_factor_reset')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('secret', strtolower((string) $log->summary));
    }

    public function test_password_confirmation_is_rate_limited(): void
    {
        $user = $this->staffUser(confirmedTotp: true);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong'])
                ->assertStatus(302);
        }

        $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_customer_can_disable_their_optional_totp(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($customer->user)
            ->withSession($this->confirmedPassword())
            ->delete('/user/two-factor-authentication')
            ->assertSessionHasNoErrors();

        $this->assertNull($customer->user->refresh()->two_factor_confirmed_at);
    }

    // ---------- 電話番号（SMS フォールバック） ----------

    public function test_phone_registration_requires_reauthentication(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => null])
            ->post('/admin/mfa/phone', ['phone' => '09012345678'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, DB::table('mfa_sms_challenges')->count());
    }

    public function test_new_phone_is_not_verified_until_the_otp_is_confirmed(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone', ['phone' => '09012345678'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->staff->refresh()->phone_verified_at);
        $this->assertNull($user->staff->phone);

        $code = (string) $this->sms()->lastCode();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone/verify', ['code' => $code])
            ->assertSessionHasNoErrors();

        $staff = $user->staff->refresh();
        $this->assertNotNull($staff->phone_verified_at);
        $this->assertSame('09012345678', $staff->phone);
        $this->assertNotNull($staff->phone_hmac);
    }

    public function test_phone_change_with_a_wrong_code_does_not_switch_the_number(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone', ['phone' => '09012345678']);

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone/verify', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->staff->refresh()->phone_verified_at);
    }

    public function test_phone_change_is_audited_without_the_number(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone', ['phone' => '09012345678']);
        $code = (string) $this->sms()->lastCode();
        $this->actingAs($user)->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone/verify', ['code' => $code]);

        $log = AuditLog::query()->where('action', 'mfa.phone.changed')->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString('09012345678', (string) $log->summary);
    }

    public function test_customer_cannot_use_staff_mfa_endpoints(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        $this->actingAs($customer->user)
            ->withSession($this->confirmedPassword())
            ->get('/admin/mfa')
            ->assertForbidden();

        $this->actingAs($customer->user)
            ->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone', ['phone' => '09012345678'])
            ->assertForbidden();
    }

    // ---------- MFA 画面が秘密情報を漏らさない ----------

    public function test_mfa_page_never_exposes_the_raw_phone_number(): void
    {
        $user = $this->staffUser(confirmedTotp: true);
        Staff::query()->where('user_id', $user->id)->update([
            'phone' => encrypt('09012345678'),
            'phone_verified_at' => now(),
        ]);

        $html = (string) $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->get('/admin/mfa')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('09012345678', $html, '電話番号平文が画面に出ている');
        $this->assertStringNotContainsString('two_factor_secret', $html);
    }

    // ---------- Recovery Code（Fortify 標準の維持確認） ----------

    public function test_recovery_codes_are_not_stored_in_plaintext_columns(): void
    {
        $user = $this->staffUser();
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETVALUE'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertStringNotContainsString('SECRETVALUE', (string) $row->two_factor_secret);
        $this->assertStringNotContainsString('aaaa-bbbb', (string) $row->two_factor_recovery_codes);
    }

    // ---------- helpers ----------

    private function sms(): FakeSmsSender
    {
        $sender = app(SmsSender::class);
        $this->assertInstanceOf(FakeSmsSender::class, $sender);

        return $sender;
    }

    /** @return array<string, int> */
    private function confirmedPassword(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp];
    }

    private function staffUser(string $role = 'staff', bool $confirmedTotp = false): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Staff::factory()->create(['user_id' => $user->id]);

        if ($confirmedTotp) {
            $user->forceFill([
                'two_factor_secret' => encrypt('secret'),
                'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $user->refresh();
    }
}
