<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\MembershipCheckoutSaga;
use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MembershipLifecycleE2ETest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'whsec_test_membership_e2e_secret';

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 09:00:00');
        config()->set('stripe.webhook_secret', self::SECRET);
        config()->set('reservation.slot_minutes', 15);
        config()->set('reservation.allow_admin_free_time', false);
        $this->gateway = app(FakeMembershipStripeGateway::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_full_membership_lifecycle_reaches_canceling_portal_with_consumed_balance(): void
    {
        [$customer, $service, $staff] = $this->reservationMasters();
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => 4]);

        $membership = app(MembershipCheckoutSaga::class)->execute($customer, $plan);
        $subscriptionId = (string) $membership->stripe_subscription_id;
        $this->setStripe($subscriptionId, 'active');

        $this->postEvent(
            'evt_e2e_paid',
            'invoice.paid',
            $this->invoiceObject('in_e2e_paid', $subscriptionId),
        )->assertOk();

        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame(4, $membership->period_available);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->user_id,
            'kind' => PaymentKind::MembershipInvoice->value,
            'payment_operation_id' => 'inv:in_e2e_paid',
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Grant));

        $reservation = app(ReservationService::class)->create($this->input(
            $customer,
            $service,
            $staff,
            PaymentMethod::Membership,
            '2026-10-01 10:00:00',
        ));
        $usage = MembershipReservationUsage::query()
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
        $this->assertSame(MembershipReservationUsageStatus::Reserved, $usage->status);
        $this->assertSame(3, $membership->fresh()->period_available);
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Reserve));

        app(ReservationService::class)->markCompleted($reservation, $customer->user);

        $this->assertSame(
            MembershipReservationUsageStatus::Consumed,
            $usage->fresh()->status,
        );
        $this->assertSame(3, $membership->fresh()->period_available);
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Release));
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Consume));

        app(MembershipSubscriptionService::class)->requestCancelAtPeriodEnd(
            $membership,
            $customer->user,
        );

        $membership->refresh();
        $this->assertSame(MembershipStatus::Canceling, $membership->status);
        $this->assertTrue($membership->cancel_at_period_end);
        $this->assertTrue($membership->status->isBookable());

        $this->actingAs($customer->user)
            ->get('/mypage/membership')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Membership/Index')
                ->where('membership.status', MembershipStatus::Canceling->value)
                ->where('membership.cancel_at_period_end', true)
                ->where('membership.available', 3)
                ->where('membership.held', 0)
                ->missing('membership.stripe_subscription_id')
                ->missing('membership.needs_attention')
                ->missing('membership.membership_operation_id'));

        foreach ($this->gateway->calls() as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
    }

    public function test_duplicate_invoice_with_different_event_ids_records_one_payment_and_one_grant(): void
    {
        $membership = $this->membershipForWebhook('sub_e2e_duplicate');
        $this->setStripe('sub_e2e_duplicate', 'active');
        $invoice = $this->invoiceObject('in_e2e_duplicate', 'sub_e2e_duplicate');

        $this->postEvent('evt_e2e_duplicate_a', 'invoice.paid', $invoice)->assertOk();
        $this->postEvent('evt_e2e_duplicate_b', 'invoice.paid', $invoice)->assertOk();

        $this->assertSame(1, Payment::query()
            ->where('payment_operation_id', 'inv:in_e2e_duplicate')
            ->count());
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Grant));
        $this->assertSame(4, $membership->fresh()->period_available);
    }

    public function test_reversed_failed_then_paid_webhooks_end_active_without_duplicate_grant(): void
    {
        $membership = $this->membershipForWebhook('sub_e2e_reversed');

        $this->setStripe(
            'sub_e2e_reversed',
            'past_due',
            nextAttempt: '2026-09-13 09:00:00',
        );
        $this->postEvent(
            'evt_e2e_failed',
            'invoice.payment_failed',
            $this->invoiceObject('in_e2e_reversed', 'sub_e2e_reversed', 0),
        )->assertOk();
        $this->assertSame(MembershipStatus::Grace, $membership->fresh()->status);

        $this->setStripe('sub_e2e_reversed', 'active');
        $this->postEvent(
            'evt_e2e_recovered',
            'invoice.paid',
            $this->invoiceObject('in_e2e_reversed', 'sub_e2e_reversed'),
        )->assertOk();

        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertNull($membership->grace_until);
        $this->assertSame(1, $this->usageCount($membership, MembershipUsageType::Grant));
        $this->assertSame(4, $membership->period_available);
    }

    public function test_invoice_refund_record_does_not_revoke_membership_grant(): void
    {
        $membership = $this->membershipForWebhook('sub_e2e_refund');
        $this->setStripe('sub_e2e_refund', 'active');
        $this->postEvent(
            'evt_e2e_refund_paid',
            'invoice.paid',
            $this->invoiceObject('in_e2e_refund', 'sub_e2e_refund'),
        )->assertOk();
        $payment = Payment::query()->where('payment_operation_id', 'inv:in_e2e_refund')->firstOrFail();
        $availableBefore = $membership->fresh()->period_available;
        $grantCountBefore = $this->usageCount($membership, MembershipUsageType::Grant);

        PaymentRefund::factory()->create([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'status' => RefundStatus::Succeeded->value,
            'reason' => 'invoice 返金の非連動確認',
        ]);
        $payment->forceFill([
            'status' => PaymentStatus::Refunded->value,
            'refunded_amount' => $payment->amount,
        ])->save();

        $this->assertSame($grantCountBefore, $this->usageCount($membership, MembershipUsageType::Grant));
        $this->assertSame(0, MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', 'REVOKE')
            ->count());
        $this->assertSame($availableBefore, $membership->fresh()->period_available);
    }

    public function test_reconcile_recovers_local_pending_after_stripe_succeeded(): void
    {
        $membership = Membership::factory()->pending()->create([
            'stripe_subscription_id' => 'sub_e2e_reconcile',
            'status' => MembershipStatus::Pending->value,
            'current_period_start' => null,
            'current_period_end' => null,
            'needs_attention' => true,
        ]);
        $this->gateway->setSubscription('sub_e2e_reconcile', new SubscriptionResult(
            stripeSubscriptionId: 'sub_e2e_reconcile',
            stripeStatus: 'active',
            cancelAtPeriodEnd: false,
            currentPeriodStart: '2026-09-01',
            currentPeriodEnd: '2026-10-01',
            latestInvoiceStatus: 'open',
        ));

        $this->assertSame(0, Artisan::call('memberships:reconcile', ['--sync' => true]));

        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertFalse($membership->needs_attention);
        $this->assertNull($membership->pending_operation);
        $this->assertSame(0, $this->gateway->calls()[0]['transaction_level']);
    }

    public function test_card_booking_does_not_create_membership_entitlement_hold(): void
    {
        [$customer, $service, $staff] = $this->reservationMasters();
        $membership = $this->membershipWithGrant($customer, 4);
        $reserveCount = $this->usageCount($membership, MembershipUsageType::Reserve);

        $reservation = app(ReservationService::class)->create($this->input(
            $customer,
            $service,
            $staff,
            PaymentMethod::Single,
            '2026-10-01 10:00:00',
        ));

        $this->assertSame(PaymentMethod::Single, $reservation->payment_method);
        $this->assertDatabaseMissing('membership_reservation_usages', [
            'reservation_id' => $reservation->id,
        ]);
        $this->assertSame($reserveCount, $this->usageCount($membership, MembershipUsageType::Reserve));
        $this->assertSame(4, $membership->fresh()->period_available);
    }

    public function test_ticket_booking_does_not_touch_membership_ledger(): void
    {
        [$customer, $service, $staff] = $this->reservationMasters();
        $membership = $this->membershipWithGrant($customer, 4);
        $product = TicketProduct::factory()->create(['total_count' => 2]);
        app(TicketLedgerService::class)->grant(
            customer: $customer,
            product: $product,
            count: 2,
            operationKey: 'membership-e2e-ticket',
            reason: '非干渉テスト準備',
        );
        $membershipTransactionsBefore = MembershipUsageTransaction::query()->count();

        $reservation = app(ReservationService::class)->create($this->input(
            $customer,
            $service,
            $staff,
            PaymentMethod::Ticket,
            '2026-10-01 12:00:00',
        ));

        $this->assertSame(PaymentMethod::Ticket, $reservation->payment_method);
        $this->assertDatabaseMissing('membership_reservation_usages', [
            'reservation_id' => $reservation->id,
        ]);
        $this->assertSame($membershipTransactionsBefore, MembershipUsageTransaction::query()->count());
        $this->assertSame(1, TicketTransaction::query()->where('reservation_id', $reservation->id)->count());
        $this->assertSame(4, $membership->fresh()->period_available);
    }

    private function membershipForWebhook(string $subscriptionId): Membership
    {
        return Membership::factory()->create([
            'stripe_subscription_id' => $subscriptionId,
            'status' => MembershipStatus::Active->value,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
            'membership_plan_id' => MembershipPlan::factory()->create([
                'usage_count_per_period' => 4,
            ])->id,
        ]);
    }

    private function membershipWithGrant(Customer $customer, int $count): Membership
    {
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => 0,
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-09-01', $count);

        return $membership;
    }

    private function setStripe(
        string $subscriptionId,
        string $stripeStatus,
        bool $cancelAtPeriodEnd = false,
        ?string $nextAttempt = null,
    ): void {
        $this->gateway->setSubscription($subscriptionId, new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: $stripeStatus,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            currentPeriodStart: '2026-09-01',
            currentPeriodEnd: '2026-10-01',
            latestInvoiceStatus: $stripeStatus === 'active' ? 'paid' : 'open',
            nextPaymentAttempt: $nextAttempt,
        ));
    }

    /** @param  array<string, mixed>  $object */
    private function postEvent(string $id, string $type, array $object): TestResponse
    {
        $event = [
            'id' => $id,
            'type' => $type,
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => $object],
        ];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** @return array<string, int|string> */
    private function invoiceObject(string $invoiceId, string $subscriptionId, int $amountPaid = 24_000): array
    {
        return [
            'object' => 'invoice',
            'id' => $invoiceId,
            'subscription' => $subscriptionId,
            'amount_paid' => $amountPaid,
            'amount_due' => $amountPaid,
        ];
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
        PaymentMethod $paymentMethod,
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
            paymentMethod: $paymentMethod,
        );
    }

    private function usageCount(Membership $membership, MembershipUsageType $type): int
    {
        return MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', $type->value)
            ->count();
    }
}
