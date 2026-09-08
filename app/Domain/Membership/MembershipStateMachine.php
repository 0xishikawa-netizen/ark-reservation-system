<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Support\StateMachine\StateMachine;

/**
 * Membership の業務状態遷移（`memberships.status`）。
 *
 * - 業務状態のみ。Stripe subscription status をそのままコピーしない。
 * - 前進が原則。`canceled` は終端（出口が無い＝古い webhook で巻き戻らない）。
 * - `active → pending` は定義しない。
 * - `grace`/`paused`/`canceling` からの `active` 復帰は「回復」であり、
 *   イベントの到着順ではなく Stripe の現在オブジェクトを retrieve して前進する。
 */
final class MembershipStateMachine extends StateMachine
{
    /**
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            'pending' => ['active', 'canceled'],
            'active' => ['grace', 'canceling', 'paused', 'canceled'],
            'grace' => ['active', 'canceling', 'paused', 'canceled'],
            'canceling' => ['active', 'canceled'],
            'paused' => ['active', 'canceled'],
            'canceled' => [],
        ];
    }
}
