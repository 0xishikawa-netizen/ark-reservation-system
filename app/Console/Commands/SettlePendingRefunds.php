<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\PaymentService;
use App\Enums\Payment\RefundStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\PaymentRefund;
use Illuminate\Console\Command;

/**
 * Stripe への返金要求が timeout / 曖昧応答で `pending` のまま止まった payment_refunds を
 * 冪等キー（refund_operation_id 由来）で再送し settle させる（Phase 9.5 hardening）。
 *
 * - `PaymentService::retryRefund` は保存済み operation ID で安全に再試行する唯一の入口。
 * - grace 分だけ古い行だけを対象にし、進行中の `refund()` 呼び出しと競合しない。
 * - Stripe HTTP は retryRefund 内で transaction 外に出る。
 * - decline / 曖昧 timeout はそのまま記録され、次回以降の対象から外れる（pending でなくなる）。
 */
final class SettlePendingRefunds extends Command
{
    private const GRACE_MINUTES = 5;

    private const BATCH = 100;

    /** @var string */
    protected $signature = 'payments:settle-pending-refunds {--dry-run}';

    /** @var string */
    protected $description = 'timeout 等で pending のまま止まった返金を冪等キーで再送し settle する';

    public function handle(PaymentService $payments): int
    {
        $cutoff = now()->subMinutes(self::GRACE_MINUTES);

        $stuck = PaymentRefund::query()
            ->where('status', RefundStatus::Pending->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('再送対象の pending 返金: %d 件（dry-run）', $stuck->count()));

            return self::SUCCESS;
        }

        $settled = 0;
        $unresolved = 0;

        foreach ($stuck as $refund) {
            try {
                $result = $payments->retryRefund($refund);

                if ($result->status === RefundStatus::Succeeded) {
                    $settled++;
                } else {
                    $unresolved++;
                }
            } catch (PaymentGatewayException) {
                // decline / 曖昧 timeout は retryRefund 側で記録済み。ここでは数えるだけ。
                $unresolved++;
            }
        }

        $this->info(sprintf('settle: %d 件 / 未解決: %d 件', $settled, $unresolved));

        return self::SUCCESS;
    }
}
