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
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

final class MfaSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('mfa-sms-send:127.0.0.1');
    }

    // ---------- Passkey ルートの保護 ----------

    public function test_passkey_registration_options_require_authentication(): void
    {
        $this->getJson('/user/passkeys/options')->assertUnauthorized();
    }

    public function test_passkey_registration_requires_password_confirmation(): void
    {
        $user = $this->staffWithPasskey();

        // パスワード未確認では登録オプションを取得できない
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => null])
            ->get('/user/passkeys/options')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_passkey_login_options_are_available_to_guests(): void
    {
        // Passkey は「パスワードなしログイン」の入口なので guest から到達できる必要がある
        $this->get('/passkeys/login/options')->assertOk();
    }

    public function test_invalid_passkey_assertion_is_rejected(): void
    {
        $this->postJson('/passkeys/login', [
            'credential' => [
                'id' => 'bogus',
                'rawId' => 'bogus',
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => 'bogus',
                    'authenticatorData' => 'bogus',
                    'signature' => 'bogus',
                ],
            ],
        ])->assertStatus(422);

        $this->assertGuest();
    }

    // ---------- 自己ロックアウト対策（最重要） ----------

    public function test_last_mfa_method_cannot_be_deleted(): void
    {
        $user = $this->staffWithPasskey();
        $passkey = $user->passkeys()->firstOrFail();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->delete("/user/passkeys/{$passkey->id}")
            ->assertSessionHasErrors('passkey');

        $this->assertSame(1, $user->passkeys()->count(), '最後の MFA 手段が削除された');
    }

    public function test_passkey_can_be_deleted_when_another_method_remains(): void
    {
        $user = $this->staffWithPasskey();
        $this->givePasskey($user, 'credential-2');
        $passkey = $user->passkeys()->firstOrFail();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->delete("/user/passkeys/{$passkey->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_single_passkey_can_be_deleted_when_totp_is_configured(): void
    {
        $user = $this->staffWithPasskey();
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $passkey = $user->passkeys()->firstOrFail();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->delete("/user/passkeys/{$passkey->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_passkey_deletion_requires_password_confirmation(): void
    {
        $user = $this->staffWithPasskey();
        $this->givePasskey($user, 'credential-2');
        $passkey = $user->passkeys()->firstOrFail();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => null])
            ->delete("/user/passkeys/{$passkey->id}")
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(2, $user->passkeys()->count());
    }

    public function test_a_user_cannot_delete_another_users_passkey(): void
    {
        $owner = $this->staffWithPasskey();
        $this->givePasskey($owner, 'owner-2');
        $intruder = $this->staffWithPasskey();
        // 侵入者側にも複数登録させ、「最後の 1 つ」ガードではなく
        // 所有権チェックで弾かれることを確認する。
        $this->givePasskey($intruder, 'intruder-2');
        $passkey = $owner->passkeys()->firstOrFail();

        $this->actingAs($intruder)
            ->withSession($this->confirmedPassword())
            ->delete("/user/passkeys/{$passkey->id}")
            ->assertStatus(403);

        $this->assertSame(2, $owner->passkeys()->count());
    }

    // ---------- 監査 ----------

    public function test_passkey_events_are_audited_without_credential_material(): void
    {
        $user = $this->staffWithPasskey();
        $this->givePasskey($user, 'credential-2');
        $passkey = $user->passkeys()->firstOrFail();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->delete("/user/passkeys/{$passkey->id}");

        $log = AuditLog::query()->where('action', 'mfa.passkey.removed')->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString('credential', strtolower((string) $log->summary));
        $this->assertStringNotContainsString('publicKey', (string) $log->summary);
    }

    // ---------- 電話番号 ----------

    public function test_phone_registration_requires_reauthentication(): void
    {
        $user = $this->staffWithPasskey();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => null])
            ->post('/admin/mfa/phone', ['phone' => '09012345678'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, DB::table('mfa_sms_challenges')->count());
    }

    public function test_new_phone_is_not_verified_until_the_otp_is_confirmed(): void
    {
        $user = $this->staffWithPasskey();

        $this->actingAs($user)
            ->withSession($this->confirmedPassword())
            ->post('/admin/mfa/phone', ['phone' => '09012345678'])
            ->assertSessionHasNoErrors();

        // OTP 未検証の時点では確定させない
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
        // 等価検索用の HMAC が入り、平文の検索コピーは持たない
        $this->assertNotNull($staff->phone_hmac);
    }

    public function test_phone_change_with_a_wrong_code_does_not_switch_the_number(): void
    {
        $user = $this->staffWithPasskey();

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
        $user = $this->staffWithPasskey();

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

    public function test_mfa_page_never_exposes_credentials_or_the_raw_phone_number(): void
    {
        $user = $this->staffWithPasskey();
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
        $this->assertStringNotContainsString('fake-public-key', $html, 'credential が画面に出ている');
        $this->assertStringNotContainsString('credential_id', $html);
    }

    // ---------- Recovery Code（Fortify 標準の維持確認） ----------

    public function test_recovery_codes_are_not_stored_in_plaintext_columns(): void
    {
        $user = $this->staffWithPasskey();
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

    private function staffWithPasskey(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');
        Staff::factory()->create(['user_id' => $user->id]);
        $this->givePasskey($user, 'credential-'.$user->id);

        return $user->refresh();
    }

    private function givePasskey(User $user, string $credentialId): Passkey
    {
        $passkey = new Passkey([
            'name' => 'テスト端末',
            'credential_id' => $credentialId,
            'credential' => ['publicKey' => 'fake-public-key-for-tests'],
        ]);
        $passkey->user_id = $user->id;
        $passkey->save();

        return $passkey;
    }
}
