<?php

declare(strict_types=1);

namespace App\Domain\Membership\Gateway;

use App\Domain\Membership\Gateway\Dto\CreateSubscriptionCommand;
use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * テスト用の Stripe subscription ゲートウェイ。
 *
 * - すべてのメソッドで DB::transactionLevel() === 0 を検査する（TX 中 Stripe HTTP の設計違反を機械的に落とす）。
 * - idempotencyKey で結果をキャッシュし、同一 key の再呼び出しでは同じ結果 / 同じ subscription を返す。
 */
final class FakeMembershipStripeGateway implements MembershipStripeGateway
{
    public bool $assertOutsideTransaction = true;

    /** @var list<array{op: string, idempotency_key: ?string, subscription_id: ?string, transaction_level: int}> */
    private array $calls = [];

    /** @var array<string, SubscriptionResult> */
    private array $idempotent = [];

    /** @var array<string, SubscriptionResult> */
    private array $subscriptions = [];

    private int $sequence = 0;

    /** 次の createSubscription をこの例外で失敗させる（1 回だけ）。 */
    private ?Throwable $failCreateOnce = null;

    private string $nextCreateStripeStatus = 'active';

    private ?string $nextCreateInvoiceStatus = 'paid';

    public function failNextCreateWith(Throwable $exception): void
    {
        $this->failCreateOnce = $exception;
    }

    public function nextCreateReturnsStatus(string $stripeStatus, ?string $invoiceStatus = null): void
    {
        $this->nextCreateStripeStatus = $stripeStatus;
        $this->nextCreateInvoiceStatus = $invoiceStatus;
    }

    /** テストが後続の retrieve 結果を差し替える。 */
    public function setSubscription(string $subscriptionId, SubscriptionResult $result): void
    {
        $this->subscriptions[$subscriptionId] = $result;
    }

    /** @return list<array{op: string, idempotency_key: ?string, subscription_id: ?string, transaction_level: int}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(string $op): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c['op'] === $op));
    }

    public function ensureCustomer(Customer $customer): string
    {
        $this->guard('ensure_customer', null, null);

        $id = $customer->stripe_customer_id ?? ('cus_fake_'.$customer->user_id);
        if ($customer->stripe_customer_id !== $id) {
            $customer->forceFill(['stripe_customer_id' => $id])->save();
        }

        return $id;
    }

    public function createSubscription(CreateSubscriptionCommand $command): SubscriptionResult
    {
        $this->guard('create_subscription', $command->idempotencyKey, null);

        if (isset($this->idempotent[$command->idempotencyKey])) {
            return $this->idempotent[$command->idempotencyKey];
        }

        if ($this->failCreateOnce !== null) {
            $exception = $this->failCreateOnce;
            $this->failCreateOnce = null;
            throw $exception;
        }

        $this->sequence++;
        $subscriptionId = 'sub_fake_'.$this->sequence;
        $result = new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: $this->nextCreateStripeStatus,
            cancelAtPeriodEnd: false,
            currentPeriodStart: now()->startOfMonth()->toDateString(),
            currentPeriodEnd: now()->startOfMonth()->addMonth()->toDateString(),
            latestInvoiceStatus: $this->nextCreateInvoiceStatus,
            latestInvoiceId: 'in_fake_'.$this->sequence,
        );

        $this->nextCreateStripeStatus = 'active';
        $this->nextCreateInvoiceStatus = 'paid';
        $this->idempotent[$command->idempotencyKey] = $result;
        $this->subscriptions[$subscriptionId] = $result;

        return $result;
    }

    public function retrieveSubscription(string $subscriptionId): SubscriptionResult
    {
        $this->guard('retrieve_subscription', null, $subscriptionId);

        return $this->subscriptions[$subscriptionId] ?? new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: 'active',
            cancelAtPeriodEnd: false,
            currentPeriodStart: now()->startOfMonth()->toDateString(),
            currentPeriodEnd: now()->startOfMonth()->addMonth()->toDateString(),
            latestInvoiceStatus: 'paid',
        );
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $value, string $idempotencyKey): SubscriptionResult
    {
        $this->guard('set_cancel_at_period_end', $idempotencyKey, $subscriptionId);

        $current = $this->retrieveInternal($subscriptionId);
        $result = new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: $current->stripeStatus,
            cancelAtPeriodEnd: $value,
            currentPeriodStart: $current->currentPeriodStart,
            currentPeriodEnd: $current->currentPeriodEnd,
            latestInvoiceStatus: $current->latestInvoiceStatus,
            latestInvoiceId: $current->latestInvoiceId,
        );
        $this->subscriptions[$subscriptionId] = $result;

        return $result;
    }

    public function cancelNow(string $subscriptionId, string $idempotencyKey): SubscriptionResult
    {
        $this->guard('cancel_now', $idempotencyKey, $subscriptionId);

        $current = $this->retrieveInternal($subscriptionId);
        $result = new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: 'canceled',
            cancelAtPeriodEnd: false,
            currentPeriodStart: $current->currentPeriodStart,
            currentPeriodEnd: $current->currentPeriodEnd,
            latestInvoiceStatus: $current->latestInvoiceStatus,
        );
        $this->subscriptions[$subscriptionId] = $result;

        return $result;
    }

    private function retrieveInternal(string $subscriptionId): SubscriptionResult
    {
        return $this->subscriptions[$subscriptionId] ?? new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: 'active',
            cancelAtPeriodEnd: false,
        );
    }

    private function guard(string $op, ?string $idempotencyKey, ?string $subscriptionId): void
    {
        $level = DB::transactionLevel();

        if ($this->assertOutsideTransaction && $level !== 0) {
            throw new LogicException("Stripe subscription 呼び出し [{$op}] が DB transaction (level {$level}) の内側で実行されました。");
        }

        $this->calls[] = [
            'op' => $op,
            'idempotency_key' => $idempotencyKey,
            'subscription_id' => $subscriptionId,
            'transaction_level' => $level,
        ];
    }
}
