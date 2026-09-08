<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Enums\Membership\MembershipStatus;

/**
 * Stripe subscription status → ARK の業務状態（target）への写像。
 *
 * - Stripe status をそのまま業務状態にコピーしない。業務にとって意味のある状態だけへ写す。
 * - ここが返すのは「到達したい target」。実際の遷移は MembershipStateMachine::pathTo() で
 *   定義済みの前進エッジだけを辿る（target が現在より後退なら何もしない）。
 * - grace / paused の最終判断は grace policy（config/membership.php）を見る MembershipBillingService。
 *   ここでは past_due → grace、unpaid → paused の素朴な写像に留める。
 */
final class MembershipStatusMapper
{
    public function targetFor(string $stripeStatus, bool $cancelAtPeriodEnd): MembershipStatus
    {
        $base = match ($stripeStatus) {
            'active', 'trialing' => MembershipStatus::Active,
            'past_due' => MembershipStatus::Grace,
            'unpaid' => MembershipStatus::Paused,
            'paused' => MembershipStatus::Paused,
            'canceled', 'incomplete_expired' => MembershipStatus::Canceled,
            'incomplete' => MembershipStatus::Pending,
            default => MembershipStatus::Pending,
        };

        // cancel_at_period_end は「当期は使えるが次回更新で終了」。active/grace のときだけ canceling へ。
        if ($cancelAtPeriodEnd && in_array($base, [MembershipStatus::Active, MembershipStatus::Grace], true)) {
            return MembershipStatus::Canceling;
        }

        return $base;
    }
}
