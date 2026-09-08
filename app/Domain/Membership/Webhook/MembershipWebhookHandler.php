<?php

declare(strict_types=1);

namespace App\Domain\Membership\Webhook;

use App\Domain\Membership\MembershipBillingService;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Models\Membership;

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
        $candidate = str_starts_with($type, 'invoice.')
            ? ($object['subscription'] ?? null)
            : ($object['id'] ?? null);

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }
}
