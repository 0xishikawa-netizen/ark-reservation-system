<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Membership\Gateway\Dto\CreateSubscriptionCommand;
use App\Domain\Membership\Gateway\Dto\MembershipCheckoutResult;
use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\MembershipStripeGateway;
use App\Enums\Membership\MembershipStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Membership subscription の最小オーケストレーション。
 *
 * 鉄則（Phase 5 と同一）:
 * - DB transaction の中で Stripe HTTP を呼ばない（local commit → Stripe HTTP → local commit）。
 * - Idempotency-Key は membership_operation_id（DB 永続値）からのみ導出。retry で新 ID を発行しない。
 * - Stripe SDK 例外 = Stripe 失敗 と決めつけない。timeout / 5xx / 想定外は「曖昧」として
 *   状態を確定させず needs_attention を立て、reconcile / retrieve / 同一 key retry で収束させる。
 */
final class MembershipSubscriptionService
{
    private readonly MembershipStateMachine $stateMachine;

    public function __construct(
        private readonly MembershipStripeGateway $gateway,
        private readonly MembershipIdempotencyKeyFactory $keys,
        private readonly MembershipStatusMapper $mapper,
        private readonly AuditLogger $auditLogger,
    ) {
        $this->stateMachine = new MembershipStateMachine;
    }

