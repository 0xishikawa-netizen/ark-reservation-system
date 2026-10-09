<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipUsageTransaction;

class CustomerMembershipQuery
{
    public function __construct(private readonly MembershipLedgerService $ledger) {}

    /** @return array<string, mixed>|null */
    public function currentFor(int $customerId): ?array
    {
        $membership = Membership::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', MembershipStatus::Canceled->value)
            ->with('plan:id,name,price,usage_count_per_period,billing_interval')
            ->latest('id')
            ->first();

        if ($membership === null) {
            return null;
        }

        $summary = $this->ledger->summary($membership);

        return [
            'id' => (int) $membership->id,
            'plan' => [
                'id' => (int) $membership->plan->id,
                'name' => (string) $membership->plan->name,
                'price' => (int) $membership->plan->price,
                'usage_count_per_period' => (int) $membership->plan->usage_count_per_period,
                'billing_interval' => (string) $membership->plan->billing_interval,
            ],
            'status' => $membership->status->value,
            'status_label' => self::statusLabel($membership->status),
            'current_period_start' => $membership->current_period_start?->toDateString(),
            'current_period_end' => $membership->current_period_end?->toDateString(),
            'next_renewal' => $membership->current_period_end?->toDateString(),
            'cancel_at_period_end' => (bool) $membership->cancel_at_period_end,
            ...$summary,
            'grace_until' => $membership->status === MembershipStatus::Grace
                ? $membership->grace_until?->toDateString()
                : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function historyFor(int $customerId, int $limit = 100): array
    {
        return MembershipUsageTransaction::query()
            ->select([
                'id',
                'membership_id',
                'type',
                'delta',
                'period_start',
                'reservation_id',
                'created_at',
            ])
            ->whereHas('membership', fn ($query) => $query->where('customer_id', $customerId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (MembershipUsageTransaction $transaction): array => [
                'id' => (int) $transaction->id,
                'type' => self::usageTypeLabel($transaction->type),
                'delta' => (int) $transaction->delta,
                'period_start' => $transaction->period_start->toDateString(),
                'reservation_id' => $transaction->reservation_id === null
                    ? null
                    : (int) $transaction->reservation_id,
                'created_at' => $transaction->created_at->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function activePlans(): array
    {
        return MembershipPlan::query()
            ->select(['id', 'name', 'price', 'usage_count_per_period', 'billing_interval'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MembershipPlan $plan): array => [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'price' => (int) $plan->price,
                'usage_count_per_period' => (int) $plan->usage_count_per_period,
                'billing_interval' => (string) $plan->billing_interval,
            ])
            ->values()
            ->all();
    }

    public static function statusLabel(MembershipStatus $status): string
    {
        return match ($status) {
            MembershipStatus::Pending => __('messages.query_labels.membership_pending'),
            MembershipStatus::Active => __('messages.query_labels.membership_active'),
            MembershipStatus::Grace => __('messages.query_labels.membership_grace'),
            MembershipStatus::Canceling => __('messages.query_labels.membership_canceling'),
            MembershipStatus::Paused => __('messages.query_labels.membership_paused'),
            MembershipStatus::Canceled => __('messages.query_labels.membership_canceled'),
        };
    }

    public static function usageTypeLabel(MembershipUsageType $type): string
    {
        return match ($type) {
            MembershipUsageType::Grant => __('messages.query_labels.usage_grant'),
            MembershipUsageType::Reserve => __('messages.query_labels.usage_reserve'),
            MembershipUsageType::Release => __('messages.query_labels.usage_release'),
            MembershipUsageType::Consume => __('messages.query_labels.usage_consume'),
            MembershipUsageType::Adjust => __('messages.query_labels.usage_adjust'),
        };
    }
}
