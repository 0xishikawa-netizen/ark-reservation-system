<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Customers;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Payment\PaymentStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketWallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CustomerOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00:00'));
    }

    public function test_customer_role_cannot_view_admin_customer_detail(): void
    {
        $actor = $this->customer();
        $target = $this->customer();

        $this->actingAs($actor->user)
            ->get("/admin/customers/{$target->user_id}")
            ->assertForbidden();
    }

    public function test_admin_sees_recent_reservations_payment_totals_and_membership_summary(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $service = Service::factory()->create(['name' => 'コンディショニング']);
        $staff = Staff::factory()->create(['display_name' => '担当 スタッフ']);
        $reservations = collect();

        foreach (range(-1, 6) as $dayOffset) {
            $reservations->push(Reservation::factory()->create([
                'customer_id' => $customer->user_id,
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => now()->addDays($dayOffset),
            ]));
        }

        Payment::factory()->create([
            'reservation_id' => $reservations->last()->id,
            'customer_id' => $customer->user_id,
            'status' => PaymentStatus::Succeeded,
            'needs_attention' => false,
            'created_at' => now()->subHour(),
        ]);
        Payment::factory()->create([
            'reservation_id' => $reservations->last()->id,
            'customer_id' => $customer->user_id,
            'needs_attention' => true,
            'created_at' => now(),
        ]);
        TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'balance' => 3,
        ]);
        TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'balance' => 2,
        ]);
        TicketWallet::factory()->exhausted()->create([
            'customer_id' => $customer->user_id,
        ]);

        $expectedReservationIds = $reservations
            ->sortByDesc(fn (Reservation $reservation) => $reservation->starts_at)
            ->take(5)
            ->pluck('id')
            ->values()
            ->all();

        $this->actingAs($admin)
            ->get("/admin/customers/{$customer->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Show')
                ->where('customer.user_id', $customer->user_id)
                ->has('overview.recent_reservations', 5)
                ->where(
                    'overview.recent_reservations',
                    fn ($rows): bool => collect($rows)->pluck('id')->all() === $expectedReservationIds,
                )
                ->where('overview.reservation_totals.total', 8)
                ->where('overview.reservation_totals.upcoming', 6)
                ->where('overview.payments.total_count', 2)
                ->where('overview.payments.needs_attention_count', 1)
                ->where('overview.tickets.active_wallet_count', 2)
                ->where('overview.tickets.total_available', 5)
                ->where('overview.membership', null));

        $plan = MembershipPlan::factory()->create([
            'name' => '月4回プラン',
            'usage_count_per_period' => 4,
        ]);
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-09-01', 4);

        $this->actingAs($admin)
            ->get("/admin/customers/{$customer->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.membership.status', 'active')
                ->where('overview.membership.status_label', '有効')
                ->where('overview.membership.plan.name', '月4回プラン')
                ->where('overview.membership.available', 4));
    }

    public function test_customer_overview_does_not_mix_another_customers_reservations_or_payments(): void
    {
        $admin = $this->admin();
        $customerA = $this->customer();
        $customerB = $this->customer();
        $reservationA = Reservation::factory()->create(['customer_id' => $customerA->user_id]);
        $reservationB = Reservation::factory()->create(['customer_id' => $customerB->user_id]);
        $paymentA = Payment::factory()->create([
            'customer_id' => $customerA->user_id,
            'reservation_id' => $reservationA->id,
        ]);
        $paymentB = Payment::factory()->create([
            'customer_id' => $customerB->user_id,
            'reservation_id' => $reservationB->id,
        ]);

        $this->actingAs($admin)
            ->get("/admin/customers/{$customerA->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'overview.recent_reservations',
                    fn ($rows): bool => collect($rows)->pluck('id')->all() === [$reservationA->id],
                )
                ->where(
                    'overview.payments.recent',
                    fn ($rows): bool => collect($rows)->pluck('id')->all() === [$paymentA->id],
                )
                ->where('overview.reservation_totals.total', 1)
                ->where('overview.payments.total_count', 1));

        $this->assertNotSame($reservationA->id, $reservationB->id);
        $this->assertNotSame($paymentA->id, $paymentB->id);
    }

    public function test_customer_overview_never_exposes_stripe_internal_ids(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $reservation = Reservation::factory()->create(['customer_id' => $customer->user_id]);
        $priceId = 'price_overview_secret';
        $subscriptionId = 'sub_overview_secret';
        $paymentIntentId = 'pi_overview_secret';
        $plan = MembershipPlan::factory()->create(['stripe_price_id' => $priceId]);

        Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'stripe_subscription_id' => $subscriptionId,
        ]);
        Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'reservation_id' => $reservation->id,
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);

        $response = $this->actingAs($admin)
            ->get("/admin/customers/{$customer->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overview.membership')
                ->missing('overview.membership.plan.stripe_price_id')
                ->missing('overview.membership.stripe_subscription_id'));

        $content = $response->getContent();
        $this->assertStringNotContainsString($priceId, $content);
        $this->assertStringNotContainsString($subscriptionId, $content);
        $this->assertStringNotContainsString($paymentIntentId, $content);
    }

    public function test_payments_section_is_hidden_without_reservations_view_permission(): void
    {
        $staffRole = Role::findByName('staff');
        $staffRole->syncPermissions(['admin.access', 'customers.view']);

        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole($staffRole);

        $customer = $this->customer();
        Payment::factory()->create([
            'customer_id' => $customer->user_id,
            'needs_attention' => true,
        ]);

        $this->actingAs($staff)
            ->get("/admin/customers/{$customer->user_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Show')
                ->where('overview.payments', null)
                ->whereNot('overview.recent_reservations', null));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');

        return $customer;
    }
}
