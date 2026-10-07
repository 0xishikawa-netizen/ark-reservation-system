<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipUsageTransaction;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 月額解約予定者が契約期末までは正当に予約でき、期末後の利用権を先取りする事故が
 * JSTの日付境界をまたいでも起きないことを証明する業務シナリオ。
 */
final class MembershipCancelingScenarioTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $now = Carbon::parse('2026-10-07 09:00:00', 'Asia/Tokyo')->utc();
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow(CarbonImmutable::instance($now));
        config()->set('reservation.slot_minutes', 15);
        app(Settings::class)->set('business_hours.open', '00:00');
        app(Settings::class)->set('business_hours.close', '23:59');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 事故防止: canceling の期末当日を誤って拒否せず、11月1日00:30 JSTを10月扱いして許可しない。
     */
    public function test_canceling_membership_allows_period_end_but_rejects_the_next_jst_business_date(): void
    {
        $customer = Customer::factory()->create();
        $customer->user->assignRole('customer');
        $plan = MembershipPlan::factory()->create([
            'usage_count_per_period' => 3,
            'is_active' => true,
        ]);
        $subscriptionId = 'sub_membership_canceling_scenario';
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'status' => MembershipStatus::Active->value,
            'stripe_subscription_id' => $subscriptionId,
            'current_period_start' => '2026-10-01',
            'current_period_end' => '2026-10-31',
            'period_available' => 0,
            'cancel_at_period_end' => false,
        ]);
        $gateway = app(FakeMembershipStripeGateway::class);
        $gateway->setSubscription($subscriptionId, new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: 'active',
            cancelAtPeriodEnd: false,
            currentPeriodStart: '2026-10-01',
            currentPeriodEnd: '2026-10-31',
            latestInvoiceStatus: 'paid',
        ));
        app(MembershipLedgerService::class)->grant($membership, '2026-10-01', 3);

        app(MembershipSubscriptionService::class)->requestCancelAtPeriodEnd($membership, $customer->user);
        $membership->refresh();
        $this->assertSame(MembershipStatus::Canceling, $membership->status);
        $this->assertTrue($membership->cancel_at_period_end);
        $this->assertSame(1, $gateway->callCount('set_cancel_at_period_end'));

        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        foreach ([
            ['2026-10-20', '09:00:00', '18:00:00'],
            ['2026-10-31', '09:00:00', '18:00:00'],
            ['2026-11-01', '00:00:00', '03:00:00'],
        ] as [$date, $start, $end]) {
            StaffShift::query()->create([
                'staff_id' => $staff->user_id,
                'work_date' => $date,
                'start_at' => $start,
                'end_at' => $end,
            ]);
        }

        foreach (['2026-10-20 10:00:00', '2026-10-31 10:00:00'] as $startsAt) {
            $this->actingAs($customer->user)->post('/reserve', [
                'service_id' => $service->id,
                'staff_id' => $staff->user_id,
                'starts_at' => $startsAt,
                'payment_method' => 'membership',
            ])->assertSessionHasNoErrors();
        }

        $reserveCount = MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', MembershipUsageType::Reserve->value)
            ->count();
        $this->assertSame(2, $reserveCount);
        $this->assertDatabaseCount('reservations', 2);

        $this->actingAs($customer->user)->postJson('/reserve', [
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'starts_at' => '2026-11-01 00:30:00',
            'payment_method' => 'membership',
            // 回数券・月額の残数不足／期間外は bootstrap/app.php で 409（競合）として返す既存仕様。
        ])->assertConflict();

        $this->assertDatabaseCount('reservations', 2);
        $this->assertSame($reserveCount, MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', MembershipUsageType::Reserve->value)
            ->count());
        $this->assertSame([
            '2026-10-20 10:00:00',
            '2026-10-31 10:00:00',
        ], Reservation::query()->orderBy('starts_at')->pluck('starts_at')->map(
            static fn ($value): string => CarbonImmutable::parse($value)->format('Y-m-d H:i:s'),
        )->all());
    }
}
