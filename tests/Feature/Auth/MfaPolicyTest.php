<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\MfaPolicy;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MFA 要件判定の組合せ検証（Phase 9.6: TOTP 一本）。
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

    public function test_confirmed_totp_satisfies_the_requirement(): void
    {
        $user = $this->userWithRole('staff');
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $policy = app(MfaPolicy::class);

        $this->assertTrue($policy->isSatisfiedBy($user));
        $this->assertFalse($policy->needsSetup($user));
    }

    public function test_unconfirmed_totp_does_not_satisfy_the_requirement(): void
    {
        $user = $this->userWithRole('staff');
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->assertFalse(app(MfaPolicy::class)->isSatisfiedBy($user));
        $this->assertTrue(app(MfaPolicy::class)->needsSetup($user));
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

    // ---------- 最後の 1 手段（自己ロックアウト対策） ----------

    public function test_primary_method_count_reflects_confirmed_totp(): void
    {
        $user = $this->userWithRole('admin');
        $policy = app(MfaPolicy::class);

        $this->assertSame(0, $policy->primaryMethodCount($user));

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertSame(1, $policy->primaryMethodCount($user));
    }

    public function test_required_role_with_totp_would_remove_last_method_on_disable(): void
    {
        $user = $this->userWithRole('admin');
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->assertTrue(app(MfaPolicy::class)->wouldRemoveLastMethod($user));
    }

    public function test_customer_is_never_subject_to_the_last_method_guard(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->assertFalse(app(MfaPolicy::class)->wouldRemoveLastMethod($customer->user));
    }

    // ---------- 表示用要約 ----------

    public function test_summary_never_exposes_credentials_or_phone_numbers(): void
    {
        $user = $this->userWithRole('admin');
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
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
        $this->assertStringNotContainsString('secret', strtolower($encoded));
        $this->assertSame(
            ['required', 'satisfied', 'totp_confirmed', 'phone_verified'],
            array_keys($summary),
        );
    }

    // ---------- middleware 経由の実挙動 ----------

    public function test_staff_with_confirmed_totp_can_reach_admin(): void
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
}
