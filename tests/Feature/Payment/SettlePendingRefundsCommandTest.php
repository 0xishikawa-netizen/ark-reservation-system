<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Phase 9.5: timeout 等で pending のまま止まった返金の settle 経路。
 */
final class SettlePendingRefundsCommandTest extends TestCase
{
    use DatabaseMigrations;

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    private function capturedPayment(): Payment
    {
        return Payment::factory()->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 5000,
            'refunded_amount' => 0,
            'stripe_payment_intent_id' => 'pi_settle_'.fake()->unique()->numerify('########'),
            'stripe_charge_id' => 'ch_settle_'.fake()->unique()->numerify('########'),
        ]);
    }

    private function stuckRefund(Payment $payment, int $amount, int $ageMinutes): PaymentRefund
    {
        $refund = PaymentRefund::factory()->create([
            'payment_id' => $payment->id,
            'amount' => $amount,
            'status' => RefundStatus::Pending,
            'stripe_refund_id' => null,
            'created_by' => User::factory(),
        ]);

        // grace window の判定は updated_at を見る。
        PaymentRefund::query()->whereKey($refund->id)->update([
            'updated_at' => now()->subMinutes($ageMinutes),
        ]);

        return $refund->refresh();
    }

    public function test_it_settles_a_stuck_pending_refund_via_retry(): void
    {
        $payment = $this->capturedPayment();
        $refund = $this->stuckRefund($payment, 2000, ageMinutes: 30);

        $this->artisan('payments:settle-pending-refunds')->assertExitCode(0);

        $refund->refresh();
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertNotNull($refund->stripe_refund_id);
        $this->assertSame(2000, (int) $payment->refresh()->refunded_amount);
    }

    public function test_it_leaves_recent_pending_refunds_alone(): void
    {
        $payment = $this->capturedPayment();
        $refund = $this->stuckRefund($payment, 2000, ageMinutes: 1);

        $this->artisan('payments:settle-pending-refunds')->assertExitCode(0);

        $this->assertSame(RefundStatus::Pending, $refund->refresh()->status);
        $this->assertCount(0, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_a_declined_retry_does_not_fail_the_command(): void
    {
        $payment = $this->capturedPayment();
        $refund = $this->stuckRefund($payment, 2000, ageMinutes: 30);
        $this->gateway->queue(
            FakeStripeGateway::REFUND,
            new PaymentGatewayDeclinedException('card_declined'),
        );

        $this->artisan('payments:settle-pending-refunds')->assertExitCode(0);

        // 決定的失敗として記録され、pending ではなくなる（次回対象から外れる）。
        $this->assertNotSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(0, (int) $payment->refresh()->refunded_amount);
    }
}
