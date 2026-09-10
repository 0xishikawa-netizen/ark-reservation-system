<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserSocialAccount;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Google ログイン（Phase 9.6 / Socialite）の自動テスト。
 *
 * 重点: account takeover / silent linking / role escalation / MFA bypass /
 * OAuth state / provider id collision / session。
 */
final class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('google-oauth');
    }

    /** Socialite の Google ドライバをモックして、返す Google ユーザーを固定する。 */
    private function fakeGoogleUser(
        string $id = 'google-abc-123',
        string $email = 'newbie@example.com',
        bool $emailVerified = true,
        string $name = 'Google 太郎',
    ): void {
        $socialiteUser = (new SocialiteUser)->setRaw([
            'email_verified' => $emailVerified,
        ]);
        $socialiteUser->id = $id;
        $socialiteUser->email = $email;
        $socialiteUser->name = $name;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function failGoogleWith(\Throwable $e): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow($e);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    // ---------- redirect ----------

    public function test_google_redirect_sends_the_user_to_google(): void
    {
        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
        $this->assertSame('login', session('google_oauth.intent'));
    }

    public function test_authenticated_user_hitting_login_redirect_is_bounced_home(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->actingAs($user)->get('/auth/google/redirect')->assertRedirect('/');
    }

    // ---------- 新規 Google ユーザー ----------

    public function test_new_google_user_creates_a_customer_only_account(): void
    {
        $this->fakeGoogleUser(id: 'g-new-1', email: 'brand.new@example.com');
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect();

        $user = User::query()->where('email', 'brand.new@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasAnyRole(['staff', 'manager', 'admin']));
        $this->assertNotNull($user->email_verified_at, 'Google 検証済みメールなので verified 扱い');
        $this->assertNotNull($user->customer);
        $this->assertDatabaseHas('user_social_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'g-new-1',
            'user_id' => $user->id,
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_new_google_user_cannot_become_staff_or_admin(): void
    {
        $this->fakeGoogleUser(id: 'g-new-2', email: 'wannabe.admin@example.com');
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback');

        $user = User::query()->where('email', 'wannabe.admin@example.com')->firstOrFail();
        $this->assertSame(['customer'], $user->getRoleNames()->all());
    }

    public function test_same_provider_user_id_does_not_create_a_duplicate_user(): void
    {
        $this->fakeGoogleUser(id: 'g-dup', email: 'dup@example.com');
        session(['google_oauth.intent' => 'login']);
        $this->get('/auth/google/callback');

        $this->post('/logout');
        RateLimiter::clear('google-oauth');

        // 2 回目の Google ログイン（同じ provider_user_id）
        $this->fakeGoogleUser(id: 'g-dup', email: 'dup@example.com');
        session(['google_oauth.intent' => 'login']);
        $this->get('/auth/google/callback');

        $this->assertSame(1, User::query()->where('email', 'dup@example.com')->count());
        $this->assertSame(1, UserSocialAccount::query()->where('provider_user_id', 'g-dup')->count());
    }

    // ---------- 既存 linked Customer ----------

    public function test_linked_customer_logs_in_normally(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        UserSocialAccount::query()->create([
            'user_id' => $customer->user_id,
            'provider' => 'google',
            'provider_user_id' => 'g-linked',
            'provider_email' => $customer->user->email,
        ]);

        $this->fakeGoogleUser(id: 'g-linked', email: $customer->user->email);
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect();
        $this->assertAuthenticatedAs($customer->user->fresh());
    }

    // ---------- 既存 email との衝突（silent takeover 不可） ----------

    public function test_existing_customer_with_same_email_is_not_silently_taken_over(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $existing->assignRole('customer');
        Customer::factory()->create(['user_id' => $existing->id]);

        $this->fakeGoogleUser(id: 'g-collide', email: 'existing@example.com');
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect(route('auth.google.confirm'));

        $this->assertGuest();
        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-collide']);
        $this->assertIsArray(session('google_oauth.pending'));
    }

    public function test_existing_customer_links_after_confirming_their_password(): void
    {
        $existing = User::factory()->create([
            'email' => 'linkme@example.com',
            'password' => Hash::make('s3cret-pass'),
            'email_verified_at' => null,
        ]);
        $existing->assignRole('customer');
        Customer::factory()->create(['user_id' => $existing->id]);

        session(['google_oauth.pending' => [
            'provider_user_id' => 'g-confirm',
            'email' => 'linkme@example.com',
            'created_at' => now()->timestamp,
        ]]);

        $this->post('/auth/google/link-existing', [
            'email' => 'linkme@example.com',
            'password' => 's3cret-pass',
        ])->assertRedirect();

        $this->assertDatabaseHas('user_social_accounts', [
            'provider_user_id' => 'g-confirm',
            'user_id' => $existing->id,
        ]);
        $this->assertNotNull($existing->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($existing->fresh());
    }

    public function test_existing_customer_link_rejects_a_wrong_password(): void
    {
        $existing = User::factory()->create([
            'email' => 'wrongpw@example.com',
            'password' => Hash::make('right-pass'),
        ]);
        $existing->assignRole('customer');

        session(['google_oauth.pending' => [
            'provider_user_id' => 'g-wrongpw',
            'email' => 'wrongpw@example.com',
            'created_at' => now()->timestamp,
        ]]);

        $this->from('/auth/google/confirm')->post('/auth/google/link-existing', [
            'email' => 'wrongpw@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-wrongpw']);
    }

    // ---------- 特権アカウントの自動 link 禁止 ----------

    public function test_staff_email_collision_does_not_auto_link_or_log_in(): void
    {
        $staff = User::factory()->create(['email' => 'staff@example.com']);
        $staff->assignRole('staff');
        Staff::factory()->create(['user_id' => $staff->id]);

        $this->fakeGoogleUser(id: 'g-staff-collide', email: 'staff@example.com');
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-staff-collide']);
        $this->assertNull(session('google_oauth.pending'));
    }

    public function test_admin_email_collision_does_not_auto_link_or_log_in(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->assignRole('admin');

        $this->fakeGoogleUser(id: 'g-admin-collide', email: 'admin@example.com');
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-admin-collide']);
    }

    public function test_link_existing_route_refuses_privileged_accounts(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin2@example.com',
            'password' => Hash::make('admin-pass'),
        ]);
        $admin->assignRole('admin');

        session(['google_oauth.pending' => [
            'provider_user_id' => 'g-admin-linkexisting',
            'email' => 'admin2@example.com',
            'created_at' => now()->timestamp,
        ]]);

        $this->post('/auth/google/link-existing', [
            'email' => 'admin2@example.com',
            'password' => 'admin-pass',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-admin-linkexisting']);
    }

    // ---------- OAuth state / エラー ----------

    public function test_invalid_oauth_state_is_rejected_safely(): void
    {
        $this->failGoogleWith(new InvalidStateException);
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    public function test_callback_error_shows_a_safe_message(): void
    {
        $this->failGoogleWith(new \RuntimeException('token endpoint 500: secret leaked here'));
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $bag = session()->get('errors');
        $message = $bag->getBag('default')->first('google');
        $this->assertStringNotContainsString('secret leaked here', $message);
        $this->assertStringNotContainsString('500', $message);
        $this->assertGuest();
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogleUser(id: 'g-unverified', email: 'unverified@example.com', emailVerified: false);
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    // ---------- MFA 合流（bypass しない） ----------

    public function test_staff_google_login_is_sent_to_the_totp_challenge(): void
    {
        $staff = User::factory()->create([
            'email' => 'staff-totp@example.com',
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole('staff');
        Staff::factory()->create(['user_id' => $staff->id]);
        UserSocialAccount::query()->create([
            'user_id' => $staff->id,
            'provider' => 'google',
            'provider_user_id' => 'g-staff-totp',
            'provider_email' => $staff->email,
        ]);

        $this->fakeGoogleUser(id: 'g-staff-totp', email: $staff->email);
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));

        // まだログイン確定していない（TOTP コード入力前）
        $this->assertGuest();
        $this->assertSame($staff->id, session('login.id'));

        // /admin へ直接行っても入れない
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_google_login_without_totp_is_confined_to_mfa_setup(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin-nomfa@example.com',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');
        UserSocialAccount::query()->create([
            'user_id' => $admin->id,
            'provider' => 'google',
            'provider_user_id' => 'g-admin-nomfa',
            'provider_email' => $admin->email,
        ]);

        $this->fakeGoogleUser(id: 'g-admin-nomfa', email: $admin->email);
        session(['google_oauth.intent' => 'login']);

        $this->get('/auth/google/callback')->assertRedirect(route('admin.mfa.show'));

        // ログインはしているが /admin 本体はブロックされる
        $this->assertAuthenticatedAs($admin->fresh());
        $this->get('/admin')->assertRedirect(route('admin.mfa.show'));
    }

    public function test_customer_google_login_reaches_the_customer_portal(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        UserSocialAccount::query()->create([
            'user_id' => $customer->user_id,
            'provider' => 'google',
            'provider_user_id' => 'g-cust-portal',
            'provider_email' => $customer->user->email,
        ]);

        $this->fakeGoogleUser(id: 'g-cust-portal', email: $customer->user->email);
        session(['google_oauth.intent' => 'login']);
        $this->get('/auth/google/callback');

        $this->get('/')->assertOk();
    }

    // ---------- link / unlink（ログイン済み） ----------

    public function test_authenticated_user_can_link_google_from_settings(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill(['email_verified_at' => now()])->save();

        $this->fakeGoogleUser(id: 'g-link-settings', email: 'settings-link@example.com');
        session([
            'google_oauth.intent' => 'link',
            'google_oauth.link_user_id' => $customer->user_id,
            'google_oauth.return' => '/mypage/security',
        ]);

        $this->actingAs($customer->user)
            ->get('/auth/google/callback')
            ->assertRedirect('/mypage/security');

        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $customer->user_id,
            'provider_user_id' => 'g-link-settings',
        ]);
    }

    public function test_a_user_cannot_link_a_second_google_account(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        UserSocialAccount::query()->create([
            'user_id' => $customer->user_id,
            'provider' => 'google',
            'provider_user_id' => 'g-first',
            'provider_email' => 'first@example.com',
        ]);

        $this->fakeGoogleUser(id: 'g-second', email: 'second@example.com');
        session([
            'google_oauth.intent' => 'link',
            'google_oauth.link_user_id' => $customer->user_id,
            'google_oauth.return' => '/mypage/security',
        ]);

        $this->actingAs($customer->user)
            ->get('/auth/google/callback')
            ->assertSessionHasErrors('google');

        $this->assertSame(
            1,
            UserSocialAccount::query()->where('user_id', $customer->user_id)->count(),
            '2 個目の Google 連携が作られた',
        );
        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-second']);
    }

    public function test_unlink_clears_the_google_link_for_the_provider(): void
    {
        $user = User::factory()->create(['password' => Hash::make('has-pw')]);
        $user->assignRole('customer');
        Customer::factory()->create(['user_id' => $user->id]);
        UserSocialAccount::query()->create([
            'user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-a',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete('/auth/google/unlink')
            ->assertSessionHasNoErrors();

        // provider 単位で全消し（残存連携を残さない）。
        $this->assertSame(
            0,
            UserSocialAccount::query()->where('user_id', $user->id)->where('provider', 'google')->count(),
        );
    }

    public function test_linking_a_google_account_already_used_by_another_user_is_rejected(): void
    {
        $owner = Customer::factory()->create();
        UserSocialAccount::query()->create([
            'user_id' => $owner->user_id,
            'provider' => 'google',
            'provider_user_id' => 'g-taken',
            'provider_email' => 'owner@example.com',
        ]);

        $other = Customer::factory()->create();
        $other->user->assignRole('customer');
        $other->user->forceFill(['email_verified_at' => now()])->save();

        $this->fakeGoogleUser(id: 'g-taken', email: 'owner@example.com');
        session([
            'google_oauth.intent' => 'link',
            'google_oauth.link_user_id' => $other->user_id,
            'google_oauth.return' => '/mypage/security',
        ]);

        $this->actingAs($other->user)
            ->get('/auth/google/callback')
            ->assertSessionHasErrors('google');

        $this->assertSame(1, UserSocialAccount::query()->where('provider_user_id', 'g-taken')->count());
    }

    public function test_unlink_requires_a_password_to_be_set(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->assignRole('customer');
        Customer::factory()->create(['user_id' => $user->id]);
        UserSocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-nopw',
            'provider_email' => 'nopw@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete('/auth/google/unlink')
            ->assertSessionHasErrors('google');

        $this->assertDatabaseHas('user_social_accounts', ['provider_user_id' => 'g-nopw']);
    }

    public function test_unlink_succeeds_when_a_password_exists(): void
    {
        $user = User::factory()->create(['password' => Hash::make('has-password')]);
        $user->assignRole('customer');
        Customer::factory()->create(['user_id' => $user->id]);
        UserSocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-haspw',
            'provider_email' => 'haspw@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete('/auth/google/unlink')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('user_social_accounts', ['provider_user_id' => 'g-haspw']);
    }

    // ---------- DB 制約 ----------

    public function test_provider_and_provider_user_id_are_unique(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        UserSocialAccount::query()->create([
            'user_id' => $a->id, 'provider' => 'google', 'provider_user_id' => 'g-unique',
        ]);

        $this->expectException(QueryException::class);

        UserSocialAccount::query()->create([
            'user_id' => $b->id, 'provider' => 'google', 'provider_user_id' => 'g-unique',
        ]);
    }

    // ---------- credential 非露出 ----------

    public function test_google_client_secret_is_never_sent_to_the_frontend(): void
    {
        config()->set('services.google.client_secret', 'super-secret-value');

        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $customer->user->forceFill(['email_verified_at' => now()])->save();

        $html = $this->actingAs($customer->user)->get('/mypage/security')->getContent();

        $this->assertStringNotContainsString('super-secret-value', (string) $html);
    }
}
