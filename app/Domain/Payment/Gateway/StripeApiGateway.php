<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateway;

use App\Domain\Payment\Gateway\Dto\CreatePaymentIntentCommand;
use App\Domain\Payment\Gateway\Dto\CreateRefundCommand;
use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\Dto\RefundResult;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Throwable;

final class StripeApiGateway implements StripeGateway
{
    private readonly StripeClient $client;

    public function __construct(?StripeClient $client = null, ?CurlClient $httpClient = null)
    {
        $httpClient ??= new CurlClient;
        $httpClient
            ->setTimeout((int) config('stripe.http.timeout', 30))
            ->setConnectTimeout((int) config('stripe.http.connect_timeout', 10));
        ApiRequestor::setHttpClient($httpClient);

        $this->client = $client ?? new StripeClient([
            'api_key' => (string) config('stripe.secret'),
            'max_network_retries' => (int) config('stripe.http.max_network_retries', 2),
        ]);
    }

    public function createPaymentIntent(CreatePaymentIntentCommand $command): PaymentIntentResult
    {
        return $this->execute(function () use ($command): PaymentIntentResult {
            $parameters = [
                'amount' => $command->amount,
                'currency' => $command->currency,
                'capture_method' => $command->captureMethod,
                'metadata' => $command->metadata,
                'expand' => ['latest_charge'],
            ];

            if ($command->customerId !== null) {
                $parameters['customer'] = $command->customerId;
            }

            $intent = $this->client->paymentIntents->create(
                $parameters,
                ['idempotency_key' => $command->idempotencyKey],
            );

            return $this->paymentIntentResult($intent);
        });
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult
    {
        return $this->execute(fn (): PaymentIntentResult => $this->paymentIntentResult(
            $this->client->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['latest_charge'],
            ]),
        ));
    }

    public function capturePaymentIntent(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult
    {
        return $this->execute(fn (): PaymentIntentResult => $this->paymentIntentResult(
            $this->client->paymentIntents->capture(
                $paymentIntentId,
                ['expand' => ['latest_charge']],
                ['idempotency_key' => $idempotencyKey],
            ),
        ));
    }

    public function cancelPaymentIntent(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult
    {
        return $this->execute(fn (): PaymentIntentResult => $this->paymentIntentResult(
            $this->client->paymentIntents->cancel(
                $paymentIntentId,
                ['expand' => ['latest_charge']],
                ['idempotency_key' => $idempotencyKey],
            ),
        ));
    }

    public function createRefund(CreateRefundCommand $command): RefundResult
    {
        return $this->execute(function () use ($command): RefundResult {
            $refund = $this->client->refunds->create([
                'payment_intent' => $command->paymentIntentId,
                'amount' => $command->amount,
            ], [
                'idempotency_key' => $command->idempotencyKey,
            ]);

            return $this->refundResult($refund);
        });
    }

    public function retrieveEvent(string $eventId): mixed
    {
        return $this->execute(fn (): mixed => $this->client->events->retrieve($eventId));
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private function execute(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CardException $exception) {
            throw $this->declinedException($exception);
        } catch (ApiConnectionException) {
            throw new PaymentGatewayTimeoutException(
                'Stripeとの通信結果を確認できませんでした。',
            );
        } catch (ApiErrorException $exception) {
            $status = $exception->getHttpStatus();

            if ($status !== null && $status >= 500) {
                throw new PaymentGatewayTimeoutException(
                    'Stripeとの通信結果を確認できませんでした。',
                );
            }

            if ($this->isDecline($exception)) {
                throw $this->declinedException($exception);
            }

            throw new PaymentGatewayException(
                'Stripe APIがリクエストを受け付けませんでした。',
            );
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PaymentGatewayException(
                'Stripe APIの処理中にエラーが発生しました。',
            );
        }
    }

    private function isDecline(ApiErrorException $exception): bool
    {
        $error = $exception->getError();

        return ($exception->getHttpStatus() !== null && $exception->getHttpStatus() >= 400
                && $exception->getHttpStatus() < 500)
            && ($error?->type === 'card_error' || $error?->decline_code !== null);
    }

    private function declinedException(ApiErrorException $exception): PaymentGatewayDeclinedException
    {
        $error = $exception->getError();
        $code = $exception instanceof CardException
            ? ($exception->getDeclineCode() ?? $exception->getStripeCode())
            : ($error?->decline_code ?? $exception->getStripeCode());

        return new PaymentGatewayDeclinedException(
            $this->safeCode($code),
            'カード決済が承認されませんでした。',
        );
    }

    private function paymentIntentResult(PaymentIntent $intent): PaymentIntentResult
    {
        $charge = $intent->latest_charge;
        $chargeId = is_string($charge) ? $charge : $charge?->id;
        $refundedAmount = is_string($charge) || $charge === null
            ? 0
            : (int) ($charge->amount_refunded ?? 0);
        $lastError = $intent->last_payment_error;

        return new PaymentIntentResult(
            id: $intent->id,
            status: (string) $intent->status,
            amount: (int) $intent->amount,
            amountCapturable: (int) $intent->amount_capturable,
            amountReceived: (int) $intent->amount_received,
            currency: (string) $intent->currency,
            chargeId: $chargeId,
            clientSecret: $intent->client_secret,
            failureCode: $lastError === null ? null : $this->safeCode($lastError->code),
            failureMessage: $lastError === null ? null : 'カード決済が承認されませんでした。',
            refundedAmount: $refundedAmount,
        );
    }

    private function refundResult(Refund $refund): RefundResult
    {
        $paymentIntent = $refund->payment_intent;
        $charge = $refund->charge;

        return new RefundResult(
            id: $refund->id,
            status: (string) $refund->status,
            amount: (int) $refund->amount,
            currency: (string) $refund->currency,
            paymentIntentId: is_string($paymentIntent) ? $paymentIntent : $paymentIntent?->id,
            chargeId: is_string($charge) ? $charge : $charge?->id,
            failureCode: $refund->failure_reason === null
                ? null
                : $this->safeCode($refund->failure_reason),
            failureMessage: $refund->failure_reason === null
                ? null
                : '返金処理が完了しませんでした。',
        );
    }

    private function safeCode(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'card_declined';
        }

        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $code) ?? 'card_declined';

        return substr($safe, 0, 50);
    }
}
