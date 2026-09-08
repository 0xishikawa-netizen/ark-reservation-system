<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipUsageTransaction;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MembershipAuthorizationTest extends TestCase
{
    // cancel / resume は FakeMembershipStripeGateway の transaction 外検査を通るため、
    // テスト全体を transaction で包まない DatabaseMigrations を使う。
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
        ]);
    }

    public function test_customer_portal_returns_only_the_authenticated_customers_membership(): void
    {
        $customerA = $this->customerUser();
        $customerB = $this->customerUser();
        $membershipA = Membership::factory()->create(['customer_id' => $customerA->user_id]);
        $membershipB = Membership::factory()->create(['customer_id' => $customerB->user_id]);

        $this->actingAs($customerA->user)
            ->get('/mypage/membership')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Membership/Index')
                ->where('membership.id', (int) $membershipA->id)
                ->whereNot('membership.id', (int) $membershipB->id));
    }

    public function test_customer_cancel_and_resume_only_change_their_own_membership(): void
    {
        $customerA = $this->customerUser();
        $customerB = $this->customerUser();
        $membershipA = Membership::factory()->create(['customer_id' => $customerA->user_id]);
        $membershipB = Membership::factory()->create(['customer_id' => $customerB->user_id]);

        $this->actingAs($customerA->user)
            ->post('/mypage/membership/cancel')
            ->assertRedirect();

        $this->assertSame(MembershipStatus::Canceling, $membershipA->fresh()->status);
        $this->assertTrue($membershipA->fresh()->cancel_at_period_end);
        $this->assertSame(MembershipStatus::Active, $membershipB->fresh()->status);
        $this->assertFalse($membershipB->fresh()->cancel_at_period_end);

        $this->actingAs($customerA->user)
            ->post('/mypage/membership/resume')
            ->assertRedirect();

        $this->assertSame(MembershipStatus::Active, $membershipA->fresh()->status);
        $this->assertFalse($membershipA->fresh()->cancel_at_period_end);
        $this->assertSame(MembershipStatus::Active, $membershipB->fresh()->status);
        $this->assertFalse($membershipB->fresh()->cancel_at_period_end);
    }

    public function test_customer_and_staff_cannot_adjust_a_membership(): void
    {
        $membership = $this->membershipWithBalance(2);

        foreach (['customer', 'staff'] as $role) {
            $actor = $this->userWithRole($role);

            $this->actingAs($actor)
                ->withSession($this->passwordConfirmedSession())
                ->post("/admin/memberships/{$membership->id}/adjust", $this->adjustPayload())
                ->assertForbidden();
        }

        $this->assertSame(0, $this->adjustCount($membership));
        $this->assertSame(2, $membership->fresh()->period_available);
    }

    public function test_manager_and_admin_adjust_require_reauthentication_and_write_audit(): void
    {
        $membership = $this->membershipWithBalance(2);

        foreach (['manager', 'admin'] as $role) {
            $actor = $this->userWithRole($role);
            $countBefore = $this->adjustCount($membership);

            $response = $this->actingAs($actor)
                ->withSession(['auth.password_confirmed_at' => null])
                ->post("/admin/memberships/{$membership->id}/adjust", $this->adjustPayload());

            $this->assertContains($response->getStatusCode(), [302, 423]);
            $this->assertSame($countBefore, $this->adjustCount($membership));

            $this->actingAs($actor)
                ->withSession($this->passwordConfirmedSession())
                ->post("/admin/memberships/{$membership->id}/adjust", $this->adjustPayload())
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $this->assertSame($countBefore + 1, $this->adjustCount($membership));
        }

        $this->assertSame(2, $this->adjustCount($membership));
        $this->assertSame(2, AuditLog::query()
            ->where('action', 'membership.adjusted')
            ->where('entity_id', (string) $membership->id)
            ->count());
    }

    public function test_adjust_validates_reason_delta_and_operation_key(): void
    {
        $manager = $this->userWithRole('manager');
        $membership = $this->membershipWithBalance(2);
        $uri = "/admin/memberships/{$membership->id}/adjust";

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post($uri, [
                'delta' => 1,
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertInvalid(['reason']);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post($uri, [
                'delta' => 0,
                'reason' => 'ゼロ調整',
                'operation_key' => (string) Str::uuid(),
            ])
            ->assertInvalid(['delta']);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post($uri, [
                'delta' => 1,
                'reason' => 'operation key 未指定',
            ])
            ->assertInvalid(['operation_key']);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post($uri, [
                'delta' => 1,
                'reason' => 'operation key 不正',
                'operation_key' => 'not-a-uuid',
            ])
            ->assertInvalid(['operation_key']);

        $this->assertSame(0, $this->adjustCount($membership));
    }

    public function test_membership_plan_routes_follow_the_admin_manager_permission_matrix(): void
    {
        foreach (['admin', 'manager'] as $role) {
            $actor = $this->userWithRole($role);

            $this->actingAs($actor)
                ->get('/admin/membership-plans')
                ->assertOk();
            $this->actingAs($actor)
                ->post('/admin/membership-plans', $this->planPayload("{$role} プラン"))
                ->assertRedirect();
        }

        foreach (['staff', 'customer'] as $role) {
            $actor = $this->userWithRole($role);

            $this->actingAs($actor)
                ->get('/admin/membership-plans')
                ->assertForbidden();
            $this->actingAs($actor)
                ->post('/admin/membership-plans', $this->planPayload("{$role} プラン"))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('membership_plans', 2);
    }

    public function test_customer_cannot_access_membership_admin_routes(): void
    {
        $customer = $this->customerUser();
        $membership = Membership::factory()->create(['customer_id' => $customer->user_id]);

        $this->actingAs($customer->user)
            ->get('/admin/membership-plans')
            ->assertForbidden();
        $this->actingAs($customer->user)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/memberships/{$membership->id}/adjust", $this->adjustPayload())
            ->assertForbidden();
    }

    public function test_adjust_without_csrf_token_returns_419(): void
    {
        $this->withMiddleware();

        // testing 環境の CSRF 省略をこのケースだけ解除する。
        $this->app->instance('env', 'csrf-testing');

        $manager = $this->userWithRole('manager');
        $membership = $this->membershipWithBalance(2);

        $this->actingAs($manager)
            ->withSession($this->passwordConfirmedSession())
            ->post("/admin/memberships/{$membership->id}/adjust", $this->adjustPayload())
            ->assertStatus(419);

        $this->assertSame(0, $this->adjustCount($membership));
    }

    public function test_customer_membership_response_does_not_leak_stripe_internals_or_phone(): void
    {
        config()->set('stripe.secret', 'sk_test_membership_internal_secret');
        $customer = $this->customerUser('090-1234-5678');
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'stripe_subscription_id' => 'sub_private_membership_value',
            'membership_operation_id' => '74c91aac-b7bd-4977-b23c-29886f407a7a',
            'needs_attention' => true,
        ]);

        $response = $this->actingAs($customer->user)
            ->get('/mypage/membership')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('membership.stripe_subscription_id')
                ->missing('membership.membership_operation_id')
                ->missing('membership.needs_attention'));
        $content = $response->getContent();

        $this->assertStringNotContainsString('STRIPE_SECRET', $content);
        $this->assertStringNotContainsString('sk_', $content);
        $this->assertStringNotContainsString('sk_test_membership_internal_secret', $content);
        $this->assertStringNotContainsString((string) $membership->stripe_subscription_id, $content);
        $this->assertStringNotContainsString('090-1234-5678', $content);
        $this->assertStringNotContainsString('09012345678', $content);
    }

    private function membershipWithBalance(int $balance): Membership
    {
        $membership = Membership::factory()->create([
            'current_period_start' => now()->startOfMonth()->toDateString(),
            'current_period_end' => now()->startOfMonth()->addMonth()->toDateString(),
            'period_available' => 0,
        ]);
        app(MembershipLedgerService::class)->grant(
            $membership,
            now()->startOfMonth()->toDateString(),
            $balance,
        );

        return $membership;
    }

    private function adjustCount(Membership $membership): int
    {
        return MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', MembershipUsageType::Adjust->value)
            ->count();
    }

    /** @return array{delta: int, reason: string, operation_key: string} */
    private function adjustPayload(): array
    {
        return [
            'delta' => 1,
            'reason' => '権限マトリクス調整',
            'operation_key' => (string) Str::uuid(),
        ];
    }

    /** @return array{name: string, price: int, usage_count_per_period: int, billing_interval: string, stripe_price_id: string, sort_order: int, is_active: bool} */
    private function planPayload(string $name): array
    {
        return [
            'name' => $name,
            'price' => 24_000,
            'usage_count_per_period' => 4,
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_'.Str::lower(Str::random(24)),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** @return array<string, int> */
    private function passwordConfirmedSession(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp];
    }

    private function customerUser(?string $phone = null): Customer
    {
        $customer = Customer::factory()->create(['phone' => $phone]);
        $customer->user->assignRole('customer');

        return $customer;
    }

    private function userWithRole(string $role): User
    {
        $user = $role === 'customer'
            ? Customer::factory()->create()->user
            : User::factory()->create();
        if ($role !== 'customer') {
            Staff::factory()->create(['user_id' => $user->id]);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }
}
