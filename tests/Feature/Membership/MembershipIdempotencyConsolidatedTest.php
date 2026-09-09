<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\CreateSubscriptionCommand;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\MembershipCheckoutSaga;
use App\Domain\Membership\MembershipIdempotencyKeyFactory;
use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Settings\Settings;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MembershipIdempotencyConsolidatedTest extends TestCase
{
    use DatabaseMigrations;

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);
        $this->seed([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
        ]);
        $this->gateway = app(FakeMembershipStripeGateway::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_subscription_create_retry_with_the_same_operation_id_does_not_duplicate(): void
    {
        $customer = Customer::factory()->create();
        $plan = MembershipPlan::factory()->create();
        $membership = app(MembershipCheckoutSaga::class)->execute($customer, $plan)->membership;
        $operationId = (string) $membership->membership_operation_id;
        $idempotencyKey = app(MembershipIdempotencyKeyFactory::class)
            ->subscriptionCreate($membership);

        $retried = $this->gateway->createSubscription(new CreateSubscriptionCommand(
            customerUserId: (int) $customer->user_id,
            stripeCustomerId: (string) $customer->fresh()->stripe_customer_id,
            priceId: (string) $plan->stripe_price_id,
            membershipOperationId: $operationId,
            idempotencyKey: $idempotencyKey,
        ));

        $this->assertSame($membership->stripe_subscription_id, $retried->stripeSubscriptionId);
        $this->assertSame(1, Membership::query()->where('customer_id', $customer->user_id)->count());
        $this->assertSame(2, $this->gateway->callCount('create_subscription'));
        foreach ($this->gateway->calls() as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
    }

    public function test_adjust_retry_with_the_same_operation_key_is_counted_once(): void
    {
        $membership = $this->membershipWithBalance(Customer::factory()->create(), 2);
        $operationKey = (string) Str::uuid();
        $ledger = app(MembershipLedgerService::class);

        $first = $ledger->adjust($membership, 1, $operationKey, 'retry 調整');
        $retried = $ledger->adjust($membership, 1, $operationKey, 'retry 調整');

        $this->assertSame($first->id, $retried->id);
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('dedupe_key', "adjust:{$operationKey}")
            ->count());
        $this->assertSame(3, $membership->fresh()->period_available);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'membership.adjusted')
            ->where('entity_id', (string) $membership->id)
            ->count());
    }

    public function test_cancel_retry_does_not_append_release_twice(): void
    {
        [$customer, $membership, $reservation] = $this->membershipReservation(
            MembershipNoShowPolicy::Consume,
            '2026-10-01 10:00:00',
        );
        $service = app(ReservationService::class);

        $service->cancel($reservation, 'retry cancel', $customer->user);

        try {
            $service->cancel($reservation, 'retry cancel', $customer->user);
            $this->fail('終端状態への cancel retry が拒否されませんでした。');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Release));
        $this->assertSame(0, $this->usageCount($membership, MembershipUsageType::Consume));
        $this->assertSame(4, $membership->fresh()->period_available);
        $this->assertSame(
            MembershipReservationUsageStatus::Released,
            $this->usage($reservation)->status,
        );
    }

    public function test_completed_retry_does_not_append_release_or_consume_twice(): void
    {
        [$customer, $membership, $reservation] = $this->membershipReservation(
            MembershipNoShowPolicy::Consume,
            '2026-10-01 11:00:00',
        );
        $service = app(ReservationService::class);

        $service->markCompleted($reservation, $customer->user);

        try {
            $service->markCompleted($reservation, $customer->user);
            $this->fail('終端状態への completed retry が拒否されませんでした。');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Release));
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Consume));
        $this->assertSame(3, $membership->fresh()->period_available);
        $this->assertSame(
            MembershipReservationUsageStatus::Consumed,
            $this->usage($reservation)->status,
        );
    }

    public function test_no_show_uses_the_consume_policy_snapshot(): void
    {
        [$customer, $membership, $reservation] = $this->membershipReservation(
            MembershipNoShowPolicy::Consume,
            '2026-10-01 12:00:00',
        );

        app(Settings::class)->set('membership.no_show_policy', MembershipNoShowPolicy::Restore->value);
        app(ReservationService::class)->markNoShow($reservation, $customer->user);

        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Release));
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Consume));
        $this->assertSame(3, $membership->fresh()->period_available);
        $this->assertSame(MembershipReservationUsageStatus::Consumed, $this->usage($reservation)->status);
    }

    public function test_no_show_uses_the_restore_policy_snapshot(): void
    {
        [$customer, $membership, $reservation] = $this->membershipReservation(
            MembershipNoShowPolicy::Restore,
            '2026-10-01 13:00:00',
        );

        app(Settings::class)->set('membership.no_show_policy', MembershipNoShowPolicy::Consume->value);
        app(ReservationService::class)->markNoShow($reservation, $customer->user);

        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Release));
        $this->assertSame(0, $this->usageCount($membership, MembershipUsageType::Consume));
        $this->assertSame(4, $membership->fresh()->period_available);
        $this->assertSame(MembershipReservationUsageStatus::Released, $this->usage($reservation)->status);
    }

    public function test_cross_period_cancel_through_reservation_service_does_not_increase_current_available(): void
    {
        [$customer, $membership, $reservation] = $this->membershipReservation(
            MembershipNoShowPolicy::Consume,
            '2026-10-01 14:00:00',
        );
        $membership->forceFill([
            'current_period_start' => '2026-10-01',
            'current_period_end' => '2026-11-01',
        ])->save();
        app(MembershipLedgerService::class)->grant($membership, '2026-10-01', 4);

        app(ReservationService::class)->cancel($reservation, '期またぎ retry', $customer->user);

        $this->assertSame(4, app(MembershipLedgerService::class)->available($membership));
        $this->assertSame(4, $membership->fresh()->period_available);
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('dedupe_key', "mbr-expire:{$reservation->id}")
            ->count());
    }

    public function test_membership_commands_do_not_bypass_the_admin_mfa_gate(): void
    {
        $admin = User::factory()->create(['two_factor_confirmed_at' => null]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/membership-plans')
            ->assertRedirect(route('admin.mfa.show'));
    }

    /** @return array{Customer, Membership, Reservation} */
    private function membershipReservation(
        MembershipNoShowPolicy $policy,
        string $startsAt,
    ): array {
        app(Settings::class)->set('membership.no_show_policy', $policy->value);
        [$customer, $service, $staff] = $this->reservationMasters();
        $membership = $this->membershipWithBalance($customer, 4);
        $reservation = app(ReservationService::class)->create($this->input(
            $customer,
            $service,
            $staff,
            $startsAt,
        ));

        return [$customer, $membership, $reservation];
    }

    private function membershipWithBalance(Customer $customer, int $balance): Membership
    {
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-09-01', $balance);

        return $membership;
    }

    /** @return array{Customer, Service, Staff} */
    private function reservationMasters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }

    private function input(
        Customer $customer,
        Service $service,
        Staff $staff,
        string $startsAt,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: Carbon::parse($startsAt)->toImmutable(),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: PaymentMethod::Membership,
        );
    }

    private function usage(Reservation $reservation): MembershipReservationUsage
    {
        return MembershipReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
    }

    private function usageCount(Membership $membership, MembershipUsageType $type): int
    {
        return MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', $type->value)
            ->count();
    }
}
