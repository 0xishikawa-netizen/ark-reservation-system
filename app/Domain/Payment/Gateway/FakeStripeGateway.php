<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway;

use App\Domain\Payment\Gateway\Dto\CreatePaymentIntentCommand;
use App\Domain\Payment\Gateway\Dto\CreateRefundCommand;
use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\Dto\RefundResult;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class FakeStripeGateway implements StripeGateway
{
    public const CREATE = 'create_payment_intent';

    public const RETRIEVE = 'retrieve_payment_intent';

    public const CAPTURE = 'capture_payment_intent';

    public const CANCEL = 'cancel_payment_intent';

    public const REFUND = 'create_refund';

    public const RETRIEVE_EVENT = 'retrieve_event';

    /** @var list<array{operation: string, idempotency_key: ?string, resource_id: ?string, transaction_level: int}> */
    private array $calls = [];

    /** @var array<string, list<mixed>> */
    private array $responses = [];

    /** @var array<string, PaymentIntentResult|RefundResult> */
    private array $idempotentResults = [];

    /** @var array<string, PaymentIntentResult> */
    private array $paymentIntents = [];

    /** @var array<string, mixed> */
    private array $events = [];

    private int $paymentIntentSequence = 0;

    private int $refundSequence = 0;

    public bool $assertOutsideTransaction = true;

    private ?CreatePaymentIntentCommand $lastCreatePaymentIntentCommand = null;

    private ?CreateRefundCommand $lastCreateRefundCommand = null;

    public function createPaymentIntent(CreatePaymentIntentCommand $command): PaymentIntentResult
    {
        $this->lastCreatePaymentIntentCommand = $command;
        $this->record(self::CREATE, $command->idempotencyKey);

        $cached = $this->idempotentResults[$this->cacheKey(self::CREATE, $command->idempotencyKey)] ?? null;

        if ($cached instanceof PaymentIntentResult) {
            return $cached;
        }

        $default = new PaymentIntentResult(
            id: 'pi_fake_'.(++$this->paymentIntentSequence),
            status: 'requires_payment_method',
            amount: $command->amount,
            amountCapturable: 0,
            amountReceived: 0,
            currency: $command->currency,
            clientSecret: 'pi_fake_secret_for_tests_only',
        );
        $result = $this->next(self::CREATE, $default, $command->idempotencyKey);

        if (! $result instanceof PaymentIntentResult) {
            throw new LogicException('PaymentIntentResult が設定されていません。');
        }

        $this->rememberPaymentIntent($result);

        return $result;
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult
    {
        $this->record(self::RETRIEVE, null, $paymentIntentId);
        $default = $this->paymentIntents[$paymentIntentId] ?? null;
        $result = $this->next(self::RETRIEVE, $default);

        if (! $result instanceof PaymentIntentResult) {
            throw new LogicException("PaymentIntent [{$paymentIntentId}] の応答が設定されていません。");
        }

        $this->rememberPaymentIntent($result);

        return $result;
    }

    public function capturePaymentIntent(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult
    {
        $this->record(self::CAPTURE, $idempotencyKey, $paymentIntentId);

        $cached = $this->idempotentResults[$this->cacheKey(self::CAPTURE, $idempotencyKey)] ?? null;

        if ($cached instanceof PaymentIntentResult) {
            return $cached;
        }

        $current = $this->paymentIntents[$paymentIntentId] ?? null;
        $default = $current === null ? null : new PaymentIntentResult(
            id: $current->id,
            status: 'succeeded',
            amount: $current->amount,
            amountCapturable: 0,
            amountReceived: $current->amount,
            currency: $current->currency,
            chargeId: $current->chargeId ?? 'ch_fake_1',
        );
        $result = $this->next(self::CAPTURE, $default, $idempotencyKey);

        if (! $result instanceof PaymentIntentResult) {
            throw new LogicException("PaymentIntent [{$paymentIntentId}] の capture 応答が設定されていません。");
        }

        $this->rememberPaymentIntent($result);

        return $result;
    }

    public function cancelPaymentIntent(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult
    {
        $this->record(self::CANCEL, $idempotencyKey, $paymentIntentId);

        $cached = $this->idempotentResults[$this->cacheKey(self::CANCEL, $idempotencyKey)] ?? null;

        if ($cached instanceof PaymentIntentResult) {
            return $cached;
        }

        $current = $this->paymentIntents[$paymentIntentId] ?? null;
        $default = $current === null ? null : new PaymentIntentResult(
            id: $current->id,
            status: 'canceled',
            amount: $current->amount,
            amountCapturable: 0,
            amountReceived: $current->amountReceived,
            currency: $current->currency,
            chargeId: $current->chargeId,
        );
        $result = $this->next(self::CANCEL, $default, $idempotencyKey);

        if (! $result instanceof PaymentIntentResult) {
            throw new LogicException("PaymentIntent [{$paymentIntentId}] の cancel 応答が設定されていません。");
        }

        $this->rememberPaymentIntent($result);

        return $result;
    }

    public function createRefund(CreateRefundCommand $command): RefundResult
    {
        $this->lastCreateRefundCommand = $command;
        $this->record(self::REFUND, $command->idempotencyKey, $command->paymentIntentId);

        $cached = $this->idempotentResults[$this->cacheKey(self::REFUND, $command->idempotencyKey)] ?? null;

        if ($cached instanceof RefundResult) {
            return $cached;
        }

        $default = new RefundResult(
            id: 're_fake_'.(++$this->refundSequence),
            status: 'succeeded',
            amount: $command->amount,
            currency: 'jpy',
            paymentIntentId: $command->paymentIntentId,
        );
        $result = $this->next(self::REFUND, $default, $command->idempotencyKey);

        if (! $result instanceof RefundResult) {
            throw new LogicException('RefundResult が設定されていません。');
        }

        return $result;
    }

    public function retrieveEvent(string $eventId): mixed
    {
        $this->record(self::RETRIEVE_EVENT, null, $eventId);

        return $this->next(self::RETRIEVE_EVENT, $this->events[$eventId] ?? null);
    }

    public function queue(string $operation, mixed $response): void
    {
        $this->responses[$operation][] = $response;
    }

    public function queueAmbiguousTimeout(
        string $operation,
        PaymentIntentResult|RefundResult $result,
        ?PaymentGatewayTimeoutException $exception = null,
    ): void
    {
        $this->responses[$operation][] = new AmbiguousFakeStripeResponse(
            $result,
            $exception ?? new PaymentGatewayTimeoutException('Stripeとの通信結果を確認できませんでした。'),
        );
    }

    public function setPaymentIntent(PaymentIntentResult $result): void
    {
        $this->rememberPaymentIntent($result);
    }

    public function setEvent(string $eventId, mixed $event): void
    {
        $this->events[$eventId] = $event;
    }

    /** @return list<array{operation: string, idempotency_key: ?string, resource_id: ?string, transaction_level: int}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<array{operation: string, idempotency_key: ?string, resource_id: ?string, transaction_level: int}> */
    public function callsFor(string $operation): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['operation'] === $operation,
        ));
    }

    public function lastCreatePaymentIntentCommand(): ?CreatePaymentIntentCommand
    {
        return $this->lastCreatePaymentIntentCommand;
    }

    public function lastCreateRefundCommand(): ?CreateRefundCommand
    {
        return $this->lastCreateRefundCommand;
    }

    private function record(string $operation, ?string $idempotencyKey, ?string $resourceId = null): void
    {
        $transactionLevel = DB::transactionLevel();
        $this->calls[] = [
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'resource_id' => $resourceId,
            'transaction_level' => $transactionLevel,
        ];

        if ($this->assertOutsideTransaction && $transactionLevel !== 0) {
            throw new LogicException('Stripe Gateway が DB transaction 内で呼び出されました。');
        }
    }

    private function cacheKey(string $operation, string $idempotencyKey): string
    {
        return "{$operation}:{$idempotencyKey}";
    }

    private function next(
        string $operation,
        mixed $default,
        ?string $idempotencyKey = null,
    ): mixed
    {
        if (! isset($this->responses[$operation]) || $this->responses[$operation] === []) {
            $response = $default;
        } else {
            $response = array_shift($this->responses[$operation]);
        }

        if ($response instanceof AmbiguousFakeStripeResponse) {
            if ($idempotencyKey === null) {
                throw new LogicException('曖昧 timeout のテストには Idempotency-Key が必要です。');
            }

            $this->idempotentResults[$this->cacheKey($operation, $idempotencyKey)] = $response->result;

            if ($response->result instanceof PaymentIntentResult) {
                $this->rememberPaymentIntent($response->result);
            }

            throw $response->exception;
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($idempotencyKey !== null && ($response instanceof PaymentIntentResult || $response instanceof RefundResult)) {
            $this->idempotentResults[$this->cacheKey($operation, $idempotencyKey)] = $response;
        }

        return $response;
    }

    private function rememberPaymentIntent(PaymentIntentResult $result): void
    {
        $this->paymentIntents[$result->id] = $result;
    }
}

final readonly class AmbiguousFakeStripeResponse
{
    public function __construct(
        public PaymentIntentResult|RefundResult $result,
        public PaymentGatewayTimeoutException $exception,
    ) {}
}
