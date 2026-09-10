<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Models\Payment;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use LogicException;

final class CancellationPolicy
{
    public function __construct(private readonly CancellationPolicyResolver $resolver) {}

    public function refundPercentFor(
        Reservation $reservation,
        CarbonInterface $cancelledAt,
        bool $noShow = false,
    ): int {
        if ($noShow) {
            return $this->resolver->noShowRefundPercent();
        }

        $hoursUntilStart = max(
            0.0,
            (float) $cancelledAt->diffInMinutes($reservation->starts_at, false) / 60,
        );

        foreach ($this->resolver->tiers() as $tier) {
            if ($tier['min_hours_before'] <= $hoursUntilStart) {
                return $tier['refund_percent'];
            }
        }

        throw new LogicException('適用可能な予約キャンセルポリシーがありません。');
    }

    public function refundableAmount(Payment $payment, int $percent): int
    {
        $normalizedPercent = max(0, min(100, $percent));
        $policyAmount = (int) floor((int) $payment->amount * $normalizedPercent / 100);
        $remaining = max(0, (int) $payment->amount - (int) $payment->refunded_amount);

        return min($policyAmount, $remaining);
    }
}
