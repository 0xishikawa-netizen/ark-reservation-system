<?php

declare(strict_types=1);

namespace App\Domain\Membership\Gateway;

use App\Domain\Membership\Gateway\Dto\CreateSubscriptionCommand;
use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * 本番/Staging 用の Stripe subscription ゲートウェイ（Test Mode）。
 *
 * - Cashier は billable/顧客の Stripe customer 管理に使う。subscription 作成は
 *   Idempotency-Key を確実に渡すため Stripe SDK を直接叩き、Cashier の subscriptions レコードへ反映する。
 * - Stripe SDK 例外 = 失敗 と決めつけない。timeout / 5xx / 想定外は PaymentGatewayException 系（曖昧）へ写す。
 */
final class StripeApiMembershipGateway implements MembershipStripeGateway
{
    private StripeClient $client;

    public function __construct()
    {
        $this->client = Cashier::stripe();
    }

    public function ensureCustomer(Customer $customer): string
    {
        return $this->execute(function () use ($customer): string {
            // 既存 stripe_customer_id を尊重（alias 経由で hasStripeId が効く）。
            return $customer->hasStripeId()
                ? $customer->stripe_customer_id
                : $customer->createAsStripeCustomer()->id;
        });
    }

    public function createSubscription(CreateSubscriptionCommand $command): SubscriptionResult
    {
        return $this->execute(function () use ($command): SubscriptionResult {
            $params = [
                'customer' => $command->stripeCustomerId,
                'items' => [['price' => $command->priceId]],
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice'],
                'metadata' => $command->metadata,
            ];

            if ($command->paymentMethodId !== null) {
                $params['default_payment_method'] = $command->paymentMethodId;
            }

            $subscription = $this->client->subscriptions->create($params, [
                'idempotency_key' => $command->idempotencyKey,
            ]);

            $this->mirrorToCashier($command->customerUserId, $subscription);

            return $this->toResult($subscription);
        });
    }

    public function retrieveSubscription(string $subscriptionId): SubscriptionResult
    {
        return $this->execute(function () use ($subscriptionId): SubscriptionResult {
            $subscription = $this->client->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice']]);

            return $this->toResult($subscription);
        });
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $value, string $idempotencyKey): SubscriptionResult
    {
        return $this->execute(function () use ($subscriptionId, $value, $idempotencyKey): SubscriptionResult {
            $subscription = $this->client->subscriptions->update(
                $subscriptionId,
                ['cancel_at_period_end' => $value, 'expand' => ['latest_invoice']],
                ['idempotency_key' => $idempotencyKey],
            );

            return $this->toResult($subscription);
        });
    }

    public function cancelNow(string $subscriptionId, string $idempotencyKey): SubscriptionResult
    {
        return $this->execute(function () use ($subscriptionId, $idempotencyKey): SubscriptionResult {
            $subscription = $this->client->subscriptions->cancel(
                $subscriptionId,
                [],
                ['idempotency_key' => $idempotencyKey],
            );

            return $this->toResult($subscription);
        });
    }

    private function toResult(StripeSubscription $subscription): SubscriptionResult
    {
        $invoice = $subscription->latest_invoice;
        $invoiceStatus = is_object($invoice) ? ($invoice->status ?? null) : null;
        $invoiceId = is_string($invoice) ? $invoice : (is_object($invoice) ? ($invoice->id ?? null) : null);

        return new SubscriptionResult(
            stripeSubscriptionId: $subscription->id,
            stripeStatus: (string) $subscription->status,
            cancelAtPeriodEnd: (bool) $subscription->cancel_at_period_end,
            currentPeriodStart: $subscription->current_period_start
                ? Carbon::createFromTimestamp($subscription->current_period_start)->toDateString()
                : null,
            currentPeriodEnd: $subscription->current_period_end
                ? Carbon::createFromTimestamp($subscription->current_period_end)->toDateString()
                : null,
            latestInvoiceStatus: is_string($invoiceStatus) ? $invoiceStatus : null,
            latestInvoiceId: is_string($invoiceId) ? $invoiceId : null,
            nextPaymentAttempt: (is_object($invoice) && ! empty($invoice->next_payment_attempt))
                ? Carbon::createFromTimestamp($invoice->next_payment_attempt)->toDateTimeString()
                : null,
        );
    }

    private function mirrorToCashier(int $customerUserId, StripeSubscription $subscription): void
    {
        $customer = Customer::query()->find($customerUserId);

        if ($customer === null) {
            return;
        }

        // Cashier の subscriptions レコード（課金契約の記録）を作る/更新する。業務状態はここに置かない。
        $customer->subscriptions()->updateOrCreate(
            ['stripe_id' => $subscription->id],
            [
                'type' => 'default',
                'stripe_status' => $subscription->status,
                'stripe_price' => $subscription->items->data[0]->price->id ?? null,
                'quantity' => $subscription->items->data[0]->quantity ?? null,
                'ends_at' => null,
            ],
        );
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
            throw new PaymentGatewayDeclinedException(
                (string) ($exception->getDeclineCode() ?? $exception->getStripeCode() ?? 'card_declined'),
                'カード決済が承認されませんでした。',
            );
        } catch (ApiConnectionException) {
            throw new PaymentGatewayTimeoutException('Stripeとの通信結果を確認できませんでした。');
        } catch (ApiErrorException $exception) {
            $status = $exception->getHttpStatus();

            if ($status !== null && $status >= 500) {
                throw new PaymentGatewayTimeoutException('Stripeとの通信結果を確認できませんでした。');
            }

            throw new PaymentGatewayException('Stripe APIがリクエストを受け付けませんでした。');
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PaymentGatewayException('Stripe APIの処理中にエラーが発生しました。');
        }
    }
}
