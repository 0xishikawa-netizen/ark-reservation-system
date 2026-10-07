<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\MembershipIdempotencyKeyFactory;
use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipReservationService;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Domain\Payment\Webhook\StripeWebhookProcessor;
use App\Enums\Membership\MembershipStatus;
use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipUsageTransaction;
use App\Models\Reservation;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6/7 独立 QA（Red Team）で確認した TRUE POSITIVE 修正の回帰テスト。
 * F-01 dahlia object shape / F-03 webhook 再処理 / F-04 stuck-pending 復旧 /
 * F-05 toggle idempotency key / F-07 canceling 期末 / F-10 曖昧 cancel / F-18 adjust key。
 */
final class MembershipRedTeamRegressionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 09:00:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function gateway(): FakeMembershipStripeGateway
    {
        return app(FakeMembershipStripeGateway::class);
    }

    // ---- F-04: 曖昧 create 後の stuck-pending を webhook metadata で復旧 ----

    public function test_f04_stuck_pending_membership_recovers_via_subscription_webhook_metadata(): void
    {
        $customer = Customer::factory()->create();
        $membership = Membership::factory()->pending()->create([
            'customer_id' => $customer->user_id,
            'stripe_subscription_id' => null,
            'needs_attention' => true,
        ]);

        $this->gateway()->setSubscription('sub_amb', new SubscriptionResult(
            stripeSubscriptionId: 'sub_amb',
            stripeStatus: 'incomplete_expired',
            cancelAtPeriodEnd: false,
        ));

        $result = app(StripeWebhookProcessor::class)->process([
            'id' => 'evt_amb_1',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => 'sub_amb',
                'object' => 'subscription',
                'metadata' => ['membership_id' => (string) $membership->id],
            ]],
        ]);

        $membership->refresh();

        $this->assertSame('processed', $result);
        $this->assertSame(MembershipStatus::Canceled, $membership->status);
        $this->assertSame('sub_amb', $membership->stripe_subscription_id);
        $this->assertFalse((bool) $membership->needs_attention);
    }

    public function test_f04_metadata_fallback_does_not_steal_another_customers_subscription(): void
    {
        $customer = Customer::factory()->create();
        $mine = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'stripe_subscription_id' => 'sub_mine',
            'status' => MembershipStatus::Active->value,
        ]);

        // 別 membership の subscription を、私の membership_id を騙る payload で送っても採用しない。
        $result = app(StripeWebhookProcessor::class)->process([
            'id' => 'evt_amb_2',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_other',
                'object' => 'subscription',
                'metadata' => ['membership_id' => (string) $mine->id],
            ]],
        ]);

        $mine->refresh();
        $this->assertSame('ignored', $result);
        $this->assertSame('sub_mine', $mine->stripe_subscription_id);
    }

    // ---- F-05: cancel/resume の idempotency key は時バケットで変わる ----

    public function test_f05_toggle_idempotency_keys_change_across_time_but_are_stable_within_the_hour(): void
    {
        $membership = Membership::factory()->create();
        $factory = app(MembershipIdempotencyKeyFactory::class);

        Carbon::setTestNow('2026-09-15 09:30:00');
        $cancelA = $factory->subscriptionCancel($membership);
        $cancelA2 = $factory->subscriptionCancel($membership);

        Carbon::setTestNow('2026-09-15 11:05:00');
        $cancelB = $factory->subscriptionCancel($membership);
        $create = $factory->subscriptionCreate($membership);
        $cancelNow = $factory->subscriptionCancelNow($membership);

        $this->assertSame($cancelA, $cancelA2, '同一時内は同じ key（二重送信を 1 回に収束）');
        $this->assertNotSame($cancelA, $cancelB, '時が変われば key も変わる（古い冪等応答の張り付き防止）');
        $this->assertStringNotContainsString(':2026', $create, 'create は固定 key（retry で新 subscription を作らない）');
        $this->assertStringNotContainsString(':2026', $cancelNow, 'cancel_now は固定 key');
    }

    // ---- F-07: canceling は current_period_end を過ぎたら予約不可 ----

    public function test_f07_canceling_membership_cannot_reserve_after_period_end(): void
    {
        $customer = Customer::factory()->create();
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => 4]);
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'status' => MembershipStatus::Canceling->value,
            'cancel_at_period_end' => true,
            'current_period_start' => '2026-08-01',
            'current_period_end' => '2026-09-01', // 予約日(2026-09-15)より前＝期末経過
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-08-01', 4);

        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'starts_at' => '2026-09-15 10:00:00',
            'ends_at' => '2026-09-15 11:00:00',
        ]);

        $this->expectException(InsufficientMembershipBalanceException::class);
        app(MembershipReservationService::class)->reserve($reservation);
    }

    public function test_f07_canceling_membership_can_still_reserve_before_period_end(): void
    {
        $customer = Customer::factory()->create();
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => 4]);
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'status' => MembershipStatus::Canceling->value,
            'cancel_at_period_end' => true,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01', // 予約日より後＝当期中
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-09-01', 4);

        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'starts_at' => '2026-09-20 10:00:00',
            'ends_at' => '2026-09-20 11:00:00',
        ]);
        $usage = app(MembershipReservationService::class)->reserve($reservation);

        $this->assertSame($membership->id, $usage->membership_id);
    }

    #[DataProvider('cancelingPeriodBoundaryCases')]
    public function test_f07_canceling_membership_uses_jst_today_and_reservation_business_date(
        string $nowJst,
        string $reservationStartsAtJst,
        string $periodEnd,
        bool $allowed,
        string $storedStartsAtUtc,
    ): void {
        $now = Carbon::parse($nowJst, 'Asia/Tokyo')->utc();
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow(CarbonImmutable::instance($now));
        $customer = Customer::factory()->create();
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => 4]);
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'status' => MembershipStatus::Canceling->value,
            'cancel_at_period_end' => true,
            'current_period_start' => '2026-01-01',
            'current_period_end' => $periodEnd,
        ]);
        app(MembershipLedgerService::class)->grant($membership, '2026-01-01', 4);

        $startsAt = Carbon::parse($reservationStartsAtJst, 'Asia/Tokyo')->utc();
        $reservation = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'starts_at' => $storedStartsAtUtc,
        ]);

        if ($allowed) {
            $usage = app(MembershipReservationService::class)->reserve($reservation);
            $this->assertSame($membership->id, $usage->membership_id);

            return;
        }

        try {
            app(MembershipReservationService::class)->reserve($reservation);
            $this->fail('解約予定の期末後に利用権予約ができました。');
        } catch (InsufficientMembershipBalanceException) {
            $this->assertDatabaseMissing('membership_reservation_usages', [
                'reservation_id' => $reservation->id,
            ]);
        }
    }

    /** @return array<string, array{string, string, string, bool, string}> */
    public static function cancelingPeriodBoundaryCases(): array
    {
        return [
            'today before end and reservation before end' => [
                '2026-09-15 09:00:00', '2026-09-20 10:00:00', '2026-09-30', true, '2026-09-20 01:00:00',
            ],
            'reservation on end date is allowed' => [
                '2026-09-15 09:00:00', '2026-09-30 10:00:00', '2026-09-30', true, '2026-09-30 01:00:00',
            ],
            'reservation after end date is rejected' => [
                '2026-09-15 09:00:00', '2026-10-01 10:00:00', '2026-09-30', false, '2026-10-01 01:00:00',
            ],
            'today on end date is allowed' => [
                '2026-09-30 09:00:00', '2026-09-30 10:00:00', '2026-09-30', true, '2026-09-30 01:00:00',
            ],
            'today after end date is rejected' => [
                '2026-10-01 09:00:00', '2026-09-30 10:00:00', '2026-09-30', false, '2026-09-30 01:00:00',
            ],
            'month boundary is rejected' => [
                '2026-10-15 09:00:00', '2026-11-01 10:00:00', '2026-10-31', false, '2026-11-01 01:00:00',
            ],
            'year boundary is rejected' => [
                '2026-12-15 09:00:00', '2027-01-01 10:00:00', '2026-12-31', false, '2027-01-01 01:00:00',
            ],
            'JST midnight belongs to next business date' => [
                '2026-09-15 09:00:00', '2026-10-01 00:30:00', '2026-09-30', false, '2026-09-30 15:30:00',
            ],
            'JST late night on end date is allowed' => [
                '2026-09-15 09:00:00', '2026-09-30 23:30:00', '2026-09-30', true, '2026-09-30 14:30:00',
            ],
        ];
    }

    // ---- F-10: 曖昧な cancel/resume/cancel-now は needs_attention を立てる ----

    public function test_f10_ambiguous_cancel_sets_needs_attention(): void
    {
        $membership = Membership::factory()->create([
            'status' => MembershipStatus::Active->value,
            'needs_attention' => false,
            'pending_operation' => null,
        ]);
        $this->gateway()->failNextToggleWith(new PaymentGatewayTimeoutException('timeout'));

        try {
            app(MembershipSubscriptionService::class)->requestCancelAtPeriodEnd($membership);
            $this->fail('例外が投げられませんでした。');
        } catch (PaymentGatewayTimeoutException) {
            // expected
        }

        $membership->refresh();
        $this->assertTrue((bool) $membership->needs_attention);
        $this->assertSame('cancel', $membership->pending_operation);
    }

    public function test_f10_ambiguous_cancel_now_sets_needs_attention(): void
    {
        $membership = Membership::factory()->create(['status' => MembershipStatus::Active->value]);
        $this->gateway()->failNextCancelNowWith(new PaymentGatewayTimeoutException('timeout'));

        try {
            app(MembershipSubscriptionService::class)->cancelNow($membership, '理由あり');
            $this->fail('例外が投げられませんでした。');
        } catch (PaymentGatewayTimeoutException) {
            // expected
        }

        $membership->refresh();
        $this->assertTrue((bool) $membership->needs_attention);
        $this->assertSame('cancel_now', $membership->pending_operation);
    }

    // ---- F-18: 空 operation key の ADJUST は弾く ----

    public function test_f18_adjust_rejects_empty_operation_key(): void
    {
        $membership = Membership::factory()->create();

        $this->expectException(ValidationException::class);
        app(MembershipLedgerService::class)->adjust($membership, 1, '   ', '理由');
    }

    // ---- F-03: 失敗として記録された webhook は再送で再処理される ----

    public function test_f03_failed_webhook_event_is_reprocessed_on_redelivery(): void
    {
        $customer = Customer::factory()->create();
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'stripe_subscription_id' => 'sub_reproc',
            'status' => MembershipStatus::Active->value,
        ]);

        // 1 回目の到着を「失敗」で記録済みの状態にする（初回処理が一時例外で落ちた想定）。
        (new WebhookEvent)->forceFill([
            'stripe_event_id' => 'evt_reproc_1',
            'type' => 'customer.subscription.updated',
            'status' => 'failed',
            'attempts' => 1,
            'received_at' => now(),
            'processed_at' => now(),
            'error' => 'RuntimeException',
        ])->save();

        $this->gateway()->setSubscription('sub_reproc', new SubscriptionResult(
            stripeSubscriptionId: 'sub_reproc',
            stripeStatus: 'active',
            cancelAtPeriodEnd: true, // resume/cancel 差分を作って再処理が実際に効いたと分かるようにする
            currentPeriodStart: '2026-09-01',
            currentPeriodEnd: '2026-10-01',
        ));

        $result = app(StripeWebhookProcessor::class)->process([
            'id' => 'evt_reproc_1', // 同じ event id の再送
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_reproc', 'object' => 'subscription']],
        ]);

        $membership->refresh();
        $event = WebhookEvent::query()->where('stripe_event_id', 'evt_reproc_1')->firstOrFail();

        $this->assertSame('processed', $result, '失敗記録は再送で再処理される（duplicate で握り潰さない）');
        $this->assertSame('processed', $event->status->value);
        $this->assertSame(2, (int) $event->attempts);
        $this->assertSame(MembershipStatus::Canceling, $membership->status);
    }

    public function test_f03_terminal_processed_event_is_still_a_no_op_duplicate(): void
    {
        (new WebhookEvent)->forceFill([
            'stripe_event_id' => 'evt_done_1',
            'type' => 'customer.subscription.updated',
            'status' => 'processed',
            'attempts' => 1,
            'received_at' => now(),
            'processed_at' => now(),
        ])->save();

        $result = app(StripeWebhookProcessor::class)->process([
            'id' => 'evt_done_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_x', 'object' => 'subscription']],
        ]);

        $this->assertSame('duplicate', $result);
        $this->assertSame(2, (int) WebhookEvent::query()->where('stripe_event_id', 'evt_done_1')->value('attempts'));
    }

    // ---- F-08: 同一 invoice の paid 記録は既存 failed 行へ収束し GRANT に到達 ----

    public function test_f08_invoice_paid_converges_onto_existing_failed_payment_and_grants(): void
    {
        $customer = Customer::factory()->create();
        $plan = MembershipPlan::factory()->create(['usage_count_per_period' => 4]);
        $membership = Membership::factory()->create([
            'customer_id' => $customer->user_id,
            'membership_plan_id' => $plan->id,
            'stripe_subscription_id' => 'sub_inv',
            'status' => MembershipStatus::Grace->value,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        $this->gateway()->setSubscription('sub_inv', new SubscriptionResult(
            stripeSubscriptionId: 'sub_inv',
            stripeStatus: 'active',
            cancelAtPeriodEnd: false,
            currentPeriodStart: '2026-09-01',
            currentPeriodEnd: '2026-10-01',
            latestInvoiceStatus: 'paid',
        ));

        $processor = app(StripeWebhookProcessor::class);
        // 先に payment_failed（failed 行を作る）→ 後から invoice.paid（同一 invoice）。
        $processor->process([
            'id' => 'evt_inv_fail',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_race', 'object' => 'invoice', 'subscription' => 'sub_inv',
                'amount_paid' => 0, 'amount_due' => 24000,
            ]],
        ]);
        $processor->process([
            'id' => 'evt_inv_paid',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_race', 'object' => 'invoice', 'subscription' => 'sub_inv',
                'amount_paid' => 24000, 'amount_due' => 24000,
            ]],
        ]);

        $this->assertDatabaseHas('payments', [
            'payment_operation_id' => 'inv:in_race',
            'status' => 'succeeded',
            'amount' => 24000,
        ]);
        $this->assertSame(1, MembershipUsageTransaction::query()
            ->where('membership_id', $membership->id)
            ->where('type', 'GRANT')
            ->where('period_start', '2026-09-01')
            ->count());
    }
}
