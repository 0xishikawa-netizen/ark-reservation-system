<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\Dto\RefundResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Domain\Payment\IdempotencyKeyFactory;
use App\Domain\Payment\PaymentService;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use DatabaseMigrations;

    private FakeStripeGateway $gateway;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
        $this->service = app(PaymentService::class);
    }

    public function test_idempotency_keys_use_only_persisted_operation_ids(): void
    {
        $payment = Payment::factory()->create([
            'payment_operation_id' => '11111111-2222-4333-8444-555555555555',
        ]);
        $refund = PaymentRefund::factory()->create([
            'payment_id' => $payment->id,
            'refund_operation_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        ]);
        $factory = app(IdempotencyKeyFactory::class);

        // メモリ上の値を改変しても、key の根は必ず DB の永続値から読む。
        $payment->payment_operation_id = 'mutated-in-memory';
        $refund->refund_operation_id = 'mutated-in-memory';

        $this->assertSame(
            'pi-create:11111111-2222-4333-8444-555555555555',
            $factory->paymentIntentCreate($payment),
        );
        $this->assertSame(
            'pi-capture:11111111-2222-4333-8444-555555555555',
            $factory->paymentIntentCapture($payment),
        );
        $this->assertSame(
            'pi-cancel:11111111-2222-4333-8444-555555555555',
            $factory->paymentIntentCancel($payment),
        );
        $this->assertSame(
            'refund:aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            $factory->refund($refund),
        );
    }

    public function test_create_twice_creates_only_one_payment_intent_and_keeps_one_payment_row(): void
    {
        $payment = Payment::factory()->create([
            'payment_operation_id' => '11111111-2222-4333-8444-555555555555',
        ]);

        $first = $this->service->createIntent($payment);
        $second = $this->service->createIntent($payment);

        $this->assertSame($first->stripe_payment_intent_id, $second->stripe_payment_intent_id);
        $this->assertSame(1, Payment::query()->count());
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CREATE));
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::RETRIEVE));
        $this->assertSame(
            'pi-create:11111111-2222-4333-8444-555555555555',
            $this->gateway->callsFor(FakeStripeGateway::CREATE)[0]['idempotency_key'],
        );
        $this->assertSame([
            'reservation_id' => (string) $payment->reservation_id,
            'payment_id' => (string) $payment->id,
            'payment_operation_id' => '11111111-2222-4333-8444-555555555555',
        ], $this->gateway->lastCreatePaymentIntentCommand()?->metadata);
        $this->assertSame('manual', $this->gateway->lastCreatePaymentIntentCommand()?->captureMethod);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.created')->count());
        $this->assertArrayNotHasKey('client_secret', $second->getAttributes());
        $this->assertStringNotContainsString(
            'secret',
            (string) AuditLog::query()->where('action', 'payment.created')->value('summary'),
        );
    }

    public function test_capture_timeout_does_not_fail_and_retry_uses_the_same_key(): void
    {
        $payment = $this->paymentWithIntent(PaymentStatus::Authorized);
        $result = $this->intentResult($payment, 'succeeded', amountReceived: (int) $payment->amount);
        $this->gateway->queueAmbiguousTimeout(FakeStripeGateway::CAPTURE, $result);

        try {
            $this->service->capture($payment);
            $this->fail('timeout が再送出されませんでした。');
        } catch (PaymentGatewayTimeoutException) {
            $payment->refresh();
            $this->assertSame(PaymentStatus::Authorized, $payment->status);
            $this->assertTrue($payment->needs_attention);
            $this->assertSame('ambiguous_timeout', $payment->failure_code);
        }

        $retried = $this->service->capture($payment);
        $calls = $this->gateway->callsFor(FakeStripeGateway::CAPTURE);

        $this->assertSame(PaymentStatus::Succeeded, $retried->status);
        $this->assertFalse($retried->needs_attention);
        $this->assertNotNull($retried->paid_at);
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0]['idempotency_key'], $calls[1]['idempotency_key']);
        $this->assertSame([0, 0], array_column($calls, 'transaction_level'));

        $paidAt = $retried->paid_at?->toISOString();
        $again = $this->service->capture($retried);
        $this->assertSame($paidAt, $again->paid_at?->toISOString());
        $this->assertCount(2, $this->gateway->callsFor(FakeStripeGateway::CAPTURE));
    }

    public function test_create_timeout_retries_with_same_key_and_does_not_create_another_intent(): void
    {
        $payment = Payment::factory()->create([
            'payment_operation_id' => '11111111-2222-4333-8444-555555555555',
        ]);
        $result = $this->intentResult($payment, 'requires_payment_method', id: 'pi_ambiguous_create');
        $this->gateway->queueAmbiguousTimeout(FakeStripeGateway::CREATE, $result);

        try {
            $this->service->createIntent($payment);
            $this->fail('timeout が再送出されませんでした。');
        } catch (PaymentGatewayTimeoutException) {
            $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
            $this->assertTrue($payment->needs_attention);
            $this->assertNull($payment->stripe_payment_intent_id);
        }

        $retried = $this->service->createIntent($payment);
        $calls = $this->gateway->callsFor(FakeStripeGateway::CREATE);

        $this->assertSame('pi_ambiguous_create', $retried->stripe_payment_intent_id);
        $this->assertFalse($retried->needs_attention);
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0]['idempotency_key'], $calls[1]['idempotency_key']);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_decline_is_a_deterministic_failure(): void
    {
        $payment = Payment::factory()->create();
        $this->gateway->queue(
            FakeStripeGateway::CREATE,
            new PaymentGatewayDeclinedException('card_declined'),
        );

        try {
            $this->service->createIntent($payment);
            $this->fail('decline が再送出されませんでした。');
        } catch (PaymentGatewayDeclinedException) {
            $payment->refresh();
            $this->assertSame(PaymentStatus::Failed, $payment->status);
            $this->assertFalse($payment->needs_attention);
            $this->assertSame('card_declined', $payment->failure_code);
            $this->assertSame(1, AuditLog::query()->where('action', 'payment.failed')->count());
        }
    }

    public function test_authorize_sync_uses_stripe_current_state(): void
    {
        $payment = $this->paymentWithIntent(PaymentStatus::Pending);
        $this->gateway->queue(FakeStripeGateway::RETRIEVE, $this->intentResult($payment, 'requires_capture'));

        $authorized = $this->service->authorizeSync($payment);

        $this->assertSame(PaymentStatus::Authorized, $authorized->status);
        $this->assertNotNull($authorized->authorized_at);
        $this->assertNotNull($authorized->last_synced_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.authorized')->count());
    }

    public function test_cancel_is_idempotent(): void
    {
        $payment = $this->paymentWithIntent(PaymentStatus::Authorized);
        $this->gateway->queue(FakeStripeGateway::CANCEL, $this->intentResult($payment, 'canceled'));

        $first = $this->service->cancel($payment);
        $voidedAt = $first->voided_at?->toISOString();
        $second = $this->service->cancel($payment);

        $this->assertSame(PaymentStatus::Voided, $second->status);
        $this->assertSame($voidedAt, $second->voided_at?->toISOString());
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CANCEL));
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.canceled')->count());
    }

    public function test_refund_timeout_retries_the_same_refund_without_double_refunding(): void
    {
        $actor = User::factory()->create();
        $payment = $this->paymentWithIntent(PaymentStatus::Succeeded, amount: 1000);
        $result = new RefundResult(
            id: 're_ambiguous',
            status: 'succeeded',
            amount: 600,
            currency: 'jpy',
            paymentIntentId: $payment->stripe_payment_intent_id,
        );
        $this->gateway->queueAmbiguousTimeout(FakeStripeGateway::REFUND, $result);

        try {
            $this->service->refund($payment, 600, '顧客都合', $actor);
            $this->fail('timeout が再送出されませんでした。');
        } catch (PaymentGatewayTimeoutException) {
            $refund = PaymentRefund::query()->sole();
            $this->assertSame(RefundStatus::Pending, $refund->status);
            $this->assertSame('ambiguous_timeout', $refund->failure_code);
            $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
            $this->assertSame(0, $payment->refunded_amount);
            $this->assertTrue($payment->needs_attention);
        }

        $refund = PaymentRefund::query()->sole();
        $completed = $this->service->retryRefund($refund);
        $calls = $this->gateway->callsFor(FakeStripeGateway::REFUND);

        $this->assertSame(RefundStatus::Succeeded, $completed->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        $this->assertSame(600, $payment->refunded_amount);
        $this->assertCount(1, PaymentRefund::query()->get());
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0]['idempotency_key'], $calls[1]['idempotency_key']);

        $this->service->retryRefund($completed);
        $this->assertSame(600, $payment->refresh()->refunded_amount);
        $this->assertCount(2, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_excess_refund_is_rejected_as_validation_error(): void
    {
        $actor = User::factory()->create();
        $payment = $this->paymentWithIntent(PaymentStatus::Succeeded, amount: 1000);
        $this->gateway->queue(FakeStripeGateway::REFUND, new RefundResult(
            id: 're_first',
            status: 'succeeded',
            amount: 600,
            currency: 'jpy',
            paymentIntentId: $payment->stripe_payment_intent_id,
        ));
        $this->service->refund($payment, 600, '一部返金', $actor);

        try {
            $this->service->refund($payment, 401, '超過返金', $actor);
            $this->fail('過剰返金が拒否されませんでした。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(600, $payment->refresh()->refunded_amount);
        $this->assertCount(1, PaymentRefund::query()->get());
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_two_distinct_partial_refunds_with_same_reason_have_independent_operation_ids(): void
    {
        $actor = User::factory()->create();
        $payment = $this->paymentWithIntent(PaymentStatus::Succeeded, amount: 1000);

        $first = $this->service->refund($payment, 300, '同じ理由', $actor);
        $second = $this->service->refund($payment, 300, '同じ理由', $actor);

        $this->assertNotSame($first->refund_operation_id, $second->refund_operation_id);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        $this->assertSame(600, $payment->refunded_amount);
        $this->assertCount(2, PaymentRefund::query()->get());
        $this->assertCount(2, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_cancel_or_refund_selects_void_for_authorized_and_refund_for_succeeded(): void
    {
        $actor = User::factory()->create();
        $authorized = $this->paymentWithIntent(PaymentStatus::Authorized, amount: 800);
        $succeeded = $this->paymentWithIntent(PaymentStatus::Succeeded, amount: 1200);
        $this->gateway->queue(FakeStripeGateway::CANCEL, $this->intentResult($authorized, 'canceled'));
        $this->gateway->queue(FakeStripeGateway::REFUND, new RefundResult(
            id: 're_full',
            status: 'succeeded',
            amount: 1200,
            currency: 'jpy',
            paymentIntentId: $succeeded->stripe_payment_intent_id,
        ));

        $this->service->cancelOrRefund($authorized, '予約取消', $actor);
        $this->service->cancelOrRefund($succeeded, '予約取消', $actor);

        $this->assertSame(PaymentStatus::Voided, $authorized->refresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $succeeded->refresh()->status);
        $this->assertSame(1200, $succeeded->refunded_amount);
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::CANCEL));
        $this->assertCount(1, $this->gateway->callsFor(FakeStripeGateway::REFUND));
    }

    public function test_sync_from_stripe_never_rolls_a_succeeded_payment_back(): void
    {
        $payment = $this->paymentWithIntent(PaymentStatus::Succeeded);
        $this->gateway->queue(FakeStripeGateway::RETRIEVE, $this->intentResult($payment, 'requires_capture'));

        $synced = $this->service->syncFromStripe($payment);

        $this->assertSame(PaymentStatus::Succeeded, $synced->status);
        $this->assertNotNull($synced->last_synced_at);
        $this->assertSame(0, $this->gateway->callsFor(FakeStripeGateway::RETRIEVE)[0]['transaction_level']);
    }

    public function test_service_rejects_calling_stripe_inside_an_outer_transaction(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(LogicException::class);

        DB::transaction(fn (): Payment => $this->service->createIntent($payment));
    }

    private function paymentWithIntent(
        PaymentStatus $status,
        int $amount = 1000,
    ): Payment {
        $payment = Payment::factory()->create([
            'status' => $status,
            'amount' => $amount,
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->numerify('########'),
            'authorized_at' => in_array($status, [PaymentStatus::Authorized, PaymentStatus::Succeeded], true)
                ? now()
                : null,
            'paid_at' => $status === PaymentStatus::Succeeded ? now() : null,
        ]);
        $stripeStatus = match ($status) {
            PaymentStatus::Pending => 'requires_payment_method',
            PaymentStatus::Authorized => 'requires_capture',
            default => 'succeeded',
        };
        $this->gateway->setPaymentIntent($this->intentResult(
            $payment,
            $stripeStatus,
            amountReceived: $status === PaymentStatus::Succeeded ? $amount : 0,
        ));

        return $payment;
    }

    private function intentResult(
        Payment $payment,
        string $status,
        ?string $id = null,
        int $amountReceived = 0,
    ): PaymentIntentResult {
        return new PaymentIntentResult(
            id: $id ?? (string) ($payment->stripe_payment_intent_id ?? 'pi_fake_result'),
            status: $status,
            amount: (int) $payment->amount,
            amountCapturable: $status === 'requires_capture' ? (int) $payment->amount : 0,
            amountReceived: $amountReceived,
            currency: (string) $payment->currency,
            chargeId: $status === 'succeeded' ? 'ch_fake' : null,
        );
    }
}
