<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipUsageTransaction;

class AdminMembershipQuery
{
    public function __construct(private readonly MembershipLedgerService $ledger) {}

    /** @return array<string, mixed>|null */
    public function forCustomer(int $customerId): ?array
    {
        $membership = Membership::query()
            ->where('customer_id', $customerId)
            ->with('plan:id,name,price,usage_count_per_period,billing_interval,stripe_price_id,is_active')
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [MembershipStatus::Canceled->value])
            ->orderByDesc('id')
            ->first();

        if ($membership === null) {
            return null;
        }

        return [
            'id' => (int) $membership->id,
            'plan' => [
                'id' => (int) $membership->plan->id,
                'name' => (string) $membership->plan->name,
                'price' => (int) $membership->plan->price,
                'usage_count_per_period' => (int) $membership->plan->usage_count_per_period,
                'billing_interval' => (string) $membership->plan->billing_interval,
                'stripe_price_id' => (string) $membership->plan->stripe_price_id,
                'is_active' => (bool) $membership->plan->is_active,
            ],
            'status' => $membership->status->value,
            'status_label' => CustomerMembershipQuery::statusLabel($membership->status),
            'current_period_start' => $membership->current_period_start?->toDateString(),
            'current_period_end' => $membership->current_period_end?->toDateString(),
            'cancel_at_period_end' => (bool) $membership->cancel_at_period_end,
            ...$this->ledger->summary($membership),
            'grace_until' => $membership->grace_until?->toDateTimeString(),
            'started_at' => $membership->started_at?->toDateTimeString(),
            'canceled_at' => $membership->canceled_at?->toDateTimeString(),
            'last_synced_at' => $membership->last_synced_at?->toDateTimeString(),
            'needs_attention' => (bool) $membership->needs_attention,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function historyFor(int $customerId, int $limit = 200): array
    {
        return MembershipUsageTransaction::query()
            ->select([
                'id',
                'membership_id',
                'type',
                'delta',
                'period_start',
                'reservation_id',
                'reason',
                'created_at',
            ])
            ->whereHas('membership', fn ($query) => $query->where('customer_id', $customerId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (MembershipUsageTransaction $transaction): array => [
                'id' => (int) $transaction->id,
                'membership_id' => (int) $transaction->membership_id,
                'type' => $transaction->type->value,
                'type_label' => CustomerMembershipQuery::usageTypeLabel($transaction->type),
                'delta' => (int) $transaction->delta,
                'period_start' => $transaction->period_start->toDateString(),
                'reservation_id' => $transaction->reservation_id === null
                    ? null
                    : (int) $transaction->reservation_id,
                'reason' => $transaction->reason,
                'created_at' => $transaction->created_at->toDateTimeString(),
            ])
            ->values()
            ->all();
    }
}