    /**
     * 新規申込。1 顧客 1 有効 membership。
     *
     * 二重送信対策: TX1 で「解約以外の membership」を lockForUpdate。
     * - active / grace / canceling / paused が既にあれば 422（新規は作らせない）。
     * - 進行中の pending（自分の未完了申込）は行を再利用し、同じ membership_operation_id で
     *   Stripe を叩く。Idempotency-Key が固定なので二重 subscription にならない。
     *
     * 戻り値は 3DS/SCA が必要か（clientSecret 同梱）を表す MembershipCheckoutResult。
     */
    public function startSubscription(
        Customer $customer,
        MembershipPlan $plan,
        ?string $paymentMethodId = null,
        ?Authenticatable $actor = null,
    ): MembershipCheckoutResult {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => __('messages.membership.plan_unavailable')]);
        }

        // [TX1] ローカル確定（Stripe を呼ばない）。1 顧客 1 有効 membership を直列化して検査。
        [$membership, $reuseSubscriptionId] = DB::transaction(function () use ($customer, $plan): array {
            $existing = Membership::query()
                ->where('customer_id', $customer->user_id)
                ->where('status', '!=', MembershipStatus::Canceled->value)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing !== null && $existing->status !== MembershipStatus::Pending) {
                throw ValidationException::withMessages([
                    'membership' => __('messages.membership.already_active'),
                ]);
            }

            if ($existing !== null) {
                // 自分の未完了申込。行と operation_id を再利用（二重 subscription を作らない）。
                return [$existing, $existing->stripe_subscription_id];
            }

            return [Membership::query()->create([
                'customer_id' => $customer->user_id,
                'membership_plan_id' => $plan->id,
                'stripe_subscription_id' => null,
                'membership_operation_id' => (string) Str::uuid(),
                'pending_operation' => 'create',
                'status' => MembershipStatus::Pending->value,
                'period_available' => 0,
            ]), null];
        });

        // [HTTP] Stripe customer 確保 → subscription create / 再開時は retrieve（同一 key で安全に retry 可）。
        try {
            if (is_string($reuseSubscriptionId) && $reuseSubscriptionId !== '') {
                $result = $this->gateway->retrieveSubscription($reuseSubscriptionId);
            } else {
                $stripeCustomerId = $this->gateway->ensureCustomer($customer);

                $result = $this->gateway->createSubscription(new CreateSubscriptionCommand(
                    customerUserId: (int) $customer->user_id,
                    stripeCustomerId: $stripeCustomerId,
                    priceId: (string) $plan->stripe_price_id,
                    membershipOperationId: (string) $membership->membership_operation_id,
                    idempotencyKey: $this->keys->subscriptionCreate($membership),
                    paymentMethodId: $paymentMethodId,
                    metadata: [
                        'membership_id' => (string) $membership->id,
                        'membership_operation_id' => (string) $membership->membership_operation_id,
                    ],
                ));
            }
        } catch (PaymentGatewayException $exception) {
            // 曖昧: Stripe 側で作成済みの可能性がある。状態を確定させず要対応にする。
            $this->flagAmbiguous($membership, 'create');
            throw $exception;
        }

        // [TX2] Stripe の現在値へ同期（前進のみ）。
        $this->applyStripeResult($membership, $result, actor: $actor, isCreate: true);

        return new MembershipCheckoutResult(
            membership: $membership->fresh() ?? $membership,
            requiresConfirmation: $result->requiresConfirmation(),
            clientSecret: $result->requiresConfirmation() ? $result->clientSecret : null,
        );
    }

    /**
     * 顧客の途中解約（次回更新で停止。当期末までは利用可能）。
     */
    public function requestCancelAtPeriodEnd(Membership $membership, ?Authenticatable $actor = null): void
    {
        $this->requireSubscription($membership);

        try {
            $result = $this->gateway->setCancelAtPeriodEnd(
                (string) $membership->stripe_subscription_id,
                true,
                $this->keys->subscriptionCancel($membership),
            );
        } catch (PaymentGatewayException $exception) {
            // 適用後に応答が失われた可能性。状態を確定させず要対応にする。
            $this->flagAmbiguous($membership, 'cancel');
            throw $exception;
        }

        DB::transaction(function () use ($membership, $result, $actor): void {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['cancel_at_period_end' => true]);
            $this->syncPeriods($locked, $result);
            $this->advance($locked, $this->mapper->targetFor($result->stripeStatus, true));
            $locked->forceFill(['last_synced_at' => now(), 'pending_operation' => null])->save();

            $this->auditLogger->log(
                'membership.cancel_requested',
                $locked,
                "利用権 途中解約（当期末で停止）membership#{$locked->id}",
                $actor,
            );
        });
    }

    /**
     * 途中解約の取り消し（un-cancel）。
     */
    public function resumeCancelAtPeriodEnd(Membership $membership, ?Authenticatable $actor = null): void
    {
        $this->requireSubscription($membership);

        try {
            $result = $this->gateway->setCancelAtPeriodEnd(
                (string) $membership->stripe_subscription_id,
                false,
                $this->keys->subscriptionResume($membership),
            );
        } catch (PaymentGatewayException $exception) {
            $this->flagAmbiguous($membership, 'resume');
            throw $exception;
        }

        DB::transaction(function () use ($membership, $result, $actor): void {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['cancel_at_period_end' => false]);
            $this->syncPeriods($locked, $result);
            $this->advance($locked, $this->mapper->targetFor($result->stripeStatus, false));
            $locked->forceFill(['last_synced_at' => now(), 'pending_operation' => null])->save();

            $this->auditLogger->log(
                'membership.activated',
                $locked,
                "利用権 途中解約の取り消し membership#{$locked->id}",
                $actor,
            );
        });
    }

    /**
     * 管理者の即時解約 override（機微操作。reason + reauth + audit は呼び出し側 route）。
     */
    public function cancelNow(Membership $membership, string $reason, ?Authenticatable $actor = null): void
    {
        $this->requireSubscription($membership);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.common.reason_required')]);
        }

        try {
            $result = $this->gateway->cancelNow(
                (string) $membership->stripe_subscription_id,
                $this->keys->subscriptionCancelNow($membership),
            );
        } catch (PaymentGatewayException $exception) {
            $this->flagAmbiguous($membership, 'cancel_now');
            throw $exception;
        }

        DB::transaction(function () use ($membership, $result, $reason, $actor): void {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $this->syncPeriods($locked, $result);
            $this->advance($locked, MembershipStatus::Canceled);
            $locked->forceFill([
                'cancel_at_period_end' => false,
                'canceled_at' => now(),
                'last_synced_at' => now(),
                'pending_operation' => null,
            ])->save();

            $this->auditLogger->log(
                'membership.canceled',
                $locked,
                "利用権 即時解約 membership#{$locked->id}（理由: {$reason}）",
                $actor,
            );
        });
    }

    /**
     * Stripe の現在オブジェクトを retrieve して前進のみ同期する（webhook / reconcile から使う）。
     */
    public function syncFromStripe(Membership $membership, ?Authenticatable $actor = null): void
    {
        $this->requireSubscription($membership);

        $result = $this->gateway->retrieveSubscription((string) $membership->stripe_subscription_id);

        $this->applyStripeResult($membership, $result, actor: $actor, isCreate: false);
    }

    /**
     * 申込確認画面 / 認証完了後の sync から使う。Stripe を retrieve し、
     * ローカルへ前進同期したうえで「まだ 3DS/SCA が必要か」を返す。
     */
    public function syncCheckout(Membership $membership, ?Authenticatable $actor = null): MembershipCheckoutResult
    {
        $this->requireSubscription($membership);

        $result = $this->gateway->retrieveSubscription((string) $membership->stripe_subscription_id);
        $this->applyStripeResult($membership, $result, actor: $actor, isCreate: false);

        return new MembershipCheckoutResult(
            membership: $membership->fresh() ?? $membership,
            requiresConfirmation: $result->requiresConfirmation(),
            clientSecret: $result->requiresConfirmation() ? $result->clientSecret : null,
        );
    }

    private function applyStripeResult(
        Membership $membership,
        SubscriptionResult $result,
        ?Authenticatable $actor,
        bool $isCreate,
    ): void {
        DB::transaction(function () use ($membership, $result, $actor, $isCreate): void {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'stripe_subscription_id' => $result->stripeSubscriptionId,
                'cancel_at_period_end' => $result->cancelAtPeriodEnd,
            ]);
            $this->syncPeriods($locked, $result);

            $before = $locked->status;
            $target = $this->mapper->targetFor($result->stripeStatus, $result->cancelAtPeriodEnd);
            $this->advance($locked, $target);
            $this->syncGraceUntil($locked, $result);

            $locked->forceFill([
                'pending_operation' => null,
                'needs_attention' => false,
                'last_synced_at' => now(),
            ]);

            if ($locked->status === MembershipStatus::Active && $locked->started_at === null) {
                $locked->forceFill(['started_at' => now()]);
            }

            $locked->save();

            if ($isCreate) {
                $this->auditLogger->log('membership.created', $locked, "利用権 作成 membership#{$locked->id}", $actor);
            }

            if ($before !== $locked->status) {
                $this->auditForStatus($locked, $actor);
            }
        });
    }

    private function auditForStatus(Membership $membership, ?Authenticatable $actor): void
    {
        $action = match ($membership->status) {
            MembershipStatus::Active => 'membership.activated',
            MembershipStatus::Grace => 'membership.grace',
            MembershipStatus::Paused => 'membership.paused',
            MembershipStatus::Canceling => 'membership.cancel_requested',
            MembershipStatus::Canceled => 'membership.canceled',
            default => null,
        };

        if ($action !== null) {
            $this->auditLogger->log($action, $membership, "利用権 状態遷移 membership#{$membership->id} → {$membership->status->value}", $actor);
        }
    }

    /**
     * 定義済みの前進エッジだけを辿って target へ追いつく。後退・到達不能なら何もしない。
     */
    private function advance(Membership $membership, MembershipStatus $target): void
    {
        $current = $membership->status->value;

        if ($current === $target->value) {
            return;
        }

        $path = $this->stateMachine->pathTo($current, $target->value);

        foreach ($path as $step) {
            $this->stateMachine->apply($membership, 'status', $step);
        }
    }

    private function syncPeriods(Membership $membership, SubscriptionResult $result): void
    {
        if ($result->currentPeriodStart !== null) {
            $membership->forceFill(['current_period_start' => Carbon::parse($result->currentPeriodStart)->toDateString()]);
        }

        if ($result->currentPeriodEnd !== null) {
            $membership->forceFill(['current_period_end' => Carbon::parse($result->currentPeriodEnd)->toDateString()]);
        }
    }

    /**
     * grace 中だけ grace_until を設定する。webhook 遅延だけで予約可否が即変わらないよう、
     * config/membership.php の grace policy と Stripe の retry 予定から算出する。
     */
    private function syncGraceUntil(Membership $membership, SubscriptionResult $result): void
    {
        if ($membership->status !== MembershipStatus::Grace) {
            $membership->forceFill(['grace_until' => null]);

            return;
        }

        if ($membership->grace_until !== null) {
            return; // 既に設定済みなら延長も短縮もしない。
        }

        $maxDays = (int) config('membership.grace.max_days', 14);
        $hardLimit = now()->addDays($maxDays);

        if ((string) config('membership.grace.source', 'stripe') === 'stripe') {
            $candidate = $result->nextPaymentAttempt !== null
                ? Carbon::parse($result->nextPaymentAttempt)
                : ($result->currentPeriodEnd !== null ? Carbon::parse($result->currentPeriodEnd)->endOfDay() : $hardLimit);

            $graceUntil = $candidate->greaterThan($hardLimit) ? $hardLimit : $candidate;
        } else {
            $graceUntil = $hardLimit;
        }

        $membership->forceFill(['grace_until' => $graceUntil]);
    }

    private function flagAmbiguous(Membership $membership, string $operation): void
    {
        DB::transaction(function () use ($membership, $operation): void {
            Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail()->forceFill([
                'needs_attention' => true,
                'pending_operation' => $operation,
            ])->save();
        });
    }

    private function requireSubscription(Membership $membership): void
    {
        if (! is_string($membership->stripe_subscription_id) || $membership->stripe_subscription_id === '') {
            throw new RuntimeException('Stripe subscription が未作成の membership です。');
        }
    }
}
