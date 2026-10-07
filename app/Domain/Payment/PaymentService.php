<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Payment\Gateway\Dto\CreatePaymentIntentCommand;
use App\Domain\Payment\Gateway\Dto\CreateRefundCommand;
use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\Dto\RefundResult;
use App\Domain\Payment\Gateway\StripeGateway;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class PaymentService
{
    private const AMBIGUOUS_TIMEOUT_CODE = 'ambiguous_timeout';

    private const AMBIGUOUS_TIMEOUT_MESSAGE = 'Stripeとの通信結果を確認できませんでした。再照合が必要です。';

    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly IdempotencyKeyFactory $idempotencyKeys,
        private readonly PaymentStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createIntent(Payment $payment): Payment
    {
        return $this->ensureIntentSession($payment)->payment;
    }

    /**
     * PaymentIntent を用意し、Payment と client_secret を返す。
     * client_secret は戻り値でのみ運び、DB へ保存もログ出力もしない。
     */
    public function ensureIntentSession(Payment $payment): CheckoutSession
    {
        $persisted = $this->persistedPayment($payment);

        try {
            $this->assertStripeOutsideTransaction();

            if ($persisted->stripe_payment_intent_id !== null) {
                $result = $this->gateway->retrievePaymentIntent($persisted->stripe_payment_intent_id);

                return new CheckoutSession(
                    $this->applyStripeResult($persisted, $result, true),
                    $result->clientSecret,
                );
            }

            if ($persisted->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'payment' => __('messages.payment.only_pending_can_create_intent'),
                ]);
            }

            $result = $this->gateway->createPaymentIntent(new CreatePaymentIntentCommand(
                amount: (int) $persisted->amount,
                currency: strtolower((string) $persisted->currency),
                captureMethod: (string) $persisted->capture_method,
                idempotencyKey: $this->idempotencyKeys->paymentIntentCreate($persisted),
                customerId: $persisted->customer?->stripe_customer_id,
                metadata: [
                    'reservation_id' => (string) $persisted->reservation_id,
                    'payment_id' => (string) $persisted->getKey(),
                    'payment_operation_id' => (string) $persisted->payment_operation_id,
                ],
            ));
        } catch (PaymentGatewayDeclinedException $exception) {
            // decline は確定的失敗。failed へ確定してよい。
            $this->markPaymentDeclined($persisted, $exception);

            throw $exception;
        } catch (PaymentGatewayTimeoutException $exception) {
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        } catch (PaymentGatewayException $exception) {
            // 失敗を証明できない gateway エラーは曖昧扱い。failed へ落とさず reconcile へ回す。
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        }

        $stored = DB::transaction(function () use ($persisted, $result): Payment {
            $locked = $this->lockPayment($persisted);

            if ($locked->stripe_payment_intent_id === null) {
                $locked->forceFill(['stripe_payment_intent_id' => $result->id])->save();
                $this->auditLogger->log(
                    'payment.created',
                    $locked,
                    $this->summary($locked, 'PaymentIntentを作成'),
                );
            } elseif ($locked->stripe_payment_intent_id !== $result->id) {
                $locked->forceFill([
                    'needs_attention' => true,
                    'failure_code' => 'payment_intent_conflict',
                    'failure_message' => '異なるPaymentIntent IDが返されました。',
                ])->save();
            }

            return $locked;
        });

        if ($stored->stripe_payment_intent_id !== $result->id) {
            throw new PaymentGatewayException('PaymentIntent IDの整合性を確認できませんでした。');
        }

        return new CheckoutSession(
            $this->applyStripeResult($stored, $result, false),
            $result->clientSecret,
        );
    }

    public function authorizeSync(Payment $payment): Payment
    {
        return $this->syncFromStripe($payment);
    }

    public function capture(Payment $payment): Payment
    {
        $persisted = $this->persistedPayment($payment);

        if (in_array($persisted->status, [
            PaymentStatus::Succeeded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Refunded,
        ], true)) {
            return $persisted;
        }

        if ($persisted->status !== PaymentStatus::Authorized) {
            throw ValidationException::withMessages([
                'payment' => __('messages.payment.only_authorized_can_capture'),
            ]);
        }

        $paymentIntentId = $this->paymentIntentId($persisted);

        try {
            $this->assertStripeOutsideTransaction();
            $result = $this->gateway->capturePaymentIntent(
                $paymentIntentId,
                $this->idempotencyKeys->paymentIntentCapture($persisted),
            );
        } catch (PaymentGatewayDeclinedException $exception) {
            // decline は確定的失敗。failed へ確定してよい。
            $this->markPaymentDeclined($persisted, $exception);

            throw $exception;
        } catch (PaymentGatewayTimeoutException $exception) {
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        } catch (PaymentGatewayException $exception) {
            // 失敗を証明できない gateway エラーは曖昧扱い。failed へ落とさず reconcile へ回す。
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        }

        return $this->applyStripeResult($persisted, $result, false);
    }

    public function cancel(Payment $payment): Payment
    {
        $persisted = $this->persistedPayment($payment);

        if ($persisted->status === PaymentStatus::Voided) {
            return $persisted;
        }

        if (! in_array($persisted->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            throw ValidationException::withMessages([
                'payment' => __('messages.payment.only_pending_or_authorized_can_void'),
            ]);
        }

        // Stripe 側の Intent 作成前なら、外部通信なしでローカル試行だけを閉じる。
        if ($persisted->stripe_payment_intent_id === null) {
            return DB::transaction(function () use ($persisted): Payment {
                $locked = $this->lockPayment($persisted);

                if ($locked->status === PaymentStatus::Voided) {
                    return $locked;
                }

                $this->stateMachine->apply($locked, 'status', PaymentStatus::Voided->value);
                $locked->forceFill(['voided_at' => now()])->save();

                $this->auditLogger->log(
                    'payment.voided',
                    $locked,
                    $this->summary($locked, '未開始の決済を取消'),
                );

                return $locked;
            });
        }

        $paymentIntentId = $this->paymentIntentId($persisted);

        try {
            $this->assertStripeOutsideTransaction();
            $result = $this->gateway->cancelPaymentIntent(
                $paymentIntentId,
                $this->idempotencyKeys->paymentIntentCancel($persisted),
            );
        } catch (PaymentGatewayDeclinedException $exception) {
            // decline は確定的失敗。failed へ確定してよい。
            $this->markPaymentDeclined($persisted, $exception);

            throw $exception;
        } catch (PaymentGatewayTimeoutException $exception) {
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        } catch (PaymentGatewayException $exception) {
            // 失敗を証明できない gateway エラーは曖昧扱い。failed へ落とさず reconcile へ回す。
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        }

        return $this->applyStripeResult($persisted, $result, false);
    }

    public function refund(
        Payment $payment,
        int $amount,
        string $reason,
        Authenticatable $actor,
    ): PaymentRefund {
        $reason = trim($reason);
        $this->validateRefundInput($amount, $reason);
        $actorId = $actor->getAuthIdentifier();

        if (! is_int($actorId) && ! (is_string($actorId) && ctype_digit($actorId))) {
            throw new LogicException('返金実行者の user ID が不正です。');
        }

        // 外部返金を含む Stripe の現在値を TX1 の前に同期し、取得失敗・不整合時は返金しない。
        $syncedPayment = $this->syncFromStripe($payment);

        $refund = DB::transaction(function () use ($syncedPayment, $amount, $reason, $actorId): PaymentRefund {
            $locked = $this->lockPayment($syncedPayment);

            if (! in_array($locked->status, [
                PaymentStatus::Succeeded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                throw ValidationException::withMessages([
                    'payment' => __('messages.payment.only_captured_refundable'),
                ]);
            }

            $succeededAmount = (int) PaymentRefund::query()
                ->where('payment_id', $locked->getKey())
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount');
            // pending も予約額として数え、並行する返金がどちらも Stripe へ進むことを防ぐ。
            $pendingAmount = (int) PaymentRefund::query()
                ->where('payment_id', $locked->getKey())
                ->where('status', RefundStatus::Pending->value)
                ->sum('amount');
            $alreadyRefunded = max((int) $locked->refunded_amount, $succeededAmount);

            if ($alreadyRefunded + $pendingAmount + $amount > (int) $locked->amount) {
                throw ValidationException::withMessages([
                    'amount' => __('messages.payment.refund_exceeds_amount'),
                ]);
            }

            $refund = new PaymentRefund([
                'payment_id' => $locked->getKey(),
                'refund_operation_id' => (string) Str::uuid(),
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => (int) $actorId,
            ]);
            $refund->status = RefundStatus::Pending;
            $refund->save();

            return $refund;
        });

        return $this->retryRefund($refund);
    }

    /**
     * TX1 後の中断や timeout を、永続化済み operation ID のまま再開する。
     */
    public function retryRefund(PaymentRefund $refund): PaymentRefund
    {
        $persisted = PaymentRefund::query()->findOrFail($refund->getKey());

        if ($persisted->status !== RefundStatus::Pending) {
            return $persisted;
        }

        $payment = Payment::query()->findOrFail($persisted->payment_id);
        $paymentIntentId = $this->paymentIntentId($payment);

        try {
            $this->assertStripeOutsideTransaction();
            $result = $this->gateway->createRefund(new CreateRefundCommand(
                paymentIntentId: $paymentIntentId,
                amount: (int) $persisted->amount,
                idempotencyKey: $this->idempotencyKeys->refund($persisted),
            ));
        } catch (PaymentGatewayDeclinedException $exception) {
            // 返金拒否は確定的失敗。
            $this->markRefundDeclined($persisted, $payment, $exception);

            throw $exception;
        } catch (PaymentGatewayTimeoutException $exception) {
            $this->markRefundAmbiguousTimeout($persisted, $payment);

            throw $exception;
        } catch (PaymentGatewayException $exception) {
            // 失敗を証明できない返金エラーは曖昧扱い。二重返金を避けるため pending のまま残す。
            $this->markRefundAmbiguousTimeout($persisted, $payment);

            throw $exception;
        }

        return $this->completeRefund($persisted, $payment, $result);
    }

    public function cancelOrRefund(
        Payment $payment,
        string $reason,
        Authenticatable $actor,
    ): void {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('messages.payment.void_reason_required'),
            ]);
        }

        $persisted = $this->persistedPayment($payment);

        // pending / authorized はいずれも capture 前。refund ではなく cancel(void) を使う。
        if (in_array($persisted->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            $this->cancel($persisted);

            return;
        }

        if (in_array($persisted->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true)) {
            $synced = $this->syncFromStripe($persisted);
            $remaining = $this->remainingRefundableAmount($synced);

            if ($remaining > 0) {
                $this->refund($synced, $remaining, $reason, $actor);
            }

            return;
        }

        if (in_array($persisted->status, [PaymentStatus::Voided, PaymentStatus::Refunded], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'payment' => __('messages.payment.cannot_void_or_refund'),
        ]);
    }

    public function remainingRefundableAmount(Payment $payment): int
    {
        $persisted = $this->persistedPayment($payment);
        $succeededAmount = (int) $persisted->refunds()
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount');
        $pendingAmount = (int) $persisted->refunds()
            ->where('status', RefundStatus::Pending->value)
            ->sum('amount');

        return max(
            0,
            (int) $persisted->amount
                - max((int) $persisted->refunded_amount, $succeededAmount)
                - $pendingAmount,
        );
    }

    public function syncFromStripe(Payment $payment): Payment
    {
        $persisted = $this->persistedPayment($payment);
        $paymentIntentId = $this->paymentIntentId($persisted);

        try {
            $this->assertStripeOutsideTransaction();
            $result = $this->gateway->retrievePaymentIntent($paymentIntentId);
        } catch (PaymentGatewayDeclinedException $exception) {
            // decline は確定的失敗。failed へ確定してよい。
            $this->markPaymentDeclined($persisted, $exception);

            throw $exception;
        } catch (PaymentGatewayTimeoutException $exception) {
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        } catch (PaymentGatewayException $exception) {
            // 失敗を証明できない gateway エラーは曖昧扱い。failed へ落とさず reconcile へ回す。
            $this->markAmbiguousTimeout($persisted);

            throw $exception;
        }

        return $this->applyStripeResult($persisted, $result, true);
    }

    private function applyStripeResult(
        Payment $payment,
        PaymentIntentResult $result,
        bool $updateLastSyncedAt,
    ): Payment {
        $persisted = $this->persistedPayment($payment);

        if ($persisted->stripe_payment_intent_id !== null
            && $persisted->stripe_payment_intent_id !== $result->id) {
            $this->markPaymentAttention(
                $persisted,
                'payment_intent_mismatch',
                'PaymentIntent IDが一致しません。',
            );

            throw new PaymentGatewayException('PaymentIntent IDが一致しません。');
        }

        if ($result->amount !== (int) $persisted->amount
            || strtolower($result->currency) !== strtolower((string) $persisted->currency)) {
            $this->markPaymentAttention(
                $persisted,
                'payment_amount_mismatch',
                'Stripeとローカルの決済金額または通貨が一致しません。',
            );

            throw new PaymentGatewayException('Stripe決済金額の整合性を確認できませんでした。');
        }

        return DB::transaction(function () use ($payment, $result, $updateLastSyncedAt): Payment {
            $locked = $this->lockPayment($payment);

            $target = $this->targetStatus($locked, $result);

            if ($target !== null) {
                $this->advanceTo($locked, $target, $result);
            }

            $changes = [];

            if ($result->chargeId !== null && $locked->stripe_charge_id === null) {
                $changes['stripe_charge_id'] = $result->chargeId;
            }

            if ($result->refundedAmount > (int) $locked->refunded_amount) {
                $changes['refunded_amount'] = min($result->refundedAmount, (int) $locked->amount);
            }

            $hasPendingRefund = PaymentRefund::query()
                ->where('payment_id', $locked->getKey())
                ->where('status', RefundStatus::Pending->value)
                ->exists();

            if ($locked->failure_code === self::AMBIGUOUS_TIMEOUT_CODE && ! $hasPendingRefund) {
                $changes['needs_attention'] = false;
                $changes['failure_code'] = null;
                $changes['failure_message'] = null;
            }

            if ($updateLastSyncedAt) {
                $changes['last_synced_at'] = now();
            }

            if ($changes !== []) {
                $locked->forceFill($changes)->save();
            }

            return $locked->refresh();
        });
    }

    private function targetStatus(Payment $payment, PaymentIntentResult $result): ?PaymentStatus
    {
        if ($result->refundedAmount >= (int) $payment->amount && $result->refundedAmount > 0) {
            return PaymentStatus::Refunded;
        }

        if ($result->refundedAmount > 0) {
            return PaymentStatus::PartiallyRefunded;
        }

        return match ($result->status) {
            'requires_capture' => PaymentStatus::Authorized,
            'succeeded' => PaymentStatus::Succeeded,
            'canceled' => PaymentStatus::Voided,
            'requires_payment_method' => $result->failureCode === null ? null : PaymentStatus::Failed,
            default => null,
        };
    }

    private function advanceTo(Payment $payment, PaymentStatus $target, PaymentIntentResult $result): void
    {
        $path = match ([$payment->status, $target]) {
            [PaymentStatus::Pending, PaymentStatus::Succeeded] => [
                PaymentStatus::Authorized,
                PaymentStatus::Succeeded,
            ],
            [PaymentStatus::Pending, PaymentStatus::PartiallyRefunded] => [
                PaymentStatus::Authorized,
                PaymentStatus::Succeeded,
                PaymentStatus::PartiallyRefunded,
            ],
            [PaymentStatus::Pending, PaymentStatus::Refunded] => [
                PaymentStatus::Authorized,
                PaymentStatus::Succeeded,
                PaymentStatus::Refunded,
            ],
            [PaymentStatus::Authorized, PaymentStatus::PartiallyRefunded] => [
                PaymentStatus::Succeeded,
                PaymentStatus::PartiallyRefunded,
            ],
            [PaymentStatus::Authorized, PaymentStatus::Refunded] => [
                PaymentStatus::Succeeded,
                PaymentStatus::Refunded,
            ],
            default => [$target],
        };

        foreach ($path as $next) {
            if ($payment->status === $next || ! $this->stateMachine->can($payment->status->value, $next->value)) {
                // 古い Stripe 状態による巻き戻し要求は no-op にする。
                return;
            }

            $this->stateMachine->apply($payment, 'status', $next->value);
            $this->applyTransitionDetails($payment, $next, $result);
        }
    }

    private function applyTransitionDetails(
        Payment $payment,
        PaymentStatus $status,
        PaymentIntentResult $result,
    ): void {
        $changes = match ($status) {
            PaymentStatus::Authorized => ['authorized_at' => $payment->authorized_at ?? now()],
            PaymentStatus::Succeeded => [
                'paid_at' => $payment->paid_at ?? now(),
                'stripe_charge_id' => $result->chargeId ?? $payment->stripe_charge_id,
            ],
            PaymentStatus::Voided => ['voided_at' => $payment->voided_at ?? now()],
            PaymentStatus::Failed => [
                'failure_code' => $this->safeFailureCode($result->failureCode ?? 'card_declined'),
                'failure_message' => 'カード決済が承認されませんでした。',
            ],
            default => [],
        };

        if ($changes !== []) {
            $payment->forceFill($changes)->save();
        }

        $action = match ($status) {
            PaymentStatus::Authorized => 'payment.authorized',
            PaymentStatus::Succeeded => 'payment.captured',
            PaymentStatus::Voided => 'payment.canceled',
            PaymentStatus::Failed => 'payment.failed',
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded => 'payment.refunded',
            default => null,
        };

        if ($action !== null) {
            $this->auditLogger->log($action, $payment, $this->summary($payment, $status->value));
        }
    }

    private function completeRefund(
        PaymentRefund $refund,
        Payment $payment,
        RefundResult $result,
    ): PaymentRefund {
        if ($result->status !== 'succeeded') {
            if ($result->status === 'failed') {
                $exception = new PaymentGatewayDeclinedException(
                    $this->safeFailureCode($result->failureCode ?? 'refund_failed'),
                    '返金処理が承認されませんでした。',
                );
                $this->markRefundDeclined($refund, $payment, $exception);

                throw $exception;
            }

            $this->markRefundPendingAttention($refund, $payment);

            return PaymentRefund::query()->findOrFail($refund->getKey());
        }

        if ($result->amount !== (int) $refund->amount
            || strtolower($result->currency) !== strtolower((string) $payment->currency)
            || ($result->paymentIntentId !== null
                && $result->paymentIntentId !== $payment->stripe_payment_intent_id)) {
            $this->markRefundPendingAttention($refund, $payment, 'refund_result_mismatch');

            throw new PaymentGatewayException('Stripe返金結果の整合性を確認できませんでした。');
        }

        $completed = DB::transaction(function () use ($refund, $payment, $result): PaymentRefund {
            $lockedPayment = $this->lockPayment($payment);
            $lockedRefund = PaymentRefund::query()
                ->whereKey($refund->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRefund->status === RefundStatus::Succeeded) {
                return $lockedRefund;
            }

            if ($lockedRefund->status !== RefundStatus::Pending) {
                return $lockedRefund;
            }

            $lockedRefund->forceFill([
                'status' => RefundStatus::Succeeded,
                'stripe_refund_id' => $result->id,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            $succeededAmount = (int) PaymentRefund::query()
                ->where('payment_id', $lockedPayment->getKey())
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount');
            $refundedAmount = min(
                (int) $lockedPayment->amount,
                max(
                    (int) $lockedPayment->refunded_amount,
                    $succeededAmount,
                ),
            );
            $target = $refundedAmount >= (int) $lockedPayment->amount
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            if ($lockedPayment->status !== $target) {
                $this->stateMachine->apply($lockedPayment, 'status', $target->value);
            }

            $changes = ['refunded_amount' => $refundedAmount];

            if (in_array($lockedPayment->failure_code, [
                self::AMBIGUOUS_TIMEOUT_CODE,
                'refund_pending',
                'refund_result_mismatch',
            ], true)) {
                $changes['needs_attention'] = false;
                $changes['failure_code'] = null;
                $changes['failure_message'] = null;
            }

            $lockedPayment->forceFill($changes)->save();

            $this->auditLogger->log(
                'payment.refunded',
                $lockedPayment,
                $this->summary(
                    $lockedPayment,
                    "返金#{$lockedRefund->id} {$lockedRefund->amount}円（理由登録済み）",
                ),
                $lockedRefund->creator,
            );

            return $lockedRefund->refresh();
        });

        try {
            // Stripe の累計返金額は TX2 の commit 後に取り込み、外部返金分を安全に補完する。
            $this->syncFromStripe($payment);
        } catch (Throwable) {
            // ローカル値は過少のままでも減少しない。次回返金前の必須同期で再照合する。
        }

        return $completed;
    }

    private function markAmbiguousTimeout(Payment $payment): void
    {
        $this->markPaymentAttention(
            $payment,
            self::AMBIGUOUS_TIMEOUT_CODE,
            self::AMBIGUOUS_TIMEOUT_MESSAGE,
        );
    }

    private function markPaymentAttention(Payment $payment, string $code, string $message): void
    {
        DB::transaction(function () use ($payment, $code, $message): void {
            $locked = $this->lockPayment($payment);
            $locked->forceFill([
                'needs_attention' => true,
                'failure_code' => $code,
                'failure_message' => $message,
            ])->save();
        });
    }

    private function markPaymentDeclined(
        Payment $payment,
        PaymentGatewayDeclinedException $exception,
    ): void {
        DB::transaction(function () use ($payment, $exception): void {
            $locked = $this->lockPayment($payment);

            if ($this->stateMachine->can($locked->status->value, PaymentStatus::Failed->value)) {
                $this->stateMachine->apply($locked, 'status', PaymentStatus::Failed->value);
                $locked->forceFill([
                    'failure_code' => $this->safeFailureCode($exception->gatewayCode),
                    'failure_message' => 'カード決済が承認されませんでした。',
                    'needs_attention' => false,
                ])->save();

                $this->auditLogger->log(
                    'payment.failed',
                    $locked,
                    $this->summary($locked, 'カード決済拒否'),
                );
            }
        });
    }

    private function markRefundAmbiguousTimeout(PaymentRefund $refund, Payment $payment): void
    {
        DB::transaction(function () use ($refund, $payment): void {
            $lockedPayment = $this->lockPayment($payment);
            $lockedRefund = PaymentRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRefund->status === RefundStatus::Pending) {
                $lockedRefund->forceFill([
                    'failure_code' => self::AMBIGUOUS_TIMEOUT_CODE,
                    'failure_message' => self::AMBIGUOUS_TIMEOUT_MESSAGE,
                ])->save();
            }

            $lockedPayment->forceFill([
                'needs_attention' => true,
                'failure_code' => self::AMBIGUOUS_TIMEOUT_CODE,
                'failure_message' => self::AMBIGUOUS_TIMEOUT_MESSAGE,
            ])->save();
        });
    }

    private function markRefundDeclined(
        PaymentRefund $refund,
        Payment $payment,
        PaymentGatewayDeclinedException $exception,
    ): void {
        DB::transaction(function () use ($refund, $payment, $exception): void {
            $lockedPayment = $this->lockPayment($payment);
            $lockedRefund = PaymentRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRefund->status === RefundStatus::Pending) {
                $lockedRefund->forceFill([
                    'status' => RefundStatus::Failed,
                    'failure_code' => $this->safeFailureCode($exception->gatewayCode),
                    'failure_message' => '返金処理が承認されませんでした。',
                ])->save();

                $this->auditLogger->log(
                    'payment.failed',
                    $lockedPayment,
                    $this->summary($lockedPayment, "返金#{$lockedRefund->id} 拒否"),
                    $lockedRefund->creator,
                );
            }
        });
    }

    private function markRefundPendingAttention(
        PaymentRefund $refund,
        Payment $payment,
        string $code = 'refund_pending',
    ): void {
        DB::transaction(function () use ($refund, $payment, $code): void {
            $lockedPayment = $this->lockPayment($payment);
            $lockedRefund = PaymentRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();
            $message = 'Stripe返金の完了確認が必要です。';

            if ($lockedRefund->status === RefundStatus::Pending) {
                $lockedRefund->forceFill([
                    'failure_code' => $code,
                    'failure_message' => $message,
                ])->save();
            }

            $lockedPayment->forceFill([
                'needs_attention' => true,
                'failure_code' => $code,
                'failure_message' => $message,
            ])->save();
        });
    }

    private function lockPayment(Payment $payment): Payment
    {
        return Payment::query()
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function persistedPayment(Payment $payment): Payment
    {
        return Payment::query()->findOrFail($payment->getKey());
    }

    private function paymentIntentId(Payment $payment): string
    {
        if (! is_string($payment->stripe_payment_intent_id) || $payment->stripe_payment_intent_id === '') {
            throw ValidationException::withMessages([
                'payment' => __('messages.payment.intent_not_created'),
            ]);
        }

        return $payment->stripe_payment_intent_id;
    }

    private function validateRefundInput(int $amount, string $reason): void
    {
        $errors = [];

        if ($amount <= 0) {
            $errors['amount'] = '返金額は1円以上で指定してください。';
        }

        if ($reason === '') {
            $errors['reason'] = '返金理由は必須です。';
        } elseif (mb_strlen($reason) > 255) {
            $errors['reason'] = '返金理由は255文字以内で指定してください。';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertStripeOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Stripe API は DB transaction の外で呼び出してください。');
        }
    }

    private function safeFailureCode(string $code): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $code) ?? 'payment_failed';

        return substr($safe === '' ? 'payment_failed' : $safe, 0, 50);
    }

    private function summary(Payment $payment, string $result): string
    {
        return "決済#{$payment->id} 予約#{$payment->reservation_id} {$payment->amount}円 {$result}";
    }
}
