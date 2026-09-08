<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\MfaPolicy;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

/**
 * MFA 要件判定の組合せ検証。
 *
 * ここが誤ると **全スタッフがロックアウトされる**ため、
 * 「どの手段を持つとき管理画面に入れるか」を全パターンで固定する。
 */
final class MfaPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ---------- 要件の有無 ----------

    public function test_staff_roles_require_mfa_and_customers_do_not(): void
    {
        $policy = app(MfaPolicy::class);

        foreach (['staff', 'manager', 'admin'] as $role) {
            $this->assertTrue($policy->isRequiredFor($this->userWithRole($role)), $role);
        }

        $this->assertFalse($policy->isRequiredFor($this->userWithRole('customer')));
        $this->assertFalse($policy->isRequiredFor(null));
    }

    // ---------- 満たすかどうかの組合せ ----------

    public function test_passkey_alone_satisfies_the_requirement(): void
    {
        $user = $this->userWithRole('staff');
        $this->givePasskey($user);

        $policy = app(MfaPolicy::class);

        $this->assertTrue($policy->isSatisfiedBy($user));
        $this->assertFalse($policy->needsSetup($user));
    }

    public function test_confirmed_totp_alone_satisfies_the_requirement(): void
    {
        $user = $this->userWithRole('staff');
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->assertTrue(app(MfaPolicy::class)->isSatisfiedBy($user));
    }

    public function test_unconfirmed_totp_does_not_satisfy_the_requirement(): void
    {
        $user = $this->userWithRole('staff');
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->assertFalse(app(MfaPolicy::class)->isSatisfiedBy($user));
    }

    /**
     * SMS は SIM スワップ耐性が無いため補助手段。
     * **これ単独で MFA 要件を満たしてはいけない。**
     */
    public function test_verified_phone_alone_does_not_satisfy_the_requirement(): void
    {
        $user = $this->userWithRole('staff');
        Staff::factory()->create([
            'user_id' => $user->id,
            'phone' => '09012345678',
            'phone_verified_at' => now(),
        ]);
        $user->refresh();

        $policy = app(MfaPolicy::class);

        $this->assertTrue($policy->hasVerifiedPhone($user));
        $this->assertFalse($policy->isSatisfiedBy($user), 'SMS だけで MFA 要件を満たしてはいけない');
        $this->assertTrue($policy->needsSetup($user));
    }

    public function test_no_method_needs_setup(): void
    {
        $user = $this->userWithRole('staff');

        $this->assertTrue(app(MfaPolicy::class)->needsSetup($user));
    }

    public function test_customer_never_needs_setup_even_without_any_method(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        $this->assertFalse(app(MfaPolicy::class)->needsSetup($customer->user));
    }

    // ---------- 最後の 1 手段 ----------

    public function test_last_method_detection_counts_passkeys_and_totp(): void
    {
        $user = $this->userWithRole('admin');
        $policy = app(MfaPolicy::class);

        $this->givePasskey($user, 'key-1');
        $this->assertSame(1, $policy->primaryMethodCount($user));
        $this->assertTrue($policy->wouldRemoveLastMethod($user));

        $this->givePasskey($user, 'key-2');
        $this->assertSame(2, $policy->primaryMethodCount($user));
        $this->assertFalse($policy->wouldRemoveLastMethod($user));
    }

    public function test_totp_counts_as_a_fallback_so_a_single_passkey_can_be_removed(): void
    {
        $user = $this->userWithRole('admin');
        $this->givePasskey($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->assertFalse(app(MfaPolicy::class)->wouldRemoveLastMethod($user));
    }

    // ---------- 移行導線 ----------

    public function test_totp_only_user_is_prompted_to_add_a_passkey(): void
    {
        $user = $this->userWithRole('manager');
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $policy = app(MfaPolicy::class);

        $this->assertTrue($policy->isSatisfiedBy($user), '移行期の TOTP ユーザーはログインできる必要がある');
        $this->assertTrue($policy->shouldPromotePasskey($user));
    }

    public function test_summary_never_exposes_credentials_or_phone_numbers(): void
    {
        $user = $this->userWithRole('admin');
        $this->givePasskey($user);
        Staff::factory()->create([
            'user_id' => $user->id,
            'phone' => '09012345678',
            'phone_verified_at' => now(),
        ]);
        $user->refresh();

        $summary = app(MfaPolicy::class)->summaryFor($user);
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('09012345678', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
        $this->assertSame(
            ['required', 'satisfied', 'passkey_count', 'totp_confirmed', 'phone_verified', 'should_promote_passkey'],
            array_keys($summary),
        );
    }

    // ---------- middleware 経由の実挙動 ----------

    public function test_staff_with_only_a_passkey_is_not_sent_to_totp_setup(): void
    {
        $user = $this->userWithRole('staff');
        $this->givePasskey($user);

        // Passkey だけのユーザーが setup へリダイレクトされない（Phase 5.5 の回帰防止）
        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_staff_with_only_totp_can_still_reach_admin_during_migration(): void
    {
        $user = $this->userWithRole('staff');
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_staff_with_no_method_is_redirected_to_mfa_setup(): void
    {
        $user = $this->userWithRole('staff');

        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.mfa.show'));
    }

    public function test_mfa_setup_page_itself_is_reachable_without_mfa(): void
    {
        $user = $this->userWithRole('staff');

        $this->actingAs($user)->get('/admin/mfa')->assertOk();
    }

    // ---------- helpers ----------

    private function userWithRole(string $role): User
    {
        $user = $role === 'customer'
            ? Customer::factory()->create()->user
            : User::factory()->create();
        $user->assignRole($role);

        return $user->refresh();
    }

    private function givePasskey(User $user, string $credentialId = 'credential-1'): Passkey
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
