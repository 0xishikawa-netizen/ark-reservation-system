<?php

declare(strict_types=1);

namespace App\Domain\Membership\Webhook;

use App\Domain\Membership\MembershipBillingService;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

/**
 * invoice.* / customer.subscription.* を Membership へ反映する。
 *
 * 原則（Phase 5 と同一）:
 * - イベントの中身で state を決めない。どの membership かだけ特定し、
 *   実際の更新は Stripe の現在オブジェクトを retrieve する経路（syncFromStripe / recordInvoicePaid）に委ねる。
 * - 到着順・遅延に耐える（前進のみ・古い canceled を巻き戻さない）。
 */
final class MembershipWebhookHandler
{
    /** @var list<string> */
    public const HANDLED = [
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(
        private readonly MembershipSubscriptionService $subscriptions,
        private readonly MembershipBillingService $billing,
    ) {}

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLED, true);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{result: 'processed'|'ignored', membership_id: ?int, note: ?string}
     */
    public function handle(string $type, array $event): array
    {
        $object = $event['data']['object'] ?? null;

        if (! is_array($object)) {
            return ['result' => 'ignored', 'membership_id' => null, 'note' => 'object を特定できませんでした。'];
        }

        $subscriptionId = $this->subscriptionIdFrom($type, $object);

        if ($subscriptionId === null) {
            return ['result' => 'ignored', 'membership_id' => null, 'note' => 'subscription を特定できませんでした。'];
        }

        $membership = Membership::query()->where('stripe_subscription_id', $subscriptionId)->first();

        if ($membership === null) {
            // 曖昧な create 失敗（Stripe timeout 等）で stripe_subscription_id を保存できず
            // pending に張り付いた membership を、Stripe が付けた metadata.membership_id で再関連付けする。
            $membership = $this->adoptFromMetadata($type, $object, $subscriptionId);
        }

        if ($membership === null) {
            return ['result' => 'ignored', 'membership_id' => null, 'note' => '対象の利用権が見つかりません。'];
        }

        match ($type) {
            'invoice.paid' => $this->billing->recordInvoicePaid($membership, $object),
            'invoice.payment_failed', 'invoice.payment_action_required' => $this->billing->handlePaymentIssue($membership, $object),
            default => $this->subscriptions->syncFromStripe($membership),
        };

        return ['result' => 'processed', 'membership_id' => (int) $membership->id, 'note' => null];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function subscriptionIdFrom(string $type, array $object): ?string
    {
        if (str_starts_with($type, 'invoice.')) {
            // Stripe API 2026-08-26.dahlia で invoice.subscription は
            // parent.subscription_details.subscription へ移動。旧形にもフォールバック。
            $nested = $object['parent']['subscription_details']['subscription'] ?? null;

            if (is_array($nested)) {
                $nested = $nested['id'] ?? null;
            }

            $candidate = $object['subscription'] ?? $nested;
        } else {
            $candidate = $object['id'] ?? null;
        }

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    /**
     * customer.subscription.* イベントの metadata.membership_id から stuck-pending の membership を
     * 引き当て、まだ stripe_subscription_id が無ければ採用して以降の同期に載せる。
     *
     * @param  array<string, mixed>  $object
     */
    private function adoptFromMetadata(string $type, array $object, string $subscriptionId): ?Membership
    {
        if (str_starts_with($type, 'invoice.')) {
            return null; // invoice の metadata は subscription の metadata と別物。採用しない。
        }

        $membershipId = $object['metadata']['membership_id'] ?? null;

        if (! is_string($membershipId) && ! is_int($membershipId)) {
            return null;
        }

        return DB::transaction(function () use ($membershipId, $subscriptionId): ?Membership {
            $membership = Membership::query()->whereKey($membershipId)->lockForUpdate()->first();

            if ($membership === null) {
                return null;
            }

            if ($membership->stripe_subscription_id === null
                && ! Membership::query()->where('stripe_subscription_id', $subscriptionId)->exists()) {
                $membership->forceFill(['stripe_subscription_id' => $subscriptionId])->save();
            }

            // 別の subscription をすでに持つ membership には対応イベントではない。
            return $membership->stripe_subscription_id === $subscriptionId ? $membership : null;
        });
    }
}
