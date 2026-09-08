<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Domain\Membership\MembershipLedgerService;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 7 Customer Portal のオーナーシップ / IDOR / leak / 境界。
 * 「customer は自分自身のデータのみ」を実装レベルで検証する。
 */
final class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        return $customer;
    }

    // ---------- ダッシュボード ----------

    public function test_dashboard_renders_own_aggregated_data_only(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => 4]);

        $membership = Membership::factory()->create([
            'customer_id' => $me->user_id,
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-09-01', 4);

        // 他人の membership（ダッシュボードに出てはいけない）
        Membership::factory()->create(['customer_id' => $other->user_id, 'status' => 'active']);

        $this->actingAs($me->user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customer/Dashboard')
                ->where('membership.available', 4)
                ->where('membership.status', 'active')
                ->has('tickets')
                ->has('attention'));
    }

    public function test_dashboard_does_not_leak_stripe_internal_or_secret(): void
    {
        $me = $this->customer();
        $plan = MembershipPlan::factory()->create();
        Membership::factory()->create([
            'customer_id' => $me->user_id,
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'stripe_subscription_id' => 'sub_secret_123',
            'needs_attention' => true,
        ]);

        $html = $this->actingAs($me->user)->get('/')->getContent();

        $this->assertStringNotContainsString('sub_secret_123', $html);
        $this->assertStringNotContainsString('needs_attention', $html);
        $this->assertStringNotContainsString('membership_operation_id', $html);
        $this->assertStringNotContainsString((string) config('stripe.secret'), $html !== '' ? $html : 'x');
        $this->assertStringNotContainsString('sk_', $html);
    }

    // ---------- 予約のオーナーシップ ----------

    public function test_customer_cannot_view_another_customers_reservation(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $reservation = Reservation::factory()->create(['customer_id' => $other->user_id]);

        $this->actingAs($me->user)
            ->get("/mypage/reservations/{$reservation->id}")
            ->assertForbidden();
    }

    public function test_customer_cannot_cancel_or_reschedule_another_customers_reservation(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $reservation = Reservation::factory()->create([
            'customer_id' => $other->user_id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($me->user)
            ->delete("/mypage/reservations/{$reservation->id}", ['reason' => 'x'])
            ->assertForbidden();

        $this->actingAs($me->user)
            ->put("/mypage/reservations/{$reservation->id}", [
                'starts_at' => '2026-10-01 10:00:00',
                'version' => 0,
            ])
            ->assertForbidden();

        $this->assertSame('confirmed', $reservation->fresh()->status->value);
    }

    public function test_reservation_list_returns_only_own_reservations(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        Reservation::factory()->count(2)->create(['customer_id' => $me->user_id, 'starts_at' => now()->addDays(3)]);
        Reservation::factory()->create(['customer_id' => $other->user_id, 'starts_at' => now()->addDays(3)]);

        $this->actingAs($me->user)
            ->get('/mypage/reservations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customer/Reservations/Index')
                ->where('reservations.upcoming', fn ($rows) => count($rows) === 2));
    }

    // ---------- 決済のオーナーシップ / leak ----------

    public function test_customer_cannot_open_another_customers_checkout(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $reservation = Reservation::factory()->create([
            'customer_id' => $other->user_id,
            'payment_method' => 'single',
            'status' => 'pending_payment',
        ]);

        $this->actingAs($me->user)
            ->get("/mypage/reservations/{$reservation->id}/checkout")
            ->assertForbidden();
    }

    public function test_payment_history_shows_only_own_payments_without_stripe_internal(): void
    {
        $me = $this->customer();
        $other = $this->customer();

        (new Payment)->forceFill([
            'customer_id' => $me->user_id, 'reservation_id' => null, 'kind' => 'membership_invoice',
            'provider' => 'stripe', 'payment_operation_id' => 'inv:in_mine', 'amount' => 24000, 'currency' => 'jpy',
            'status' => 'succeeded', 'capture_method' => 'automatic', 'stripe_payment_intent_id' => 'pi_mine_secret',
            'stripe_charge_id' => 'ch_mine_secret', 'paid_at' => now(),
        ])->save();
        (new Payment)->forceFill([
            'customer_id' => $other->user_id, 'reservation_id' => null, 'kind' => 'membership_invoice',
            'provider' => 'stripe', 'payment_operation_id' => 'inv:in_other', 'amount' => 99999, 'currency' => 'jpy',
            'status' => 'succeeded', 'capture_method' => 'automatic',
        ])->save();

        $response = $this->actingAs($me->user)->get('/mypage/payments')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Customer/Payments/Index')
            ->where('payments', fn ($rows) => count($rows) === 1 && $rows[0]['amount'] === 24000));

        $html = $response->getContent();
        $this->assertStringNotContainsString('pi_mine_secret', $html);
        $this->assertStringNotContainsString('ch_mine_secret', $html);
        $this->assertStringNotContainsString('inv:in_mine', $html);
        $this->assertStringNotContainsString('99999', $html, '他人の支払いが出ていない');
    }

    // ---------- 利用権のオーナーシップ ----------

    public function test_customer_membership_page_shows_only_own_membership(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $plan = MembershipPlan::factory()->create(['name' => 'MINE PLAN']);
        Membership::factory()->create(['customer_id' => $me->user_id, 'membership_plan_id' => $plan->id, 'status' => 'active']);
        $otherPlan = MembershipPlan::factory()->create(['name' => 'OTHER PLAN']);
        Membership::factory()->create(['customer_id' => $other->user_id, 'membership_plan_id' => $otherPlan->id, 'status' => 'active']);

        $this->actingAs($me->user)
            ->get('/mypage/membership')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customer/Membership/Index')
                ->where('membership.plan.name', 'MINE PLAN'));
    }

    // ---------- admin 境界 ----------

    public function test_customer_cannot_reach_admin_area(): void
    {
        $me = $this->customer();

        $this->actingAs($me->user)->get('/admin')->assertForbidden();
        $this->actingAs($me->user)->get('/admin/membership-plans')->assertForbidden();
        $this->actingAs($me->user)->get('/admin/reservations')->assertForbidden();
    }

    public function test_customer_cannot_perform_admin_membership_mutation(): void
    {
        $me = $this->customer();
        $membership = Membership::factory()->create(['customer_id' => $me->user_id, 'status' => 'active', 'stripe_subscription_id' => 'sub_x']);

        $this->actingAs($me->user)
            ->post("/admin/memberships/{$membership->id}/adjust", [
                'delta' => 5,
                'reason' => 'self service',
                'operation_key' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, \App\Models\MembershipUsageTransaction::query()->where('type', 'ADJUST')->count());
    }
}
